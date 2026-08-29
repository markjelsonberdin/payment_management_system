<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'AUTHENTICATION_REQUIRED', 'message' => 'Please log in.']);
    exit;
}

if (!userCanAccessModule('payment')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Unauthorized access.']);
    exit;
}

$student_number = $_GET['student_number'] ?? '';

if(empty($student_number)) {
    echo json_encode(['success' => false, 'message' => 'Empty student number.']);
    exit;
}

try {
    // 1. Fetch Student from Registrar API (and sync locally)
    $client = new RegistrarStudentClient($pdo);
    $student = $client->getAndSyncStudent($student_number);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found in Registrar records.']);
        exit;
    }
    
    $student_id = $student['student_id'];

    // 2. Query Payment Database for active billing using the student_id
    $stmt = $pdo->prepare("
        SELECT 
            billing_id, 
            billing_type,
            total_amount,
            remaining_balance
        FROM billing
        WHERE student_id = :sid
        AND billing_status IN ('Unpaid', 'Partial')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([':sid' => $student_id]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);

    if($billing) {
        $course_str = ($student['course_id'] ?? 'N/A') . ' - ' . ($student['year_level'] ?? 'N/A') . ' Year';

        // Fetch unpaid billing items for the breakdown
        $stmtItems = $pdo->prepare("
            SELECT 
                bi.billing_item_id, 
                f.fee_name, 
                fc.category_name, 
                bi.remaining_amount, 
                bi.source_context, 
                fc.priority_order
            FROM payment_db.billing_items bi
            JOIN payment_db.fees f ON bi.fee_id = f.fee_id
            JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
            WHERE bi.billing_id = :billing_id 
              AND bi.status != 'Paid'
              AND bi.remaining_amount > 0
            ORDER BY fc.priority_order ASC, bi.billing_item_id ASC
        ");
        $stmtItems->execute([':billing_id' => $billing['billing_id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $breakdownSum = 0;
        $groupedBreakdown = [];

        foreach ($items as $item) {
            $breakdownSum += (float)$item['remaining_amount'];
            $cat = $item['category_name'];
            if (!isset($groupedBreakdown[$cat])) {
                $groupedBreakdown[$cat] = [
                    'category_name' => $cat,
                    'category_total' => 0,
                    'fees' => []
                ];
            }
            $groupedBreakdown[$cat]['fees'][] = [
                'billing_item_id' => $item['billing_item_id'],
                'fee_name' => $item['fee_name'],
                'remaining_amount' => (float)$item['remaining_amount'],
                'source_context' => $item['source_context'],
                'priority_order' => $item['priority_order']
            ];
            $groupedBreakdown[$cat]['category_total'] += (float)$item['remaining_amount'];
        }

        // Consistency Check
        if (round($breakdownSum, 2) !== round((float)$billing['remaining_balance'], 2) && count($items) > 0) {
            // Consistency error
            $breakdownResponse = [
                'has_error' => true,
                'error_message' => "Internal Consistency Error: Sum of unpaid fees (₱" . number_format($breakdownSum, 2) . ") does not match total billing balance (₱" . number_format($billing['remaining_balance'], 2) . ").",
                'categories' => []
            ];
            error_log("Billing Consistency Error: Student {$student_number}, Billing ID {$billing['billing_id']}");
        } else {
            $breakdownResponse = [
                'has_error' => false,
                'error_message' => null,
                'categories' => array_values($groupedBreakdown)
            ];
        }

        echo json_encode([
            'success' => true,
            'billing_id' => $billing['billing_id'],
            'student_id' => $student_id,
            'name' => trim($student['full_name'] ?? 'Unknown Student'),
            'student_number' => $student['student_number'],
            'course_year' => $course_str,
            'billing_type' => $billing['billing_type'],
            'total_amount' => (float) $billing['total_amount'],
            'balance' => (float) $billing['remaining_balance'],
            'breakdown' => $breakdownResponse
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active billing found for this student. Please create one in Billing & Invoicing.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'INTERNAL_SERVER_ERROR', 'message' => 'An unexpected error occurred.']);
}
?>
