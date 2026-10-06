<?php
declare(strict_types=1);

final class ReceiptEvidenceService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $parsed @return array<string,mixed> */
    public function indicators(int $concernId, string $imageHash, array $parsed): array
    {
        $flags = $parsed['quality']['indicators'] ?? [];
        $reference = strtoupper((string)preg_replace('/[\s-]+/','',trim((string) ($parsed['reference_number'] ?? ''))));
        if ($reference !== '') {
            $q=$this->pdo->prepare("SELECT (SELECT COUNT(*) FROM payments WHERE REPLACE(REPLACE(UPPER(TRIM(reference_number)),'-',''),' ','')=? AND payment_status<>'Rejected') + (SELECT COUNT(*) FROM ocr_results WHERE concern_id<>? AND REPLACE(REPLACE(UPPER(TRIM(reference_number)),'-',''),' ','')=?)");
            $q->execute([$reference,$concernId,$reference]);
            if((int)$q->fetchColumn()>0)$flags[]='REFERENCE_DUPLICATE';
        }
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM ocr_scan_attempts WHERE concern_id<>? AND image_sha256=?');
        $q->execute([$concernId,$imageHash]);
        if((int)$q->fetchColumn()>0)$flags[]='EXACT_IMAGE_DUPLICATE';
        $context=$this->pdo->prepare('SELECT p.amount,p.payment_date FROM payment_concerns pc LEFT JOIN payments p ON p.payment_id=pc.payment_id WHERE pc.concern_id=?');
        $context->execute([$concernId]);$claimed=$context->fetch(PDO::FETCH_ASSOC)?:[];
        if($parsed['amount']!==null&&$claimed['amount']!==null&&abs((float)$parsed['amount']-(float)$claimed['amount'])>0.005)$flags[]='AMOUNT_MISMATCH';
        if($parsed['transaction_date']!==null&&!empty($claimed['payment_date'])&&$parsed['transaction_date']!==$claimed['payment_date'])$flags[]='TRANSACTION_DATE_MISMATCH';
        if($parsed['amount']!==null&&$parsed['transaction_date']!==null){$similar=$this->pdo->prepare('SELECT COUNT(*) FROM ocr_results WHERE concern_id<>? AND extracted_amount=? AND transaction_date=?');$similar->execute([$concernId,$parsed['amount'],$parsed['transaction_date']]);if((int)$similar->fetchColumn()>0)$flags[]='CONTEXT_SIMILARITY';}
        $date=$parsed['transaction_date']??null;
        if($date!==null){$ts=strtotime((string)$date);if($ts>time()+86400)$flags[]='FUTURE_TRANSACTION_DATE';if($ts<strtotime('-6 months'))$flags[]='OLD_TRANSACTION_DATE';}
        if(isset($parsed['amount'])&&$parsed['amount']!==null&&(float)$parsed['amount']<=0)$flags[]='NON_POSITIVE_AMOUNT';
        $flags=array_values(array_unique($flags));
        if($flags!==[]&&!in_array('MANUAL_REVIEW_REQUIRED',$flags,true))$flags[]='MANUAL_REVIEW_REQUIRED';
        return ['parser_version'=>ReceiptParserService::VERSION,'indicators'=>$flags,'review_required'=>count($flags)>0,'classification'=>'REVIEW_INDICATORS_ONLY'];
    }
}
