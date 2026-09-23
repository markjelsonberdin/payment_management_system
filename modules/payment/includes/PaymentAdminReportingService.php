<?php
declare(strict_types=1);

require_once __DIR__ . '/AccountingReportingPeriod.php';
require_once __DIR__ . '/PaymentReportingScope.php';

/** Read-only, system-wide operational metrics for the Payment Admin dashboard. */
final class PaymentAdminReportingService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function load(array $input = []): array
    {
        $period = AccountingReportingPeriod::fromRequest($input);
        $start = AccountingReportingPeriod::sql($period['start_at']);
        $end = AccountingReportingPeriod::sql($period['end_exclusive']);
        $data = ['ok'=>true,'generated_at'=>date(DATE_ATOM),'scope'=>[
            'period'=>$period['period'],'timezone'=>$period['timezone'],'start_at'=>$start,
            'end_exclusive'=>$end,'comparison_label'=>$period['comparison_label']], 'section_status'=>[]];

        $this->section($data,'kpis',function() use($start,$end): array {
            $official=PaymentReportingScope::officialCondition('p');
            $q=$this->pdo->prepare("SELECT COALESCE(SUM(a.applied_amount),0) total_amount,COUNT(DISTINCT p.payment_id) payment_count,
                COALESCE(SUM(CASE WHEN p.transaction_type='Walk-in' AND p.payment_channel='Cash' THEN a.applied_amount ELSE 0 END),0) cash_amount,
                COUNT(DISTINCT CASE WHEN p.transaction_type='Walk-in' AND p.payment_channel='Cash' THEN p.payment_id END) cash_count,
                COALESCE(SUM(CASE WHEN p.transaction_type='Online' AND p.gateway_environment='live' THEN a.applied_amount ELSE 0 END),0) online_amount,
                COUNT(DISTINCT CASE WHEN p.transaction_type='Online' AND p.gateway_environment='live' THEN p.payment_id END) online_count,
                COALESCE(SUM(CASE WHEN p.transaction_type='Payment Concern' THEN a.applied_amount ELSE 0 END),0) concern_amount,
                COUNT(DISTINCT CASE WHEN p.transaction_type='Payment Concern' THEN p.payment_id END) concern_count
                FROM payments p JOIN (SELECT payment_id,SUM(allocated_amount) applied_amount FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.payment_id
                WHERE {$official} AND p.verified_at>=:start_at AND p.verified_at<:end_exclusive");
            $q->execute([':start_at'=>$start,':end_exclusive'=>$end]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];
            return ['total_successful_amount'=>(float)($r['total_amount']??0),'total_successful_count'=>(int)($r['payment_count']??0),
                'cash_amount'=>(float)($r['cash_amount']??0),'cash_count'=>(int)($r['cash_count']??0),
                'live_online_amount'=>(float)($r['online_amount']??0),'live_online_count'=>(int)($r['online_count']??0),
                'payment_concern_amount'=>(float)($r['concern_amount']??0),'payment_concern_count'=>(int)($r['concern_count']??0)];
        });
        $this->section($data,'pending_queue',function():array{return ['count'=>(int)$this->pdo->query("SELECT COUNT(*) FROM payments WHERE transaction_type='Online' AND payment_status='Pending' AND gateway_environment='live'")->fetchColumn()];});

        $this->section($data,'trend',function()use($period,$start,$end):array{
            $mode=$period['period'];$now=$period['end_exclusive'];$count=$this->bucketCount($now,$mode);$labels=[];
            for($i=0;$i<$count;$i++)$labels[]=match($mode){'today'=>sprintf('%02d:00',$i),'week'=>$period['start_at']->modify('+'.$i.' days')->format('D'),'month'=>(string)($i+1),default=>date('M',mktime(0,0,0,$i+1,1))};
            $bucket=match($mode){'today'=>'HOUR(p.verified_at)','week'=>'WEEKDAY(p.verified_at)','month'=>'DAY(p.verified_at)',default=>'MONTH(p.verified_at)'};$current=$this->trendBuckets($bucket,$start,$end,$mode);$prior=[];
            if($period['prior_start'] instanceof DateTimeInterface&&$period['prior_end_exclusive'] instanceof DateTimeInterface)$prior=$this->trendBuckets($bucket,AccountingReportingPeriod::sql($period['prior_start']),AccountingReportingPeriod::sql($period['prior_end_exclusive']),$mode);
            return ['labels'=>$labels,'current'=>$current,'prior'=>$prior,'comparison_label'=>$period['comparison_label']];
        });
        $this->section($data,'channel_breakdown',function()use($start,$end):array{
            $official=PaymentReportingScope::officialCondition('p');$q=$this->pdo->prepare("SELECT p.payment_channel label,COALESCE(SUM(a.applied_amount),0) amount,COUNT(*) payment_count FROM payments p JOIN (SELECT payment_id,SUM(allocated_amount) applied_amount FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.payment_id WHERE {$official} AND p.verified_at>=:start_at AND p.verified_at<:end_exclusive GROUP BY p.payment_channel ORDER BY amount DESC");$q->execute([':start_at'=>$start,':end_exclusive'=>$end]);return $q->fetchAll(PDO::FETCH_ASSOC);
        });
        $this->section($data,'online_status',function()use($start,$end):array{$q=$this->pdo->prepare("SELECT payment_status status,CASE WHEN gateway_environment='live' THEN 'live' WHEN gateway_environment='test' THEN 'test' ELSE 'unknown' END environment,COUNT(*) count FROM payments WHERE transaction_type='Online' AND created_at>=:start_at AND created_at<:end_exclusive GROUP BY payment_status,environment ORDER BY environment,payment_status");$q->execute([':start_at'=>$start,':end_exclusive'=>$end]);return $q->fetchAll(PDO::FETCH_ASSOC);});
        $this->section($data,'failed_expired',function()use($start,$end):array{$q=$this->pdo->prepare("SELECT payment_status status,COUNT(*) count FROM payments WHERE transaction_type='Online' AND gateway_environment='live' AND payment_status IN ('Failed','Expired','Cancelled','Rejected') AND created_at>=:start_at AND created_at<:end_exclusive GROUP BY payment_status");$q->execute([':start_at'=>$start,':end_exclusive'=>$end]);return $q->fetchAll(PDO::FETCH_ASSOC);});
        $this->section($data,'recent_activity',function()use($start,$end):array{$q=$this->pdo->prepare("SELECT p.payment_id,p.verified_at,p.created_at,p.reference_number,p.receipt_number,s.full_name,s.student_number,p.transaction_type,p.payment_channel,p.gateway_environment,p.payment_status,p.amount attempted_amount,COALESCE(a.applied_amount,0) applied_amount FROM payments p LEFT JOIN students s ON s.student_id=p.student_id LEFT JOIN (SELECT payment_id,SUM(allocated_amount) applied_amount FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.payment_id WHERE (p.payment_status='Verified' AND p.verified_at>=:start_verified AND p.verified_at<:end_verified) OR (p.payment_status<>'Verified' AND p.created_at>=:start_created AND p.created_at<:end_created) ORDER BY COALESCE(p.verified_at,p.created_at) DESC,p.payment_id DESC LIMIT 12");$q->execute([':start_verified'=>$start,':end_verified'=>$end,':start_created'=>$start,':end_created'=>$end]);return $q->fetchAll(PDO::FETCH_ASSOC);});

        $this->section($data,'configuration',function():array{$q=$this->pdo->query("SELECT setting_key,setting_value FROM payment_gateway_settings WHERE setting_key IN ('gateway_mode','live_channel_gcash','live_channel_maya','live_channel_card','live_channel_qrph','test_channel_gcash','test_channel_maya','test_channel_card','test_channel_qrph')");$s=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$s[$r['setting_key']]=$r['setting_value'];$mode=($s['gateway_mode']??'test')==='live'?'live':'test';$channels=[];foreach(['gcash'=>'GCash','maya'=>'Maya','card'=>'Card','qrph'=>'QRPh']as$k=>$label){$configured=($s[$mode.'_channel_'.$k]??'0')==='1';$allowed=$mode!=='live'||$k==='qrph';$channels[]=['code'=>$k,'name'=>$label,'configured'=>$configured,'policy_allowed'=>$allowed,'available_by_config'=>$configured&&$allowed];}return ['gateway_mode'=>$mode,'channels'=>$channels];});
        $this->section($data,'school_sales',function():array{return ['active_categories'=>(int)$this->pdo->query("SELECT COUNT(*) FROM school_sale_categories WHERE status='Active'")->fetchColumn(),'active_items'=>(int)$this->pdo->query("SELECT COUNT(*) FROM school_sale_items WHERE status='Active'")->fetchColumn()];});
        $this->section($data,'needs_attention',function():array{$checks=[['Verified payments missing verified time',"SELECT COUNT(*) FROM payments WHERE payment_status='Verified' AND verified_at IS NULL"],['Verified payments without allocations',"SELECT COUNT(*) FROM payments p WHERE payment_status='Verified' AND NOT EXISTS(SELECT 1 FROM payment_allocations pa WHERE pa.payment_id=p.payment_id)"],['Verified online payments with test or unknown environment',"SELECT COUNT(*) FROM payments WHERE transaction_type='Online' AND payment_status='Verified' AND (gateway_environment IS NULL OR gateway_environment<>'live')"],['Verified payment amount differs from allocations',"SELECT COUNT(*) FROM payments p WHERE payment_status='Verified' AND ABS(amount-COALESCE((SELECT SUM(allocated_amount) FROM payment_allocations pa WHERE pa.payment_id=p.payment_id),0))>=0.01"],['Pending live online payments past expiry',"SELECT COUNT(*) FROM payments WHERE transaction_type='Online' AND payment_status='Pending' AND gateway_environment='live' AND expires_at IS NOT NULL AND expires_at<NOW()"]];$items=[];foreach($checks as[$label,$sql]){$n=(int)$this->pdo->query($sql)->fetchColumn();if($n>0)$items[]=['label'=>$label,'count'=>$n,'severity'=>'warning'];}return $items;});
        return $data;
    }

    private function bucketCount(DateTimeInterface $endExclusive,string $period):int{$timeRemainder=$endExclusive->format('i:s.u')!=='00:00.000000';$day=(int)$endExclusive->format('j');$dayHasStarted=(int)$endExclusive->format('G')>0||$timeRemainder;return max(0,match($period){'today'=>(int)$endExclusive->format('G')+($timeRemainder?1:0),'week'=>(int)$endExclusive->format('N')-($dayHasStarted?0:1),'month'=>$day-($dayHasStarted?0:1),default=>(int)$endExclusive->format('n')-(($day===1&&!$dayHasStarted)?1:0)});}
    private function trendBuckets(string $bucketExpr,string $start,string $end,string $period):array{$official=PaymentReportingScope::officialCondition('p');$q=$this->pdo->prepare("SELECT {$bucketExpr} bucket,COALESCE(SUM(a.applied_amount),0) amount FROM payments p JOIN (SELECT payment_id,SUM(allocated_amount) applied_amount FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.payment_id WHERE {$official} AND p.verified_at>=:start_at AND p.verified_at<:end_exclusive GROUP BY bucket");$q->execute([':start_at'=>$start,':end_exclusive'=>$end]);$d=new DateTimeImmutable($end,new DateTimeZone(AccountingReportingPeriod::TIMEZONE));$v=array_fill(0,$this->bucketCount($d,$period),0.0);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r){$i=match($period){'today'=>(int)$r['bucket'],'week'=>(int)$r['bucket'],'month'=>(int)$r['bucket']-1,default=>(int)$r['bucket']-1};if(isset($v[$i]))$v[$i]=(float)$r['amount'];}return $v;}
    private function section(array &$data,string $name,callable $loader):void{try{$data[$name]=$loader();$data['section_status'][$name]=$data[$name]===[]?'no_data':'ok';}catch(Throwable $e){error_log('Payment Admin dashboard ['.$name.']: '.$e->getMessage());$data[$name]=null;$data['section_status'][$name]='error';$data['ok']=false;}}
}
