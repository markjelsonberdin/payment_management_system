<?php
declare(strict_types=1);
require_once __DIR__.'/AccountingReportingService.php';
final class AccountingReportingPageService {
 private AccountingReportingService $service;
 public function __construct(private PDO $pdo){$this->service=new AccountingReportingService($pdo);}
 public function load(array $input):array{return $this->loadForWeb($input);}
 public function loadForWeb(array $input):array
 {
  $f=$this->service->filters($input);[$page,$pageSize]=$this->pagination($input);
  return $this->finishForWeb($this->service->reportPageNormalized($f,$page,$pageSize),$f);
 }
 public function loadForExport(array $input):array
 {
  $f=$this->service->filters($input);
  return $this->finishForExport($this->service->reportNormalized($f),$f);
 }
 private function pagination(array $input):array
 {
  $page=filter_var($input['page']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
  $pageSize=filter_var($input['page_size']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])?:25;
  return[$page,$pageSize];
 }
 private function finishForWeb(array $d,array $f):array
 {
  $d['generated_at']=(new DateTimeImmutable('now',new DateTimeZone(AccountingReportingPeriod::TIMEZONE)))->format(DATE_ATOM);$d['trend']=$this->trend($f);$d['section_status']['trend']=$d['trend']['labels']?'ok':'no_data';$this->picklists($d);$this->efficiency($d,$f);$this->dashboardBreakdowns($d,$f);return$d;
 }
 private function finishForExport(array $d,array $f):array
 {
  $d['generated_at']=(new DateTimeImmutable('now',new DateTimeZone(AccountingReportingPeriod::TIMEZONE)))->format(DATE_ATOM);
  $this->efficiency($d,$f);
  if($f['fee_category']){
   foreach($this->categoryRows($f['fee_category']) as $category)$d['scope']['fee_category_name']=$category['category_name'];
  }
  return$d;
 }
 /** Shared category metadata query; export failures propagate to its generic error handler. */
 private function categoryRows(?int $categoryId=null):array
 {
  $sql='SELECT category_id,category_name FROM fee_categories';
  if($categoryId===null)return$this->pdo->query($sql.' ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC);
  $q=$this->pdo->prepare($sql.' WHERE category_id=?');$q->execute([$categoryId]);return$q->fetchAll(PDO::FETCH_ASSOC);
 }
 private function filteredAllocationSubquery(array $f,DateTimeImmutable $start,DateTimeImmutable $end,array $extraPredicates=[],array $extraParams=[]):array
 {
  $where=[PaymentReportingScope::officialCondition('p'),'b.academic_year=?','b.semester=?','p.verified_at>=?','p.verified_at<?'];
  $params=[$f['academic_year'],$f['semester'],AccountingReportingPeriod::sql($start),AccountingReportingPeriod::sql($end)];
  if($f['payment_channel']){$where[]='p.payment_channel=?';$params[]=$f['payment_channel'];}
  if($f['payment_method']){$where[]='p.payment_method=?';$params[]=$f['payment_method'];}
  if($f['search']!==''){$where[]='(p.reference_number LIKE ? OR p.receipt_number LIKE ? OR EXISTS (SELECT 1 FROM students sr WHERE sr.student_id=p.student_id AND (sr.student_number LIKE ? OR sr.full_name LIKE ?)))';$like='%'.$f['search'].'%';array_push($params,$like,$like,$like,$like);}
  if($f['fee_category']){$where[]='f.category_id=?';$params[]=$f['fee_category'];}
  foreach($extraPredicates as $predicate)$where[]=$predicate;
  array_push($params,...$extraParams);
  $sql='SELECT pa.payment_id,b.academic_year,b.semester,SUM(pa.allocated_amount) allocated_amount FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN fees f ON f.fee_id=bi.fee_id JOIN billing b ON b.billing_id=bi.billing_id WHERE '.implode(' AND ',$where).' GROUP BY pa.payment_id,b.academic_year,b.semester';
  return[$sql,$params];
 }
 private function dashboardBreakdowns(array &$d,array $f):void
 {
  $r=$f['range'];
  try{
   [$allocationSql,$params]=$this->filteredAllocationSubquery($f,$r['start_at'],$r['end_exclusive']);
   $sql="SELECT CASE WHEN p.transaction_type='Online' THEN 'Online' WHEN p.payment_method='Bank Transfer' OR p.payment_channel='Bank' THEN 'Bank Transfer' WHEN p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' THEN 'Cash' ELSE 'Other' END label,COALESCE(SUM(a.allocated_amount),0) total FROM ({$allocationSql}) a JOIN payments p ON p.payment_id=a.payment_id GROUP BY label";
   $q=$this->pdo->prepare($sql);$q->execute($params);$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$map[$row['label']]=(float)$row['total'];
   // The filtered one-row-per-payment allocation scope is shared by all dashboard collection breakdowns.
   $d['payment_methods']=[];foreach(['Cash','Online','Bank Transfer'] as $label)$d['payment_methods'][]=['label'=>$label,'total'=>$map[$label]??0.0];if(($map['Other']??0)>0)$d['payment_methods'][]=['label'=>'Other recorded method','total'=>$map['Other']];
   $d['section_status']['payment_methods']='ok';
  }catch(Throwable $e){$d['payment_methods']=[];$d['section_status']['payment_methods']='error';}
 }
 private function trend(array $f):array{$r=$f['range'];$unit=$r['period']==='today'?'hour':($r['period']==='year'?'month':'day');$current=$this->series($f,$r['start_at'],$r['end_exclusive'],$unit);$prior=$r['prior_start']?$this->series($f,$r['prior_start'],$r['prior_end_exclusive'],$unit):['values'=>[]];return['labels'=>$current['labels'],'current'=>$current['values'],'prior'=>$prior['values'],'comparison_label'=>$r['comparison_label']];}
 private function series(array $f,DateTimeImmutable $start,DateTimeImmutable $end,string $unit):array{$p=[$f['academic_year'],$f['semester']];$sql=' FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN billing b ON b.billing_id=bi.billing_id JOIN fees f ON f.fee_id=bi.fee_id WHERE '.PaymentReportingScope::officialCondition('p').' AND b.academic_year=? AND b.semester=?';if($f['payment_channel']){$sql.=' AND p.payment_channel=?';$p[]=$f['payment_channel'];}if($f['payment_method']){$sql.=' AND p.payment_method=?';$p[]=$f['payment_method'];}if($f['search']!==''){$sql.=' AND (p.reference_number LIKE ? OR p.receipt_number LIKE ? OR EXISTS (SELECT 1 FROM students sr WHERE sr.student_id=p.student_id AND (sr.student_number LIKE ? OR sr.full_name LIKE ?)))';$like='%'.$f['search'].'%';array_push($p,$like,$like,$like,$like);}if($f['fee_category']){$sql.=' AND f.category_id=?';$p[]=$f['fee_category'];}$fmt=$unit==='hour'?'%Y-%m-%d %H:00:00':($unit==='month'?'%Y-%m-01 00:00:00':'%Y-%m-%d 00:00:00');$p[]=AccountingReportingPeriod::sql($start);$p[]=AccountingReportingPeriod::sql($end);$q=$this->pdo->prepare("SELECT DATE_FORMAT(p.verified_at,'$fmt') bucket,SUM(pa.allocated_amount) total".$sql.' AND p.verified_at>=? AND p.verified_at<? GROUP BY bucket');$q->execute($p);$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$map[$row['bucket']]=(float)$row['total'];$cursor=$unit==='hour'?$start->setTime((int)$start->format('H'),0):($unit==='month'?$start->modify('first day of this month')->setTime(0,0):$start->setTime(0,0));$last=$end->modify('-1 microsecond');$last=$unit==='hour'?$last->setTime((int)$last->format('H'),0):($unit==='month'?$last->modify('first day of this month')->setTime(0,0):$last->setTime(0,0));$labels=[];$values=[];while($cursor<=$last){$labels[]=$cursor->format($unit==='hour'?'g A':($unit==='month'?'M':'M j'));$values[]=$map[$cursor->format('Y-m-d H:i:s')]??0.0;$cursor=$cursor->modify('+1 '.$unit);}return['labels'=>$labels,'values'=>$values];}
 private function picklists(array &$d):void{try{$years=$this->pdo->query('SELECT DISTINCT YEAR(p.verified_at) report_year FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.payment_id WHERE p.payment_status=\'Verified\' AND p.verified_at IS NOT NULL AND '.PaymentReportingScope::officialCondition('p').' ORDER BY report_year DESC')->fetchAll(PDO::FETCH_COLUMN);$years=array_map('intval',$years);$currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone(AccountingReportingPeriod::TIMEZONE)))->format('Y');if(!in_array($currentYear,$years,true))$years[]=$currentYear;rsort($years,SORT_NUMERIC);$d['filter_options']=['terms'=>$this->pdo->query("SELECT academic_year,semester FROM billing GROUP BY academic_year,semester ORDER BY academic_year DESC,FIELD(semester,'1st','2nd','Summer')")->fetchAll(PDO::FETCH_ASSOC),'channels'=>$this->pdo->query('SELECT DISTINCT payment_channel FROM payments ORDER BY payment_channel')->fetchAll(PDO::FETCH_COLUMN),'methods'=>$this->pdo->query('SELECT DISTINCT payment_method FROM payments WHERE payment_method IS NOT NULL AND payment_method<>\'\' ORDER BY payment_method')->fetchAll(PDO::FETCH_COLUMN),'categories'=>$this->categoryRows(),'report_years'=>$years];foreach($d['filter_options']['categories']as$c)if((int)$c['category_id']===(int)($d['scope']['fee_category']??0))$d['scope']['fee_category_name']=$c['category_name'];$d['section_status']['filter_options']='ok';}catch(Throwable $e){$d['filter_options']=['terms'=>[],'channels'=>[],'methods'=>[],'categories'=>[],'report_years'=>[]];$d['section_status']['filter_options']='error';}}
 private function efficiency(array &$d,array $f):void{$p=[$f['academic_year'],$f['semester'],AccountingReportingPeriod::sql($f['range']['end_exclusive'])];$q=$this->pdo->prepare('SELECT COALESCE(SUM(pa.allocated_amount),0) FROM payment_allocations pa JOIN payments p ON p.payment_id=pa.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN billing b ON b.billing_id=bi.billing_id WHERE '.PaymentReportingScope::officialCondition('p').' AND b.academic_year=? AND b.semester=? AND p.verified_at<?');$q->execute($p);$n=(float)$q->fetchColumn();$net=(float)$d['kpis']['net_assessed_amount'];$d['kpis']['term_to_date_collections']=$n;$d['kpis']['collection_efficiency']=$net>0?$n/$net*100:null;$d['kpis']['snapshot_note']='Net Assessed Amount and Outstanding Balance are AY/Semester snapshots; category filtering applies to collection sections only.';if($net>0&&$n/$net*100>100)$d['integrity_warnings'][]='Collection Efficiency exceeds 100%.';}
}
