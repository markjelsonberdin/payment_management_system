<?php
declare(strict_types=1);
require_once __DIR__.'/../../modules/payment/includes/AccountingReportingPageService.php';
require_once __DIR__.'/../../modules/payment/includes/SimpleXlsxWriter.php';
$mode=$argv[1]??'writer'; $n=(int)($argv[2]??100);
if(!in_array($mode,['writer','pipeline','failure'],true)||$n<1||$n>10000)throw new RuntimeException('Invalid benchmark tier');
$out=getenv('B15_OUTPUT'); if(!$out)throw new RuntimeException('Dedicated output path required');
if(realpath(dirname($out))!==realpath(sys_get_temp_dir()))throw new RuntimeException('Output and PHP temp directory must share a dedicated benchmark directory');
$pdo=null;$stages=[];$expected=[];$rows=[];$start=0;$setup=0;$writerException=null;$baseline=memory_get_usage(true);
function b15check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function b15rows(array $d):array{
 $s=$d['scope'];
 $r=[['Bestlink College of the Philippines'],['Payment Management System - Collection Report'],['Academic Year',$s['academic_year'],'Semester',$s['semester']],['Period',$s['period'],'Range',$s['start_at'].' to < '.$s['end_exclusive']],['Channel',$s['payment_channel']??'All','Fee Category',$s['fee_category_name']??'All'],['Generated',$d['generated_at'],'Timezone',$d['timezone']],['Net Assessed Amount',$d['kpis']['net_assessed_amount']],['Official Academic Collections',$d['kpis']['official_academic_collections']],['Outstanding Balance',$d['kpis']['outstanding_balance']],['Collection Efficiency',$d['kpis']['collection_efficiency']??'N/A'],[],['Fee Category','Applied Amount']];
 foreach($d['fee_categories'] as $x)$r[]=[$x['label'],$x['total']];
 $r[]=[];$r[]=['Payment Channel','Applied Amount'];foreach($d['payment_channels']as$x)$r[]=[$x['label'],$x['total']];
 $r[]=[];$r[]=['Verified At','OR / Reference','Student','Type','Channel','Applied Amount'];
 foreach($d['recent_collections']as$x)$r[]=[$x['verified_at'],$x['receipt_number']?:$x['reference_number'],$x['full_name'].' ('.$x['student_number'].')',$x['transaction_type'],$x['payment_channel'],$x['total_applied']];
 return$r;
}
register_shutdown_function(function()use(&$pdo,&$stages,&$expected,&$rows,&$start,&$setup,$baseline,$mode,$n,$out,&$writerException){
 $peak=memory_get_peak_usage(true);$after=memory_get_usage(true);$runtime=$start?(hrtime(true)-$start)/1e6:0;$valid=false;$formula=false;$rollback=$pdo===null;$failure=null;$size=0;
 try{
  if(ob_get_level())ob_end_flush();
  if($mode==='failure'){b15check($writerException==='Injected conversion failure','Injected failure was not observed: '.($writerException??'none'));throw new RuntimeException($writerException);}
  b15check(is_file($out),'Output missing');$size=filesize($out);b15check($size>0,'Empty output');
  $zip=new ZipArchive();b15check($zip->open($out)===true,'Invalid ZIP');
  foreach(['[Content_Types].xml','_rels/.rels','xl/workbook.xml','xl/_rels/workbook.xml.rels','xl/worksheets/sheet1.xml']as$entry)b15check($zip->locateName($entry)!==false,'Missing entry '.$entry);
  $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();$dom=new DOMDocument();b15check($dom->loadXML($xml),'Invalid XML');
  $xp=new DOMXPath($dom);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $actual=[];foreach($xp->query('//s:sheetData/s:row')as$row){$cells=[];foreach($xp->query('s:c/s:is/s:t',$row)as$c)$cells[]=$c->textContent;$actual[]=$cells;}
  b15check(count($actual)===count($rows),'Workbook row count mismatch');
  foreach($rows as$i=>$r)b15check($actual[$i]===array_map(static fn($v)=>(string)$v,$r),'Cell mismatch at row '.$i);
  b15check($xp->query('//s:f')->length===0,'Unexpected formula');
  $formula=$xp->query('//s:c[not(@t="inlineStr")]')->length===0;$valid=true;
 }catch(Throwable $e){$failure=$e->getMessage();}
 finally{
  if($pdo&&$pdo->inTransaction()){$pdo->rollBack();$rollback=!$pdo->inTransaction();}
  $orphans=glob(sys_get_temp_dir().'/sms2_xlsx_*')?:[];
  foreach($orphans as$file)unlink($file);
  if(is_file($out))unlink($out);
  fwrite(STDERR,json_encode(['mode'=>$mode,'rows'=>$n,'before_bytes'=>$baseline,'after_bytes'=>$after,'peak_bytes'=>$peak,'runtime_ms'=>round($runtime,3),'setup_ms'=>round($setup,3),'xlsx_bytes'=>$size,'valid'=>$valid,'formula_safe'=>$formula,'rollback'=>$rollback,'orphan_count'=>count($orphans),'cleanup'=>!is_file($out),'stages'=>$stages,'failure'=>$failure],JSON_UNESCAPED_SLASHES).PHP_EOL);
 }
});
if($mode==='pipeline'){
 $dsn=(string)getenv('ACCOUNTING_REPORTING_TEST_DSN');
 b15check(str_contains($dsn,'dbname=payment_accounting_reporting_test;'),'Exact test DSN required');
 $pdo=new PDO($dsn,(string)getenv('ACCOUNTING_REPORTING_TEST_USER'),(string)getenv('ACCOUNTING_REPORTING_TEST_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 b15check($pdo->query('SELECT DATABASE()')->fetchColumn()==='payment_accounting_reporting_test','Post-connect guard');
 foreach(['students','billing','billing_items','fees','fee_categories','payments','payment_allocations']as$t){
  b15check((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn()===0,'Test schema must be empty');
  $q=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);b15check($q->fetchColumn()==='InnoDB','Transactional fixture engine required');
 }
 $t=hrtime(true);$pdo->beginTransaction();
 $pdo->exec("INSERT INTO students(user_id,student_number,full_name,course,year_level,status) VALUES(999001,'B15-S1','=Benchmark Student','BSIT','1','Enrolled')");$sid=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO fee_categories(category_name,priority_order,status) VALUES('B15 Category',1,'Active')");$cat=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO fees(fee_code,category_id,fee_name,default_amount,is_required,status,identity_status) VALUES('B15-F1',$cat,'B15 Fee',100,1,'Active','Active')");$fee=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO billing(student_id,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES($sid,'Assessment','2029-2030','1st',1000000,0,1000000,'Partial')");$bill=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO billing_items(billing_id,fee_id,fee_name,source_context,amount,paid_amount,remaining_amount,status) VALUES($bill,$fee,'B15 Fee','Benchmark',1000000,0,1000000,'Unpaid')");$item=(int)$pdo->lastInsertId();
 $pay=$pdo->prepare('INSERT INTO payments(student_id,billing_id,transaction_type,payment_method,amount,payment_channel,gateway_environment,reference_number,payment_status,payment_date,verified_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
 $alloc=$pdo->prepare('INSERT INTO payment_allocations(payment_id,billing_item_id,allocated_amount) VALUES(?,?,?)');
 $add=function($ref,$stamp,$type='Walk-in',$method='Walk-in',$channel='Cash',$env=null,$status='Verified')use($pay,$alloc,$pdo,$sid,$bill,$item){$pay->execute([$sid,$bill,$type,$method,999,$channel,$env,$ref,$status,substr($stamp,0,10),$stamp,$stamp]);$id=(int)$pdo->lastInsertId();$alloc->execute([$id,$item,12.34]);return$id;};
 for($i=0;$i<$n;$i++){$stamp=$i===0?'2030-01-01 00:00:00':($i===$n-1?'2030-01-31 23:59:59':'2030-01-15 12:00:00');$expected[]=$add(['=','+','-','@'][$i%4].'B15-'.str_pad((string)$i,6,'0',STR_PAD_LEFT),$stamp);}
 $add('B15-end','2030-02-01 00:00:00');
 foreach(['Pending','Failed','Rejected']as$status)$add('B15-'.$status,'2030-01-20 12:00:00','Walk-in','Walk-in','Cash',null,$status);
 $add('B15-test','2030-01-20 12:00:00','Online','Online','GCash','test');
 $setup=(hrtime(true)-$t)/1e6;$stages['before_report']=memory_get_usage(true);$start=hrtime(true);
 $d=(new AccountingReportingPageService($pdo))->loadForExport(['academic_year'=>'2029-2030','semester'=>'1st','period'=>'month','period_month'=>1,'period_year'=>2030,'fee_category'=>$cat,'search'=>'B15-','page_size'=>3]);
 $stages['after_report']=memory_get_usage(true);
 $ids=array_map('intval',array_column($d['recent_collections'],'payment_id'));$sorted=$d['recent_collections'];
 usort($sorted,static fn($a,$b)=>strcmp($b['verified_at'],$a['verified_at'])?:((int)$b['payment_id']<=>(int)$a['payment_id']));
 b15check($ids===array_map('intval',array_column($sorted,'payment_id')),'Ordering mismatch');
 $set=$ids;sort($set);sort($expected);b15check($set===$expected&&count(array_unique($ids))===$n,'Scope/boundary/completeness mismatch');
 foreach($d['recent_collections']as$r)b15check(abs((float)$r['total_applied']-12.34)<0.0001,'Allocation mismatch');
 $rows=b15rows($d);$stages['after_rows']=memory_get_usage(true);
}else{
 $start=hrtime(true);$rows=[['Bestlink College of the Philippines'],['Payment Management System - Collection Report'],['Academic Year','2029-2030','Semester','1st'],['Official Collections',$n*12.34],['Fee Category','Applied Amount'],['B15 Category',$n*12.34],['Payment Channel','Applied Amount'],['Cash',$n*12.34],['Verified At','OR / Reference','Student','Type','Channel','Applied Amount']];
 for($i=0;$i<$n;$i++)$rows[]=['2030-01-15 12:00:00',['=','+','-','@'][$i%4].'B15-'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'Benchmark Student Name (B15-S1)','Walk-in','Cash',12.34];
 $stages['after_rows']=memory_get_usage(true);
 if($mode==='failure')$rows[]=[new class{public function __toString():string{throw new RuntimeException('Injected conversion failure');}}];
}
ob_start(static function(string $data)use($out):string{file_put_contents($out,$data,FILE_APPEND);return'';},8192);
try{SimpleXlsxWriter::download('b15.xlsx',$rows);}catch(Throwable $e){$writerException=$e->getMessage();}
