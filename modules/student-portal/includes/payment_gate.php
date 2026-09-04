<?php
/**
 * SMS 2 - Centralized Payment Gate Helper
 */

function requireResearchPaymentGate() {
    // ── Research Forum payment gate ──────────────────────────────────────────────
    // Check if student has paid the Research Forum fee before allowing access.
    // In production, query the actual payment table.
    $_gatePayments = [
        ['description' => 'Tuition Down Payment',  'status' => 'Paid'],
        ['description' => 'Registration Fee',       'status' => 'Paid'],
        ['description' => 'Laboratory Fee',         'status' => 'Paid'],
        ['description' => 'Research Forum',         'status' => 'Paid'],
    ];
    $_researchForumPaid = false;
    foreach ($_gatePayments as $_txn) {
        if (
            stripos($_txn['description'], 'Research Forum') !== false &&
            strtolower($_txn['status']) === 'paid'
        ) {
            $_researchForumPaid = true;
            break;
        }
    }
    
    if (!$_researchForumPaid) {
        // Enforce block if not paid
        header('Location: ' . BASE_URL . '/modules/student-portal/pages/payment-history.php?notice=research-forum-required');
        exit;
    }
}
