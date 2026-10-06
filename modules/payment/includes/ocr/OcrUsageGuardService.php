<?php
declare(strict_types=1);

final class OcrUsageLimitException extends RuntimeException {}
final class OcrOperationStateException extends RuntimeException {}

final class OcrUsageGuardService
{
    public const PROVIDER = 'google_cloud_vision';
    public const FEATURE = 'DOCUMENT_TEXT_DETECTION';
    public const FEATURE_VERSION = 'vision-document-v1';
    public const PARSER_VERSION = 'receipt_parser_v1';

    /** @return array<string,mixed>|null */
    public function attemptForRequest(string $requestId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM ocr_scan_attempts WHERE request_id=?');
        $stmt->execute([$requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function begin(string $operation, int $concernId, string $imageSha256, string $receiptIdentity, int $actorUserId, int $configuredLimit): array
    {
        $this->assertHash($imageSha256);
        if ($concernId < 1 || $actorUserId < 1 || $configuredLimit < 1 || $configuredLimit > 900) {
            throw new InvalidArgumentException('OCR_OPERATION_INVALID');
        }
        $operation = strtoupper(trim($operation));
        if (!in_array($operation, ['CONNECTION_TEST','RECEIPT_SCAN','CONTROLLED_RETRY'], true)) {
            throw new InvalidArgumentException('OCR_OPERATION_INVALID');
        }
        $idempotencyKey = hash('sha256', implode('|', [$operation,$concernId,$receiptIdentity,$imageSha256,self::PROVIDER,self::FEATURE,self::FEATURE_VERSION]));
        $billingMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-01');

        $this->pdo->beginTransaction();
        try {
            $existing = $this->findByIdempotency($idempotencyKey, true);
            if ($existing) { $this->pdo->commit(); return ['reused'=>true,'usage'=>$existing]; }

            $concern = $this->pdo->prepare('SELECT concern_id FROM payment_concerns WHERE concern_id=? FOR UPDATE');
            $concern->execute([$concernId]);
            if (!$concern->fetchColumn()) throw new DomainException('OCR_CONCERN_NOT_FOUND');

            $monthInsert = $this->pdo->prepare('INSERT IGNORE INTO ocr_usage_months (billing_month,configured_limit) VALUES (?,?)');
            $monthInsert->execute([$billingMonth,$configuredLimit]);
            $monthStmt = $this->pdo->prepare('SELECT * FROM ocr_usage_months WHERE billing_month=? FOR UPDATE');
            $monthStmt->execute([$billingMonth]);
            $month = $monthStmt->fetch(PDO::FETCH_ASSOC);
            if (!$month) throw new RuntimeException('OCR_MONTH_STATE_UNAVAILABLE');

            $requestId = $this->uuidV4();
            $cache = $this->findCache($imageSha256);
            if ($cache) {
                $usage = $this->insertUsage($requestId,$idempotencyKey,$billingMonth,$operation,$concernId,$imageSha256,0,'CACHE_HIT',$actorUserId);
                $attempt = $this->insertAttempt($requestId,$concernId,$imageSha256,'CACHE_HIT',$actorUserId,true,(int)$cache['canonical_attempt_id'],$cache);
                $this->pdo->prepare('UPDATE ocr_image_cache SET hit_count=hit_count+1,last_used_at=NOW() WHERE image_sha256=? AND provider=? AND feature=? AND feature_version=?')
                    ->execute([$imageSha256,self::PROVIDER,self::FEATURE,self::FEATURE_VERSION]);
                $this->pdo->commit();
                return ['reused'=>false,'cache_hit'=>true,'usage'=>$usage,'attempt'=>$attempt];
            }

            if ((int)$month['reserved_units'] + (int)$month['consumed_units'] >= (int)$month['configured_limit']) {
                $usage = $this->insertUsage($requestId,$idempotencyKey,$billingMonth,$operation,$concernId,$imageSha256,0,'REJECTED_LIMIT',$actorUserId,'MONTHLY_LIMIT_REACHED');
                $attempt = $this->insertAttempt($requestId,$concernId,$imageSha256,'LIMIT_REACHED',$actorUserId);
                $this->pdo->commit();
                return ['reused'=>false,'limit_reached'=>true,'usage'=>$usage,'attempt'=>$attempt];
            }

            $this->pdo->prepare('UPDATE ocr_usage_months SET reserved_units=reserved_units+1,row_version=row_version+1 WHERE billing_month=?')->execute([$billingMonth]);
            $usage = $this->insertUsage($requestId,$idempotencyKey,$billingMonth,$operation,$concernId,$imageSha256,1,'RESERVED',$actorUserId);
            $attempt = $this->insertAttempt($requestId,$concernId,$imageSha256,'RESERVED',$actorUserId);
            $this->pdo->commit();
            return ['reused'=>false,'cache_hit'=>false,'usage'=>$usage,'attempt'=>$attempt];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function markProviderCalled(string $requestId): void
    {
        $this->transition($requestId, function(array $usage): void {
            if ($usage['lifecycle_state'] !== 'RESERVED' || (int)$usage['provider_called'] !== 0) throw new OcrOperationStateException('OCR_STATE_INVALID');
            $this->pdo->prepare('UPDATE ocr_usage_months SET reserved_units=reserved_units-1,consumed_units=consumed_units+1,row_version=row_version+1 WHERE billing_month=? AND reserved_units>0')->execute([$usage['billing_month']]);
            $this->pdo->prepare("UPDATE ocr_usage_ledger SET lifecycle_state='PROVIDER_CALLED',provider_called=1,provider_called_at=NOW() WHERE request_id=?")->execute([$usage['request_id']]);
            $this->pdo->prepare("UPDATE ocr_scan_attempts SET extraction_status='PROVIDER_CALLED' WHERE request_id=?")->execute([$usage['request_id']]);
        });
    }

    /** @param array<string,mixed> $evidence */
    public function succeed(string $requestId, array $evidence): void
    {
        $this->transition($requestId, function(array $usage) use ($evidence): void {
            if ($usage['lifecycle_state'] !== 'PROVIDER_CALLED' || (int)$usage['provider_called'] !== 1) throw new OcrOperationStateException('OCR_STATE_INVALID');
            $this->pdo->prepare('UPDATE ocr_usage_months SET successful_units=successful_units+1,row_version=row_version+1 WHERE billing_month=?')->execute([$usage['billing_month']]);
            $this->pdo->prepare("UPDATE ocr_usage_ledger SET lifecycle_state='SUCCEEDED',completed_at=NOW() WHERE request_id=?")->execute([$usage['request_id']]);
            $stmt=$this->pdo->prepare("UPDATE ocr_scan_attempts SET extraction_status='SUCCEEDED',normalized_text=?,extracted_amount=?,bank_name=?,reference_number=?,transaction_date=?,transaction_time=?,confidence_score=?,quality_json=?,completed_at=NOW() WHERE request_id=?");
            $stmt->execute([$evidence['normalized_text']??null,$evidence['amount']??null,$evidence['bank_name']??null,$evidence['reference_number']??null,$evidence['transaction_date']??null,$evidence['transaction_time']??null,$evidence['confidence_score']??null,isset($evidence['quality'])?json_encode($evidence['quality'],JSON_THROW_ON_ERROR):null,$usage['request_id']]);
            $id=(int)$this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $attempt=$this->pdo->prepare('SELECT attempt_id FROM ocr_scan_attempts WHERE request_id=?');$attempt->execute([$usage['request_id']]);$id=(int)$attempt->fetchColumn();
            $this->pdo->prepare('INSERT INTO ocr_image_cache (image_sha256,provider,feature,feature_version,canonical_attempt_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE canonical_attempt_id=VALUES(canonical_attempt_id)')
                ->execute([$usage['image_sha256'],self::PROVIDER,self::FEATURE,self::FEATURE_VERSION,$id]);
        });
    }

    public function fail(string $requestId, string $category, bool $providerWasCalled): void
    {
        $category=strtoupper(trim($category));
        if (!preg_match('/^[A-Z0-9_]{3,64}$/',$category)) throw new InvalidArgumentException('OCR_FAILURE_CATEGORY_INVALID');
        $this->transition($requestId, function(array $usage) use ($category,$providerWasCalled): void {
            if ($providerWasCalled) {
                if ($usage['lifecycle_state']==='RESERVED') {
                    $this->pdo->prepare('UPDATE ocr_usage_months SET reserved_units=reserved_units-1,consumed_units=consumed_units+1,failed_units=failed_units+1,row_version=row_version+1 WHERE billing_month=? AND reserved_units>0')->execute([$usage['billing_month']]);
                } elseif ($usage['lifecycle_state']==='PROVIDER_CALLED') {
                    $this->pdo->prepare('UPDATE ocr_usage_months SET failed_units=failed_units+1,row_version=row_version+1 WHERE billing_month=?')->execute([$usage['billing_month']]);
                } else throw new OcrOperationStateException('OCR_STATE_INVALID');
                $sql="UPDATE ocr_usage_ledger SET lifecycle_state='FAILED',provider_called=1,provider_called_at=COALESCE(provider_called_at,NOW()),failure_category=?,completed_at=NOW() WHERE request_id=?";
            } else {
                if ($usage['lifecycle_state']!=='RESERVED') throw new OcrOperationStateException('OCR_STATE_INVALID');
                $this->pdo->prepare('UPDATE ocr_usage_months SET reserved_units=reserved_units-1,released_units=released_units+1,row_version=row_version+1 WHERE billing_month=? AND reserved_units>0')->execute([$usage['billing_month']]);
                $sql="UPDATE ocr_usage_ledger SET lifecycle_state='RELEASED',failure_category=?,released_at=NOW(),completed_at=NOW() WHERE request_id=?";
            }
            $this->pdo->prepare($sql)->execute([$category,$usage['request_id']]);
            $providerCategories=['AUTHENTICATION_FAILED','API_DISABLED','TIMEOUT','NETWORK_OR_PROVIDER_ERROR','PROVIDER_ERROR','CONFIGURATION_ERROR'];
            $indicators=['MANUAL_REVIEW_REQUIRED'];
            if(in_array($category,$providerCategories,true))$indicators[]='OCR_PROVIDER_UNAVAILABLE';
            $quality=json_encode(['parser_version'=>self::PARSER_VERSION,'indicators'=>$indicators,'review_required'=>true,'failure_category'=>$category],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->pdo->prepare("UPDATE ocr_scan_attempts SET extraction_status='FAILED',failure_category=?,quality_json=?,completed_at=NOW() WHERE request_id=?")->execute([$category,$quality,$usage['request_id']]);
        });
    }

    private function transition(string $requestId, callable $fn): void {
        $this->pdo->beginTransaction();
        try { $s=$this->pdo->prepare('SELECT * FROM ocr_usage_ledger WHERE request_id=? FOR UPDATE');$s->execute([$requestId]);$u=$s->fetch(PDO::FETCH_ASSOC);if(!$u)throw new DomainException('OCR_REQUEST_NOT_FOUND');$fn($u);$this->pdo->commit(); }
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function findByIdempotency(string $key,bool $lock=false):?array{$s=$this->pdo->prepare('SELECT * FROM ocr_usage_ledger WHERE idempotency_key=?'.($lock?' FOR UPDATE':''));$s->execute([$key]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    private function findCache(string $hash):?array{$s=$this->pdo->prepare('SELECT c.canonical_attempt_id,a.* FROM ocr_image_cache c JOIN ocr_scan_attempts a ON a.attempt_id=c.canonical_attempt_id WHERE c.image_sha256=? AND c.provider=? AND c.feature=? AND c.feature_version=?');$s->execute([$hash,self::PROVIDER,self::FEATURE,self::FEATURE_VERSION]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    private function insertUsage(string $request,string $key,string $month,string $operation,int $concern,string $hash,int $units,string $state,int $actor,?string $failure=null):array{$s=$this->pdo->prepare('INSERT INTO ocr_usage_ledger (request_id,idempotency_key,billing_month,operation,concern_id,image_sha256,provider,feature,feature_version,units,lifecycle_state,failure_category,actor_user_id,reserved_at,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?=\'RESERVED\',NOW(),NULL),IF(? IN (\'CACHE_HIT\',\'REJECTED_LIMIT\'),NOW(),NULL))');$s->execute([$request,$key,$month,$operation,$concern,$hash,self::PROVIDER,self::FEATURE,self::FEATURE_VERSION,$units,$state,$failure,$actor,$state,$state]);return ['request_id'=>$request,'idempotency_key'=>$key,'lifecycle_state'=>$state];}
    private function insertAttempt(string $request,int $concern,string $hash,string $status,int $actor,bool $hit=false,?int $source=null,?array $cache=null):array{$n=$this->pdo->prepare('SELECT COALESCE(MAX(attempt_number),0)+1 FROM ocr_scan_attempts WHERE concern_id=?');$n->execute([$concern]);$num=(int)$n->fetchColumn();$quality=$cache['quality_json']??null;if($hit){$decoded=json_decode((string)$quality,true);if(!is_array($decoded))$decoded=[];$decoded['indicators']=array_values(array_unique(array_merge($decoded['indicators']??[],['EXACT_IMAGE_DUPLICATE','MANUAL_REVIEW_REQUIRED'])));$decoded['review_required']=true;$quality=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}$s=$this->pdo->prepare('INSERT INTO ocr_scan_attempts (request_id,concern_id,attempt_number,image_sha256,provider,feature,feature_version,parser_version,extraction_status,normalized_text,extracted_amount,bank_name,reference_number,transaction_date,transaction_time,confidence_score,quality_json,cache_hit,cache_source_attempt_id,scanned_by,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL))');$s->execute([$request,$concern,$num,$hash,self::PROVIDER,self::FEATURE,self::FEATURE_VERSION,self::PARSER_VERSION,$status,$cache['normalized_text']??null,$cache['extracted_amount']??null,$cache['bank_name']??null,$cache['reference_number']??null,$cache['transaction_date']??null,$cache['transaction_time']??null,$cache['confidence_score']??null,$quality,$hit?1:0,$source,$actor,$hit?1:0]);return ['request_id'=>$request,'attempt_number'=>$num,'status'=>$status];}
    private function assertHash(string $h):void{if(!preg_match('/^[0-9a-f]{64}$/',$h))throw new InvalidArgumentException('OCR_IMAGE_HASH_INVALID');}
    private function uuidV4():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
}
