<?php
/**
 * SMS 2 - Printable Statement of Account
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

$pageTitle = 'Statement of Account';
$studentId = $_SESSION['student_id'] ?? 'S230106713'; // Default fallback

$studentName = 'Unknown Student';
$course = 'N/A';
$yearLevel = 'N/A';
$academicYear = 'N/A';
$semester = 'N/A';
$billingItems = [];
$totalAmount = 0;
$totalPaid = 0;
$remainingBalance = 0;

try {
    $pdo = studentPortalDb();
    
    if ($pdo) {
        $searchSn = strtoupper(trim($studentId));
        if (!str_starts_with($searchSn, 'S') && is_numeric($searchSn)) {
            $searchSn = 'S' . str_pad($searchSn, 9, '0', STR_PAD_LEFT);
        }

        $stmt = $pdo->prepare("SELECT student_id, student_number, full_name, course, year_level FROM payment_db.students WHERE student_number = :student_number OR student_number = :raw_number LIMIT 1");
        $stmt->execute([':student_number' => $searchSn, ':raw_number' => $studentId]);
        $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($studentRow) {
            $dbStudentId = $studentRow['student_id'];
            $studentIdDisplay = $studentRow['student_number'];
            $studentName = $studentRow['full_name'];
            $course = $studentRow['course'];
            $yearLevel = $studentRow['year_level'];

            $stmtBilling = $pdo->prepare("SELECT * FROM payment_db.billing WHERE student_id = :student_id ORDER BY billing_id DESC LIMIT 1");
            $stmtBilling->execute([':student_id' => $dbStudentId]);
            $billingDetails = $stmtBilling->fetch(PDO::FETCH_ASSOC);

            if ($billingDetails) {
                $totalAmount = (float)$billingDetails['total_amount'];
                $remainingBalance = (float)$billingDetails['remaining_balance'];
                $totalPaid = $totalAmount - $remainingBalance;
                $academicYear = $billingDetails['academic_year'];
                $semester = $billingDetails['semester'];

                $stmtItems = $pdo->prepare("
                    SELECT bi.*, f.fee_name 
                    FROM payment_db.billing_items bi 
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id 
                    WHERE bi.billing_id = :billing_id
                ");
                $stmtItems->execute([':billing_id' => $billingDetails['billing_id']]);
                $billingItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement of Account - <?= htmlspecialchars($studentName) ?></title>
    <!-- Use the same bootstrap as the rest of the app -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #e9ecef; }
        .soa-container { background: #fff; max-width: 800px; margin: 2rem auto; padding: 2rem 3rem; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .soa-header { border-bottom: 2px solid #000; padding-bottom: 1rem; margin-bottom: 2rem; }
        .school-name { font-size: 1.5rem; font-weight: bold; text-transform: uppercase; }
        .school-address { font-size: 0.9rem; color: #555; }
        .soa-title { font-size: 1.25rem; font-weight: bold; letter-spacing: 1px; text-align: center; margin-bottom: 1.5rem; }
        .student-info { margin-bottom: 2rem; }
        .student-info th { text-align: left; padding-right: 1rem; color: #555; width: 120px; }
        .student-info td { font-weight: bold; }
        .fee-table th { background: #f8f9fa; text-transform: uppercase; font-size: 0.85rem; }
        .totals-table th { text-align: right; padding-right: 1rem; }
        .totals-table td { font-weight: bold; text-align: right; }
        
        @media print {
            body { background: #fff; }
            .soa-container { box-shadow: none; margin: 0 auto; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="soa-container">
    <div class="text-end mb-3 no-print">
        <a href="account-balance.php" class="btn btn-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm ms-2">Print SOA</button>
    </div>

    <div class="soa-header text-center">
        <div class="school-name">Bestlink College of the Philippines</div>
        <div class="school-address">#1071 Brgy. Kaligayahan, Quirino Highway, Novaliches, Quezon City</div>
    </div>

    <div class="soa-title">STATEMENT OF ACCOUNT</div>

    <table class="student-info">
        <tr>
            <th>Student No.</th>
            <td><?= htmlspecialchars($studentIdDisplay ?? '') ?></td>
            <th>Term</th>
            <td><?= htmlspecialchars($semester) ?> Semester, A.Y. <?= htmlspecialchars($academicYear) ?></td>
        </tr>
        <tr>
            <th>Name</th>
            <td><?= htmlspecialchars($studentName) ?></td>
            <th>Course/Year</th>
            <td><?= htmlspecialchars($course) ?> - <?= htmlspecialchars($yearLevel) ?></td>
        </tr>
    </table>

    <table class="table table-bordered fee-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-end" style="width: 150px;">Amount (PHP)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($billingItems) > 0): ?>
                <?php foreach ($billingItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['fee_name']) ?></td>
                        <td class="text-end"><?= number_format($item['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="2" class="text-center text-muted">No assessment records found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="row justify-content-end mt-4">
        <div class="col-6">
            <table class="table table-borderless totals-table">
                <tr>
                    <th>Total Assessment:</th>
                    <td><?= number_format($totalAmount, 2) ?></td>
                </tr>
                <tr>
                    <th>Less Payments:</th>
                    <td class="text-danger">- <?= number_format($totalPaid, 2) ?></td>
                </tr>
                <tr style="border-top: 2px solid #000;">
                    <th>Remaining Balance:</th>
                    <td class="fs-5"><?= number_format($remainingBalance, 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="mt-5 text-center" style="font-size: 0.85rem; color: #666;">
        <p>This is a system-generated statement. If you have any concerns regarding your assessment, please visit the Accounting Office.</p>
        <p>Generated on <?= date('F j, Y h:i A') ?></p>
    </div>
</div>

</body>
</html>
