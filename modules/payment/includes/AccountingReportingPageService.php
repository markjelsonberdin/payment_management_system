<?php
declare(strict_types=1);
require_once __DIR__.'/AccountingReportingService.php';
final class AccountingReportingPageService {
 private AccountingReportingService $service;
 public function __construct(private PDO $pdo){$this->service=new AccountingReportingService($pdo);}
 public function load(array $input,bool $dashboard=false):array{$f=$this->service->filters($input);$d=$this->service->report($input);$d['generated_at']=(new DateTimeImmutable('now',new DateTimeZone(AccountingReportingPeriod::TIMEZONE)))->format(DATE_ATOM);$d['trend']=$this->trend($f);$this->picklists($d);$this->efficiency($d,$f);if($dashboard){$this->operational($d,$f);$this->liveOnlineCollections($d,$f);}return$d;}
 private function liveOnlineCollections(array &$d,array $f):void
 {
  $r=$f['range'];
  $sql='SELECT COALESCE(SUM(pa.allocated_amount),0) ';
  $sql.='FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id ';
  $sql.='JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id ';
  $sql.='JOIN billing b ON b.billing_id=bi.billing_id ';
  $sql.='WHERE p.transaction_type=? AND p.payment_status=? ';
  $sql.='AND p.gateway_environment=? AND b.academic_year=? AND b.semester=? ';
  $sql.='AND p.verified_at>=? AND p.verified_at<?';
  $q=$this->pdo->prepare($sql);
  $params=['Online','Verified','live',$f['academic_year'],$f['semester'],
   AccountingReportingPeriod::sql($r['start_at']),
   AccountingReportingPeriod::sql($r['end_exclusive'])];
  $q->execute($params);
  $d['kpis']['live_online_collections']=(float)$q->fetchColumn();
  $d['section_status']['live_online_collections']='ok';
 }
 private function trend(array $f):array{$r=$f['range'];$unit=$r['period']==='today'?'hour':($r['period']==='year'?'month':'day');$current=$this->series($f,$r['start_at'],$r['end_exclusive'],$unit);$prior=$r['prior_start']?$this->series($f,$r['prior_start'],$r['prior_end_exclusive'],$unit):['values'=>[]];return['labels'=>$current['labels'],'current'=>$current['values'],'prior'=>$prior['values'],'comparison_label'=>$r['comparison_label']];}
 private function series(array $f,DateTimeImmutable $start,DateTimeImmutable $end,string $unit):array{$p=[$f['academic_year'],$f['semester']];$sql=' FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN billing b ON b.billing_id=bi.billing_id JOIN fees f ON f.fee_id=bi.fee_id WHERE '.PaymentReportingScope::officialCondition('p').' AND b.academic_year=? AND b.semester=?';if($f['payment_channel']){$sql.=' AND p.payment_channel=?';$p[]=$f['payment_channel'];}if($f['fee_category']){$sql.=' AND f.category_id=?';$p[]=$f['fee_category'];}$fmt=$unit==='hour'?'%Y-%m-%d %H:00:00':($unit==='month'?'%Y-%m-01 00:00:00':'%Y-%m-%d 00:00:00');$p[]=AccountingReportingPeriod::sql($start);$p[]=AccountingReportingPeriod::sql($end);$q=$this->pdo->prepare("SELECT DATE_FORMAT(p.verified_at,'$fmt') bucket,SUM(pa.allocated_amount) total".$sql.' AND p.verified_at>=? AND p.verified_at<? GROUP BY bucket');$q->execute($p);$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$map[$row['bucket']]=(float)$row['total'];$cursor=$unit==='hour'?$start->setTime((int)$start->format('H'),0):($unit==='month'?$start->modify('first day of this month')->setTime(0,0):$start->setTime(0,0));$last=$end->modify('-1 microsecond');$last=$unit==='hour'?$last->setTime((int)$last->format('H'),0):($unit==='month'?$last->modify('first day of this month')->setTime(0,0):$last->setTime(0,0));$labels=[];$values=[];while($cursor<=$last){$labels[]=$cursor->format($unit==='hour'?'g A':($unit==='month'?'M':'M j'));$values[]=$map[$cursor->format('Y-m-d H:i:s')]??0.0;$cursor=$cursor->modify('+1 '.$unit);}return['labels'=>$labels,'values'=>$values];}
 private function picklists(array &$d):void{try{$d['filter_options']=['terms'=>$this->pdo->query("SELECT academic_year,semester FROM billing GROUP BY academic_year,semester ORDER BY academic_year DESC,FIELD(semester,'1st','2nd','Summer')")->fetchAll(PDO::FETCH_ASSOC),'channels'=>$this->pdo->query('SELECT DISTINCT payment_channel FROM payments ORDER BY payment_channel')->fetchAll(PDO::FETCH_COLUMN),'categories'=>$this->pdo->query('SELECT category_id,category_name FROM fee_categories ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC)];foreach($d['filter_options']['categories']as$c)if((int)$c['category_id']===(int)($d['scope']['fee_category']??0))$d['scope']['fee_category_name']=$c['category_name'];$d['section_status']['filter_options']='ok';}catch(Throwable $e){$d['filter_options']=['terms'=>[],'channels'=>[],'categories'=>[]];$d['section_status']['filter_options']='error';$d['ok']=false;}}
 private function efficiency(array &$d,array $f):void{$p=[$f['academic_year'],$f['semester'],AccountingReportingPeriod::sql($f['range']['end_exclusive'])];$q=$this->pdo->prepare('SELECT COALESCE(SUM(pa.allocated_amount),0) FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN billing b ON b.billing_id=bi.billing_id WHERE '.PaymentReportingScope::officialCondition('p').' AND b.academic_year=? AND b.semester=? AND p.verified_at<?');$q->execute($p);$n=(float)$q->fetchColumn();$net=(float)$d['kpis']['net_assessed_amount'];$d['kpis']['term_to_date_collections']=$n;$d['kpis']['collection_efficiency']=$net>0?$n/$net*100:null;$d['kpis']['snapshot_note']='Net Assessed Amount and Outstanding Balance are AY/Semester snapshots; category filtering applies to collection sections only.';if($net>0&&$n/$net*100>100)$d['integrity_warnings'][]='Collection Efficiency exceeds 100%.';}
 private function operational(array &$d,array $f):void{
  $jobs=[
   'online_status'=>[
    'sql'=>"SELECT CASE WHEN p.gateway_environment='live' THEN CONCAT('LIVE ',p.payment_status) ELSE 'Unknown environment integrity warning' END label,COUNT(DISTINCT p.payment_id) count FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN billing b ON b.billing_id=bi.billing_id WHERE p.transaction_type='Online' AND b.academic_year=? AND b.semester=? GROUP BY label",
    'params'=>[$f['academic_year'],$f['semester']],
   ],
   'payment_concerns'=>[
    'sql'=>"SELECT verification_status label,COUNT(*) count FROM payment_concerns WHERE verification_status IN ('Pending','On Hold') GROUP BY verification_status UNION ALL SELECT CONCAT('OCR ',ocr_status),COUNT(*) FROM payment_concerns WHERE ocr_status IN ('Completed','Failed') GROUP BY ocr_status",
    'params'=>[],
   ],
   'bank_reconciliation'=>[
    'sql'=>"SELECT 'Processed imports' label,COUNT(*) count FROM bank_statements WHERE status='Processed' UNION ALL SELECT 'Matched rows',COUNT(*) FROM bank_statement_rows WHERE status='Matched' UNION ALL SELECT 'Unmatched exceptions',COUNT(*) FROM bank_statement_rows WHERE status='Unmatched' UNION ALL SELECT 'Failed imports',COUNT(*) FROM bank_statements WHERE status='Failed'",
    'params'=>[],
   ],
  ];
  $d['section_status']??=[];
  foreach($jobs as$key=>$job){try{$q=$this->pdo->prepare($job['sql']);$q->execute($job['params']);$d[$key]=$q->fetchAll(PDO::FETCH_ASSOC);$d['section_status'][$key]=$d[$key]?'ok':'no_data';}catch(Throwable $e){$d[$key]=[];$d['section_status'][$key]='error';$d['ok']=false;}}
  try{$q=$this->pdo->prepare("SELECT CASE WHEN status='Completed' AND failed_count>0 THEN 'Completed with failures' ELSE status END label,COUNT(*) count FROM fee_billing_campaigns WHERE academic_year=? AND semester=? GROUP BY label UNION ALL SELECT 'Expired campaign leases',COUNT(*) FROM fee_billing_campaigns WHERE academic_year=? AND semester=? AND status='Running' AND lease_expires_at<NOW()");$q->execute([$f['academic_year'],$f['semester'],$f['academic_year'],$f['semester']]);$d['billing_activity']=$q->fetchAll(PDO::FETCH_ASSOC);$d['section_status']['billing_activity']=$d['billing_activity']?'ok':'no_data';}catch(Throwable $e){$d['billing_activity']=[];$d['section_status']['billing_activity']='error';$d['ok']=false;}
 }
}
