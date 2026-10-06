<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/SchoolSalesCatalogMutationInfrastructure.php';

final class OcrStructuredAuditService
{
    public function __construct(private readonly PaymentAuditOutboxService $outbox) {}

    /** @param array<string,mixed> $result */
    public function record(int $concernId,int $actorId,string $actorName,string $actorRole,array $result):string
    {
        $correlation=(string)($result['request_id']??CatalogCorrelationId::generate());
        CatalogCorrelationId::assertValid($correlation);
        if($this->outbox->find($correlation)===null){
            $safe=['concern_id'=>$concernId,'success'=>(bool)($result['success']??false),'attempt_number'=>$result['attempt_number']??null,'cache_or_idempotent_reuse'=>(bool)($result['cache_or_idempotent_reuse']??false),'indicator_count'=>count($result['review_indicators']??[])];
            $this->outbox->insertPending($correlation,CatalogCanonicalJson::fingerprint($safe),[
                'action'=>'RECEIPT_OCR_EVIDENCE_RECORDED','module_key'=>'payment','entity_type'=>'payment_concern','entity_id'=>$concernId,
                'detail'=>'Google OCR evidence was recorded for Accounting review. No payment decision was made.',
                'after_state'=>$safe,'actor_user_id'=>$actorId,'actor_user_name'=>$actorName,'actor_role_key'=>$actorRole,
                'actor_ip_address'=>function_exists('smsClientIp')?smsClientIp():null,'actor_user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
            ],$safe);
        }
        return $this->outbox->deliver($correlation);
    }

    public function recordFailure(int $concernId,int $actorId,string $actorName,string $actorRole,string $category):string
    {
        $category=strtoupper(trim($category));
        if(!preg_match('/^[A-Z0-9_]{3,64}$/',$category))$category='OCR_PROCESSING_FAILED';
        $correlation=CatalogCorrelationId::generate();
        $safe=['concern_id'=>$concernId,'success'=>false,'failure_category'=>$category,'manual_review_available'=>true];
        $this->outbox->insertPending($correlation,CatalogCanonicalJson::fingerprint($safe),[
            'action'=>'RECEIPT_OCR_FALLBACK_RECORDED','module_key'=>'payment','entity_type'=>'payment_concern','entity_id'=>$concernId,
            'detail'=>'OCR was unavailable or failed. The receipt remains available for manual Accounting review.',
            'after_state'=>$safe,'actor_user_id'=>$actorId,'actor_user_name'=>$actorName,'actor_role_key'=>$actorRole,
            'actor_ip_address'=>function_exists('smsClientIp')?smsClientIp():null,'actor_user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
        ],$safe);
        return $this->outbox->deliver($correlation);
    }
}
