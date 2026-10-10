<?php
declare(strict_types=1);

require_once __DIR__ . '/OcrTextProviderInterface.php';
require_once __DIR__ . '/GoogleVisionOcrService.php';
require_once __DIR__ . '/OcrUsageGuardService.php';
require_once __DIR__ . '/PrivateReceiptStorageService.php';
require_once __DIR__ . '/ReceiptParserService.php';
require_once __DIR__ . '/ReceiptEvidenceService.php';

final class ReceiptOcrProcessor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OcrTextProviderInterface $provider,
        private readonly PrivateReceiptStorageService $storage,
        private readonly OcrUsageGuardService $guard,
        private readonly ReceiptParserService $parser,
        private readonly ReceiptEvidenceService $evidence
    ) {}

    /** @return array<string,mixed> */
    public function scan(int $concernId, int $actorId, bool $controlledRetry = false): array
    {
        $stmt=$this->pdo->prepare('SELECT receipt_path,verification_status FROM payment_concerns WHERE concern_id=?');
        $stmt->execute([$concernId]);
        $concern=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$concern||empty($concern['receipt_path']))throw new DomainException('OCR_RECEIPT_NOT_FOUND');
        if(!in_array($concern['verification_status'],['Pending','On Hold'],true))throw new DomainException('OCR_CONCERN_FINALIZED');

        $configuration=$this->configuration();
        if(!$configuration['enabled'])throw new DomainException('OCR_DISABLED');
        if(method_exists($this->provider,'configurationStatus')){
            $providerConfiguration=$this->provider->configurationStatus();
            if(($providerConfiguration['status']??'NOT_CONFIGURED')!=='CONFIGURED')throw new DomainException('OCR_PROVIDER_NOT_CONFIGURED');
        }
        if (strtoupper($configuration['mode']) !== GoogleVisionOcrService::FEATURE
            || (method_exists($this->provider, 'projectIdentityMatches')
                && !$this->provider->projectIdentityMatches($configuration['project_id']))) {
            throw new DomainException('OCR_PROVIDER_NOT_CONFIGURED');
        }
        $resolved=$this->storage->resolve((string)$concern['receipt_path']);
        $bytes=file_get_contents($resolved['path']);
        if($bytes===false||$bytes==='')throw new RuntimeException('OCR_RECEIPT_UNREADABLE');
        $hash=hash('sha256',$bytes);
        $identity=implode(':',[$concern['receipt_path'],strlen($bytes),filemtime($resolved['path'])?:0]);
        $begin=$this->guard->begin($controlledRetry?'CONTROLLED_RETRY':'RECEIPT_SCAN',$concernId,$hash,$identity,$actorId,$configuration['monthly_limit']);
        $requestId=(string)($begin['usage']['request_id']??'');

        if(!empty($begin['limit_reached']))return ['success'=>false,'error'=>'OCR_MONTHLY_LIMIT_REACHED','message'=>'Monthly OCR limit reached. Manual review remains available.'];
        if(!empty($begin['reused'])){
            $attempt=$this->guard->attemptForRequest($requestId);
            $state=(string)($begin['usage']['lifecycle_state']??'');
            if(in_array($state,['SUCCEEDED','CACHE_HIT'],true))return $this->responseFromAttempt($attempt,true);
            if(in_array($state,['RESERVED','PROVIDER_CALLED'],true))return ['success'=>false,'error'=>'OCR_REQUEST_IN_PROGRESS','message'=>'This OCR request is already being processed.'];
            return ['success'=>false,'error'=>'OCR_RETRY_REQUIRED','message'=>'The previous OCR attempt did not complete. Use the explicit retry action.'];
        }
        if(!empty($begin['cache_hit'])){
            $attempt=$this->guard->attemptForRequest($requestId);
            $this->writeCompatibility($concernId,$actorId,$attempt?:[]);
            return $this->responseFromAttempt($attempt,true);
        }

        $providerFingerprint = method_exists($this->provider, 'configurationFingerprint')
            ? $this->provider->configurationFingerprint($configuration['project_id'], $configuration['mode'], $configuration['monthly_limit'])
            : null;

        $providerCalled=false;
        try{
            $this->guard->markProviderCalled($requestId);
            $providerCalled=true;
            $providerResult=$this->provider->extractDocumentText($bytes);
            $parsed=$this->parser->parse($providerResult['raw_text']??null);
            $parsed['quality']=$this->evidence->indicators($concernId,$hash,$parsed);
            $this->guard->succeed($requestId,$parsed);
            $attempt=$this->guard->attemptForRequest($requestId);
            $this->writeCompatibility($concernId,$actorId,$attempt?:[]);
            return $this->responseFromAttempt($attempt,false,is_string($providerFingerprint)?$providerFingerprint:null);
        }catch(Throwable $e){
            try{$category=$e instanceof GoogleVisionOcrException?$e->category:'OCR_PROCESSING_FAILED';$this->guard->fail($requestId,$category,$providerCalled);}catch(Throwable){}
            $this->pdo->prepare("UPDATE payment_concerns SET ocr_status='Failed' WHERE concern_id=?")->execute([$concernId]);
            throw $e;
        }
    }

    /** @return array{enabled:bool,monthly_limit:int,project_id:string,mode:string} */
    private function configuration():array
    {
        $stmt=$this->pdo->query("SELECT setting_key,setting_value FROM payment_gateway_settings WHERE setting_key IN ('ocr_enabled','ocr_monthly_limit','ocr_project_id','ocr_mode')");
        $settings=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['enabled'=>($settings['ocr_enabled']??'0')==='1','monthly_limit'=>max(1,min(900,(int)($settings['ocr_monthly_limit']??900))),
            'project_id'=>(string)($settings['ocr_project_id']??''),'mode'=>(string)($settings['ocr_mode']??'DOCUMENT_TEXT_DETECTION')];
    }

    /** @param array<string,mixed> $attempt */
    private function writeCompatibility(int $concernId,int $actorId,array $attempt):void
    {
        $quality=json_decode((string)($attempt['quality_json']??''),true);
        if(!is_array($quality))$quality=[];
        $status=empty($attempt['normalized_text'])?'NO_TEXT_DETECTED':(!empty($quality['review_required'])?'REVIEW_REQUIRED':'READY_FOR_REVIEW');
        $notes=json_encode(['parser_version'=>ReceiptParserService::VERSION,'indicators'=>$quality['indicators']??[],'request_id'=>$attempt['request_id']??null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $raw=json_encode(['normalized_text'=>$attempt['normalized_text']??null,'quality'=>$quality],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $sql='INSERT INTO ocr_results (concern_id,scan_attempt,extracted_amount,bank_name,confidence_score,reference_number,transaction_date,transaction_time,raw_json,extraction_status,extraction_notes,scanned_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE scan_attempt=VALUES(scan_attempt),extracted_amount=VALUES(extracted_amount),bank_name=VALUES(bank_name),confidence_score=VALUES(confidence_score),reference_number=VALUES(reference_number),transaction_date=VALUES(transaction_date),transaction_time=VALUES(transaction_time),raw_json=VALUES(raw_json),extraction_status=VALUES(extraction_status),extraction_notes=VALUES(extraction_notes),scanned_by=VALUES(scanned_by),updated_at=CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([$concernId,(int)($attempt['attempt_number']??1),$attempt['extracted_amount']??null,$attempt['bank_name']??null,$attempt['confidence_score']??null,$attempt['reference_number']??null,$attempt['transaction_date']??null,$attempt['transaction_time']??null,$raw,$status,$notes,$actorId]);
        $this->pdo->prepare("UPDATE payment_concerns SET ocr_status='Completed' WHERE concern_id=?")->execute([$concernId]);
    }

    /** @param array<string,mixed>|null $attempt @return array<string,mixed> */
    private function responseFromAttempt(?array $attempt,bool $reused,?string $providerFingerprint=null):array
    {
        if(!$attempt)return ['success'=>false,'error'=>'OCR_ATTEMPT_UNAVAILABLE'];
        $quality=json_decode((string)($attempt['quality_json']??''),true);
        return ['success'=>true,'request_id'=>$attempt['request_id'],'attempt_number'=>(int)$attempt['attempt_number'],'cache_or_idempotent_reuse'=>$reused,'provider_processing_fingerprint'=>$providerFingerprint,'evidence'=>['amount'=>$attempt['extracted_amount'],'reference_number'=>$attempt['reference_number'],'transaction_date'=>$attempt['transaction_date'],'transaction_time'=>$attempt['transaction_time'],'channel'=>$attempt['bank_name'],'confidence_score'=>$attempt['confidence_score']],'review_indicators'=>is_array($quality)?($quality['indicators']??[]):[],'message'=>'OCR evidence is ready for Accounting review.'];
    }
}
