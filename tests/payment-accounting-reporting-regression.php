<?php
declare(strict_types=1);

// Integration regression for canonical Accounting reporting. Never falls back to application DB settings.
require_once __DIR__ . '/../modules/payment/includes/AccountingReportingPageService.php';

$dsn = (string) getenv('ACCOUNTING_REPORTING_TEST_DSN');
if ($dsn === ''
    || !str_starts_with(strtolower($dsn), 'mysql:')
    || !preg_match('/(?:^|;)host=([^;]+)/i', $dsn)
    || !preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $databaseMatch)
    || !str_ends_with(strtolower(trim($databaseMatch[1])), '_test')) {
    echo "NOT EXECUTED - SAFE TEST DATABASE NOT CONFIGURED. ACCOUNTING_REPORTING_TEST_DSN must explicitly name a MySQL/MariaDB database ending in _test.\n";
    exit(0);
}

require_once __DIR__ . '/helpers/AccountingReportingQueryRecorder.php';
$pdo = new AccountingReportingQueryRecorder(
    $dsn,
    (string) (getenv('ACCOUNTING_REPORTING_TEST_USER') ?: 'root'),
    (string) (getenv('ACCOUNTING_REPORTING_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AccountingReportingRecordedStatement::class, [$pdo]]);
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database === '' || !str_ends_with(strtolower($database), '_test')) {
    fwrite(STDERR, "TEST DATABASE SAFETY GUARD FAILED: connected database does not end in _test.\n");
    exit(2);
}

$requiredTables = ['students', 'billing', 'billing_items', 'fees', 'fee_categories', 'payments', 'payment_allocations'];
foreach ($requiredTables as $table) {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    if (!(int) $statement->fetchColumn()) {
        fwrite(STDERR, "Missing required Accounting reporting test table: {$table}. No fixture was written.\n");
        exit(2);
    }
}
$requiredColumns = [
    'payments' => ['transaction_type', 'payment_method', 'payment_channel', 'gateway_environment', 'payment_status', 'verified_at', 'reference_number'],
    'billing' => ['academic_year', 'semester', 'total_amount', 'discount_amount', 'remaining_balance', 'billing_status'],
    'payment_allocations' => ['payment_id', 'billing_item_id', 'allocated_amount'],
];
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $statement->execute([$table, $column]);
        if (!(int) $statement->fetchColumn()) {
            fwrite(STDERR, "Missing required Accounting reporting test column: {$table}.{$column}. No fixture was written.\n");
            exit(2);
        }
    }
}

$checks = 0;
$tag = 'AR' . bin2hex(random_bytes(5));
$targetYear = '2029-2030';
$otherYear = '2028-2029';

function reportCheck(bool $condition, string $message, mixed $expected = null, mixed $actual = null): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        $detail = $expected === null && $actual === null ? '' : ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
        throw new RuntimeException("Assertion failed: {$message}{$detail}");
    }
}

function reportAmount(float $expected, mixed $actual, string $message): void
{
    reportCheck(abs($expected - (float) $actual) < 0.0001, $message, $expected, (float) $actual);
}

function reportMap(array $rows, string $valueKey = 'total'): array
{
    $result = [];
    foreach ($rows as $row) $result[(string) $row['label']] = (float) $row[$valueKey];
    return $result;
}

function insertStudent(PDO $pdo, string $number, string $name): int
{
    $statement = $pdo->prepare("INSERT INTO students (user_id,student_number,full_name,course,year_level,status) VALUES (?,?,?,?,?,'Enrolled')");
    $statement->execute([900000000 + random_int(1, 9999999), $number, $name, 'BSIT', '1']);
    return (int) $pdo->lastInsertId();
}

function insertBilling(PDO $pdo, int $studentId, string $year, string $semester, float $total, float $discount, float $remaining, string $status): int
{
    $statement = $pdo->prepare("INSERT INTO billing (student_id,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES (?,'Assessment',?,?,?,?,?,?)");
    $statement->execute([$studentId, $year, $semester, $total, $discount, $remaining, $status]);
    $id = (int) $pdo->lastInsertId();
    // Existing installations may have a billing trigger that derives the initial snapshot.
    $pdo->prepare('UPDATE billing SET remaining_balance=?,billing_status=? WHERE billing_id=?')->execute([$remaining, $status, $id]);
    return $id;
}

function insertPayment(PDO $pdo, array $data): int
{
    $sql = 'INSERT INTO payments (student_id,billing_id,transaction_type,payment_method,amount,payment_channel,gateway_environment,reference_number,payment_status,payment_date,verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute([$data['student'], $data['billing'], $data['type'], $data['method'], $data['amount'], $data['channel'], $data['environment'], $data['reference'], $data['status'], substr($data['verified'], 0, 10), $data['verified'], $data['verified']]);
    return (int) $pdo->lastInsertId();
}

function allocatePayment(PDO $pdo, int $paymentId, int $billingItemId, float $amount): void
{
    $pdo->prepare('INSERT INTO payment_allocations (payment_id,billing_item_id,allocated_amount) VALUES (?,?,?)')->execute([$paymentId, $billingItemId, $amount]);
}

try {
    $pdo->beginTransaction();

    $studentOne = insertStudent($pdo, "{$tag}-S1", "{$tag} Alpha Student");
    $studentTwo = insertStudent($pdo, "{$tag}-S2", "{$tag} Beta Student");
    $targetBillingOne = insertBilling($pdo, $studentOne, $targetYear, '1st', 1000, 100, 600, 'Partial');
    $targetBillingTwo = insertBilling($pdo, $studentTwo, $targetYear, '1st', 500, 0, 0, 'Paid');
    $otherBilling = insertBilling($pdo, $studentOne, $otherYear, '2nd', 700, 0, 700, 'Unpaid');

    $pdo->prepare("INSERT INTO fee_categories (category_name,priority_order,status) VALUES (?,999,'Active')")->execute(["{$tag} Tuition"]);
    $categoryOne = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fee_categories (category_name,priority_order,status) VALUES (?,999,'Active')")->execute(["{$tag} Misc"]);
    $categoryTwo = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fees (fee_code,category_id,fee_name,default_amount,is_required,status,identity_status) VALUES (?,?,?,1000,1,'Active','Active')")->execute(["{$tag}-F1", $categoryOne, "{$tag} Fee One"]);
    $feeOne = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fees (fee_code,category_id,fee_name,default_amount,is_required,status,identity_status) VALUES (?,?,?,1000,1,'Active','Active')")->execute(["{$tag}-F2", $categoryTwo, "{$tag} Fee Two"]);
    $feeTwo = (int) $pdo->lastInsertId();

    $itemSql = "INSERT INTO billing_items (billing_id,fee_id,fee_name,source_context,amount,paid_amount,remaining_amount,status) VALUES (?,?,?,'Reporting regression',1000,0,1000,'Unpaid')";
    $pdo->prepare($itemSql)->execute([$targetBillingOne, $feeOne, "{$tag} Fee One"]); $itemOne = (int) $pdo->lastInsertId();
    $pdo->prepare($itemSql)->execute([$targetBillingOne, $feeTwo, "{$tag} Fee Two"]); $itemTwo = (int) $pdo->lastInsertId();
    $pdo->prepare($itemSql)->execute([$targetBillingTwo, $feeTwo, "{$tag} Fee Two Student Two"]); $itemThree = (int) $pdo->lastInsertId();
    $pdo->prepare($itemSql)->execute([$otherBilling, $feeOne, "{$tag} Other Term"]); $otherItem = (int) $pdo->lastInsertId();

    $records = [
        'prior' => [$studentOne,$targetBillingOne,'Walk-in','Walk-in',50,'Cash',null,'Verified','2029-12-15 12:00:00',$itemOne,50],
        'start' => [$studentOne,$targetBillingOne,'Walk-in','Walk-in',999,'Cash',null,'Verified','2030-01-01 00:00:00',$itemOne,100],
        'online' => [$studentOne,$targetBillingOne,'Online','Online',250,'QRPh','live','Verified','2030-01-10 10:00:00',$itemTwo,200],
        'bank' => [$studentOne,$targetBillingOne,'Payment Concern','Bank Transfer',300,'Bank',null,'Verified','2030-01-15 10:00:00',$itemOne,300],
        'before_end' => [$studentTwo,$targetBillingTwo,'Walk-in','Walk-in',40,'Cash',null,'Verified','2030-01-31 23:59:59',$itemThree,40],
        'at_end' => [$studentTwo,$targetBillingTwo,'Walk-in','Walk-in',700,'Cash',null,'Verified','2030-02-01 00:00:00',$itemThree,700],
        'test_online' => [$studentOne,$targetBillingOne,'Online','Online',400,'GCash','test','Verified','2030-01-12 10:00:00',$itemTwo,400],
        'pending' => [$studentOne,$targetBillingOne,'Online','Online',410,'Maya','live','Pending','2030-01-13 10:00:00',$itemTwo,410],
        'failed' => [$studentOne,$targetBillingOne,'Online','Online',420,'Visa','live','Failed','2030-01-14 10:00:00',$itemTwo,420],
        'rejected' => [$studentOne,$targetBillingOne,'Online','Online',430,'Mastercard','live','Rejected','2030-01-16 10:00:00',$itemTwo,430],
        'other_term' => [$studentOne,$otherBilling,'Walk-in','Walk-in',500,'Cash',null,'Verified','2030-01-20 10:00:00',$otherItem,500],
    ];
    $paymentIds = [];
    foreach ($records as $name => [$student,$billing,$type,$method,$headerAmount,$channel,$environment,$status,$verified,$item,$allocation]) {
        $reference = "{$tag}-{$name}";
        $paymentIds[$name] = insertPayment($pdo, compact('student','billing','type','method','channel','environment','status','verified','reference') + ['amount'=>$headerAmount]);
        allocatePayment($pdo, $paymentIds[$name], $item, $allocation);
    }

    $paginationYear = '2031-2032';
    $paginationBilling = insertBilling($pdo, $studentOne, $paginationYear, '1st', 700, 0, 700, 'Partial');
    $pdo->prepare($itemSql)->execute([$paginationBilling, $feeOne, "{$tag} Pagination Fee"]); $paginationItem = (int) $pdo->lastInsertId();
    $paginationRecords = [
        'pg1' => ['2032-01-07 10:00:00',10],
        'pg2' => ['2032-01-07 10:00:00',20],
        'pg3' => ['2032-01-06 10:00:00',30],
        'pg4' => ['2032-01-05 10:00:00',40],
        'pg5' => ['2032-01-04 10:00:00',50],
        'pg6' => ['2032-01-03 10:00:00',60],
        'pg7' => ['2032-01-01 00:00:00',70],
    ];
    $paginationIds = [];
    foreach ($paginationRecords as $name => [$verified,$allocation]) {
        $reference = "{$tag}-PG-{$name}";
        $paginationIds[$name] = insertPayment($pdo, ['student'=>$studentOne,'billing'=>$paginationBilling,'type'=>'Walk-in','method'=>'Walk-in','amount'=>1000+$allocation,'channel'=>'Cash','environment'=>null,'status'=>'Verified','verified'=>$verified,'reference'=>$reference]);
        allocatePayment($pdo, $paginationIds[$name], $paginationItem, $allocation);
    }
    $paginationExcludedIds = [];
    foreach ([
        'test_online'=>['Online','Online','GCash','test','Verified','2032-01-08 10:00:00'],
        'pending'=>['Online','Online','Maya','live','Pending','2032-01-08 11:00:00'],
        'failed'=>['Online','Online','Visa','live','Failed','2032-01-08 12:00:00'],
        'rejected'=>['Online','Online','Mastercard','live','Rejected','2032-01-08 13:00:00'],
        'at_end'=>['Walk-in','Walk-in','Cash',null,'Verified','2032-02-01 00:00:00'],
    ] as $name => [$type,$method,$channel,$environment,$status,$verified]) {
        $reference = "{$tag}-PX-{$name}";
        $paginationExcludedIds[$name] = insertPayment($pdo, ['student'=>$studentOne,'billing'=>$paginationBilling,'type'=>$type,'method'=>$method,'amount'=>900,'channel'=>$channel,'environment'=>$environment,'status'=>$status,'verified'=>$verified,'reference'=>$reference]);
        allocatePayment($pdo, $paginationExcludedIds[$name], $paginationItem, 90);
    }

    $service = new AccountingReportingPageService($pdo);
    $filters = ['academic_year'=>$targetYear,'semester'=>'1st','period'=>'month','period_month'=>'1','period_year'=>'2030'];
    $report = $service->load($filters);

    reportAmount(640, $report['kpis']['official_academic_collections'], 'official total includes only target-term verified official allocations');
    reportCheck((int)$report['kpis']['verified_payment_count'] === 4, 'verified payment count is exact', 4, $report['kpis']['verified_payment_count']);
    reportAmount(160, $report['kpis']['average_payment_amount'], 'average uses allocation total per included payment');
    reportAmount(1400, $report['kpis']['net_assessed_amount'], 'net assessment uses total less discounts for target term');
    reportAmount(600, $report['kpis']['outstanding_balance'], 'outstanding balance includes Unpaid/Partial and excludes Paid');
    reportAmount(690, $report['kpis']['term_to_date_collections'], 'term-to-date includes prior-period official allocations before cutoff');
    reportAmount(690 / 1400 * 100, $report['kpis']['collection_efficiency'], 'efficiency uses unfiltered term snapshot');
    $snapshotNote = strtolower(trim((string) $report['kpis']['snapshot_note']));
    reportCheck(
        $snapshotNote !== ''
        && str_contains($snapshotNote, 'category')
        && str_contains($snapshotNote, 'filter')
        && str_contains($snapshotNote, 'collection')
        && str_contains($snapshotNote, 'only'),
        'snapshot note preserves unfiltered efficiency contract'
    );

    $methods = reportMap($report['payment_methods']);
    reportAmount(140, $methods['Cash'] ?? -1, 'Cash method breakdown');
    reportAmount(200, $methods['Online'] ?? -1, 'Online contains only verified live online allocation');
    reportAmount(300, $methods['Bank Transfer'] ?? -1, 'Bank Transfer method breakdown');
    $channels = reportMap($report['payment_channels']);
    reportAmount(140, $channels['Cash'] ?? -1, 'Cash channel total');
    reportAmount(200, $channels['QRPh'] ?? -1, 'live QRPh channel total');
    reportAmount(300, $channels['Bank'] ?? -1, 'Bank channel total');
    $categories = reportMap($report['fee_categories']);
    reportAmount(400, $categories["{$tag} Tuition"] ?? -1, 'first category allocation total');
    reportAmount(240, $categories["{$tag} Misc"] ?? -1, 'second category allocation total');

    $recentIds = array_map('intval', array_column($report['recent_collections'], 'payment_id'));
    sort($recentIds); $expectedRecent = [$paymentIds['start'],$paymentIds['online'],$paymentIds['bank'],$paymentIds['before_end']]; sort($expectedRecent);
    reportCheck($recentIds === $expectedRecent, 'recent collections returns the complete matching set', $expectedRecent, $recentIds);
    reportCheck(!in_array($paymentIds['at_end'], $recentIds, true), 'exact end boundary is excluded');
    reportCheck(in_array($paymentIds['start'], $recentIds, true), 'exact start boundary is included');
    reportCheck(in_array($paymentIds['before_end'], $recentIds, true), 'last second before end is included');
    foreach (['test_online','pending','failed','rejected','other_term'] as $excluded) reportCheck(!in_array($paymentIds[$excluded], $recentIds, true), "{$excluded} is excluded from official target-term reporting");

    reportAmount(140, $service->load($filters + ['payment_channel'=>'Cash'])['kpis']['official_academic_collections'], 'channel filter includes only selected channel');
    reportAmount(200, $service->load($filters + ['payment_method'=>'Online'])['kpis']['official_academic_collections'], 'method filter includes only selected method');
    reportAmount(600, $service->load($filters + ['search'=>"{$tag} Alpha"])['kpis']['official_academic_collections'], 'student-name search excludes unrelated student');
    reportAmount(40, $service->load($filters + ['search'=>"{$tag}-S2"])['kpis']['official_academic_collections'], 'student-number search matches second student');
    reportAmount(200, $service->load($filters + ['search'=>"{$tag}-online"])['kpis']['official_academic_collections'], 'reference search matches exact payment');
    reportAmount(640, $service->load($filters + ['search'=>'%'])['kpis']['official_academic_collections'], 'LIKE wildcard behavior remains part of search contract');
    $categoryReport = $service->load($filters + ['fee_category'=>$categoryOne]);
    reportAmount(400, $categoryReport['kpis']['official_academic_collections'], 'fee-category filter narrows official collections');
    reportCheck(count($categoryReport['fee_categories']) === 1, 'fee-category summary respects selected category');

    foreach ([['payment_channel'=>'Cash'],['payment_method'=>'Online'],['search'=>"{$tag}-S2"],['fee_category'=>$categoryOne]] as $optionalFilter) {
        $filtered = $service->load($filters + $optionalFilter);
        reportAmount(690, $filtered['kpis']['term_to_date_collections'], 'optional filter does not narrow term efficiency numerator');
        reportAmount(690 / 1400 * 100, $filtered['kpis']['collection_efficiency'], 'optional filter does not narrow efficiency percentage');
    }

    reportAmount(640, array_sum(array_map('floatval', $report['trend']['current'])), 'current trend series matches current-period official total');
    reportAmount(50, array_sum(array_map('floatval', $report['trend']['prior'])), 'prior trend series matches prior-period official total');
    reportCheck(count($report['trend']['labels']) === 31, 'January month trend contains all daily buckets', 31, count($report['trend']['labels']));

    $paginationFilters = ['academic_year'=>$paginationYear,'semester'=>'1st','period'=>'month','period_month'=>'1','period_year'=>'2032','payment_channel'=>'Cash','payment_method'=>'Walk-in','search'=>"{$tag}-PG",'fee_category'=>$categoryOne,'page_size'=>3];
    $pageOne = $service->loadForWeb($paginationFilters + ['page'=>1]);
    $pageTwo = $service->loadForWeb($paginationFilters + ['page'=>2]);
    $pageThree = $service->loadForWeb($paginationFilters + ['page'=>3]);
    $expectedPagedOrder = [$paginationIds['pg2'],$paginationIds['pg1'],$paginationIds['pg3'],$paginationIds['pg4'],$paginationIds['pg5'],$paginationIds['pg6'],$paginationIds['pg7']];
    $pageOneIds = array_map('intval', array_column($pageOne['recent_collections'],'payment_id'));
    $pageTwoIds = array_map('intval', array_column($pageTwo['recent_collections'],'payment_id'));
    $pageThreeIds = array_map('intval', array_column($pageThree['recent_collections'],'payment_id'));
    reportCheck(count($pageOneIds) === 3, 'web page one is bounded by requested page size', 3, count($pageOneIds));
    reportCheck(count($pageTwoIds) === 3, 'web page two returns the next full page', 3, count($pageTwoIds));
    reportCheck(count($pageThreeIds) === 1, 'web final page returns the remaining partial page', 1, count($pageThreeIds));
    reportCheck(count(array_unique(array_merge($pageOneIds,$pageTwoIds,$pageThreeIds))) === 7, 'payments are not duplicated across web pages');
    reportCheck(array_merge($pageOneIds,$pageTwoIds,$pageThreeIds) === $expectedPagedOrder, 'page union is complete and deterministically ordered', $expectedPagedOrder, array_merge($pageOneIds,$pageTwoIds,$pageThreeIds));
    reportCheck($pageOneIds[0] === $paginationIds['pg2'] && $pageOneIds[1] === $paginationIds['pg1'], 'equal timestamps use descending payment ID tie-breaker');
    reportCheck(count(array_filter($pageOne['recent_collections'], static fn(array $row): bool => $row['payment_channel']!=='Cash' || $row['payment_method']!=='Walk-in' || !str_contains((string)$row['reference_number'],'-PG-') || !str_contains((string)$row['allocation_breakdown'],'Tuition')))===0, 'web page preserves channel, method, search, and category filters');
    $meta = $pageOne['recent_collections_pagination'];
    reportCheck($meta['page']===1 && $meta['page_size']===3 && $meta['total_records']===7 && $meta['total_pages']===3 && $meta['has_previous']===false && $meta['has_next']===true, 'page one metadata is exact');
    $meta = $pageTwo['recent_collections_pagination'];
    reportCheck($meta['page']===2 && $meta['has_previous']===true && $meta['has_next']===true, 'page two metadata exposes both directions');
    $meta = $pageThree['recent_collections_pagination'];
    reportCheck($meta['page']===3 && $meta['has_previous']===true && $meta['has_next']===false, 'final page metadata is exact');
    reportCheck(in_array($paginationIds['pg7'],$pageThreeIds,true) && !in_array($paginationExcludedIds['at_end'],array_merge($pageOneIds,$pageTwoIds,$pageThreeIds),true), 'paged results preserve half-open date boundaries');
    reportCheck(count(array_intersect(array_values($paginationExcludedIds),array_merge($pageOneIds,$pageTwoIds,$pageThreeIds)))===0, 'paged results apply official payment scope before pagination');
    $pg1Row = current(array_filter(array_merge($pageOne['recent_collections'],$pageTwo['recent_collections'],$pageThree['recent_collections']),static fn(array $row):bool=>(int)$row['payment_id']===$paginationIds['pg1']));
    reportAmount(10,$pg1Row['total_applied']??-1,'paged recent collection amount remains allocation-backed');
    $emptyPage = $service->loadForWeb(array_replace($paginationFilters,['search'=>"{$tag}-NO-MATCH",'page'=>4]));
    reportCheck($emptyPage['recent_collections']===[], 'empty paged result contains no rows');
    reportCheck($emptyPage['recent_collections_pagination']===['page'=>1,'page_size'=>3,'total_records'=>0,'total_pages'=>0,'has_previous'=>false,'has_next'=>false], 'empty pagination metadata is valid');
    $outOfRange = $service->loadForWeb($paginationFilters + ['page'=>99]);
    reportCheck($outOfRange['recent_collections_pagination']['page']===3 && count($outOfRange['recent_collections'])===1, 'out-of-range page clamps to the last valid page');
    $invalidPaging = $service->loadForWeb(array_replace($paginationFilters,['page'=>'invalid','page_size'=>101]));
    reportCheck($invalidPaging['recent_collections_pagination']['page']===1 && $invalidPaging['recent_collections_pagination']['page_size']===25, 'invalid pagination input falls back to safe defaults');
    $completeExport = $service->loadForExport($paginationFilters + ['page'=>1,'page_size'=>3]);
    $completeExportIds = array_map('intval',array_column($completeExport['recent_collections'],'payment_id'));
    reportCheck($completeExportIds===$expectedPagedOrder, 'export returns the complete matching set independent of web page size', $expectedPagedOrder, $completeExportIds);
    reportCheck(!array_key_exists('recent_collections_pagination',$completeExport), 'export response excludes web pagination metadata');

    reportCheck(!array_key_exists('ok', $report) && !array_key_exists('ok', $completeExport), 'unused top-level ok is absent from web and export');
    reportCheck(!array_key_exists('efficiency_note', $report['kpis']) && !array_key_exists('efficiency_note', $completeExport['kpis']), 'unused efficiency_note is absent from web and export');
    $webContract = ['scope'=>'array','section_status'=>'array','kpis'=>'array','fee_categories'=>'array','recent_collections'=>'array','recent_collections_pagination'=>'array','trend'=>'array','filter_options'=>'array','payment_methods'=>'array'];
    foreach ($webContract as $field => $type) reportCheck(array_key_exists($field,$report) && gettype($report[$field])===$type, "web contract field {$field} has expected shape");
    $exportContract = ['timezone'=>'string','generated_at'=>'string','scope'=>'array','kpis'=>'array','fee_categories'=>'array','payment_channels'=>'array','recent_collections'=>'array'];
    foreach ($exportContract as $field => $type) reportCheck(array_key_exists($field,$completeExport) && gettype($completeExport[$field])===$type, "export contract field {$field} has expected shape");
    foreach (['online_summary','needs_attention','channel_breakdown'] as $removed) reportCheck(!array_key_exists($removed,$report), "removed response field {$removed} remains absent");
    foreach (['cash_payments','online_payments','bank_transfers','live_online_collections'] as $removed) reportCheck(!array_key_exists($removed,$report['kpis']), "removed KPI {$removed} remains absent");

    // Actual executions for explicit-term requests; both efficiency paths stay in the shared flow.
    $queryScenarios = [
        'web comparison category' => ['web', $filters + ['fee_category'=>$categoryOne], 18],
        'export comparison category' => ['export', $filters + ['fee_category'=>$categoryOne], 10],
        'export comparison no category' => ['export', $filters, 9],
        'export custom category' => ['export', ['academic_year'=>$targetYear,'semester'=>'1st','period'=>'custom','start_date'=>'2030-01-01','end_date'=>'2030-01-31','fee_category'=>$categoryOne], 10],
        'export custom no category' => ['export', ['academic_year'=>$targetYear,'semester'=>'1st','period'=>'custom','start_date'=>'2030-01-01','end_date'=>'2030-01-31'], 9],
    ];
    foreach ($queryScenarios as $label => [$mode,$input,$expectedCount]) {
        $pdo->executed = [];
        $result = $mode === 'web' ? $service->loadForWeb($input) : $service->loadForExport($input);
        reportCheck(count($pdo->executed)===$expectedCount, $label.' execution count', $expectedCount, count($pdo->executed));
        echo 'QUERY COUNT: '.$label.' = '.count($pdo->executed).PHP_EOL;
        if ($mode === 'export') {
            $sql = implode("\n",$pdo->executed);
            reportCheck(!preg_match('/DATE_FORMAT|report_year|SELECT academic_year,semester FROM billing|SELECT DISTINCT payment_channel|SELECT DISTINCT payment_method|SELECT CASE WHEN/', $sql), $label.' skips every web-only query family');
            reportCheck(substr_count($sql,'SELECT category_id,category_name FROM fee_categories WHERE') === (isset($input['fee_category'])?1:0), $label.' conditional category lookup count');
            reportCheck(!array_key_exists('filter_options', $result) && !array_key_exists('payment_methods', $result) && !array_key_exists('recent_collections_pagination',$result), $label.' omits web enrichment/pagination');
            reportAmount(690,$result['kpis']['term_to_date_collections'],$label.' preserves final unfiltered numerator');
            reportAmount(690/1400*100,$result['kpis']['collection_efficiency'],$label.' preserves final efficiency');
            reportCheck(array_key_exists('integrity_warnings',$result),$label.' preserves integrity metadata');
            if (isset($input['fee_category'])) reportCheck($result['scope']['fee_category_name']===$tag.' Tuition',$label.' selected category heading');
        } else {
            reportCheck(isset($result['trend'],$result['filter_options'],$result['payment_methods'],$result['recent_collections_pagination']), 'web enrichment remains present');
        }
    }
    $pdo->prepare("INSERT INTO fee_categories(category_name,priority_order,status) VALUES (?,999,'Active')")->execute([$tag.' Empty']);
    $emptyCategory=(int)$pdo->lastInsertId();
    $emptyExport=$service->loadForExport($filters+['fee_category'=>$emptyCategory]);
    reportCheck($emptyExport['recent_collections']===[] && $emptyExport['fee_categories']===[] && $emptyExport['scope']['fee_category_name']===$tag.' Empty','zero-collection category retains metadata heading');
    $missingExport=$service->loadForExport($filters+['fee_category'=>2147483647]);
    reportCheck(!array_key_exists('fee_category_name',$missingExport['scope']), 'nonexistent category preserves absent-label behavior');
    $pdo->exec("UPDATE billing SET total_amount=50,discount_amount=0 WHERE billing_id IN ($targetBillingOne,$targetBillingTwo)");
    $warningWeb=$service->loadForWeb($filters+['fee_category'=>$categoryOne]);
    $warningExport=$service->loadForExport($filters+['fee_category'=>$categoryOne]);
    reportCheck(count($warningExport['integrity_warnings'])===2 && $warningExport['integrity_warnings']===$warningWeb['integrity_warnings'],'both integrity paths remain, including duplicate warnings');
    $pdo->exec("UPDATE billing SET total_amount=1000,discount_amount=100 WHERE billing_id=$targetBillingOne");
    $pdo->exec("UPDATE billing SET total_amount=500,discount_amount=0 WHERE billing_id=$targetBillingTwo");
    $pdo->failCategoryLookup=true;
    try {
        $service->loadForExport($filters+['fee_category'=>$categoryOne]);
        reportCheck(false,'category lookup failure must propagate');
    } catch (PDOException $e) {
        reportCheck($e->getMessage()==='Injected category metadata failure','category failure safely propagates before XLSX generation');
    } finally { $pdo->failCategoryLookup=false; }
    echo "PASS: {$checks} Accounting reporting regression checks on {$database}.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
