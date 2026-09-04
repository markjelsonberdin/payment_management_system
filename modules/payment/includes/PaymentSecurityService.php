<?php
/**
 * SMS 2 - Payment Security Coordinator
 * 
 * Centralized security service for the Payment Module.
 * Extends global authentication, RBAC, and CSRF with payment-specific object
 * ownership, context validation, and state validation.
 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/authentication.php';

class PaymentSecurityService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Enforces Role/Permission AND Object Ownership/Scope.
     * 
     * @param int $userId Authenticated user ID
     * @param string $role Authenticated user role key
     * @param string $permission The specific permission required (e.g. 'payment.ocr.scan')
     * @param int $objectId The ID of the object being accessed
     * @param string $objectType 'student', 'billing', 'payment', 'concern'
     * @return bool True if authorized, throws Exception if not.
     */
    public function ensurePaymentAccess(int $userId, string $role, string $permission, int $objectId, string $objectType): bool {
        // 1. Enforce global permission (Admin/Accounting/Cashier must have the granular permission)
        if ($role !== 'student') {
            // userCanAccessModule checks against top-level module slugs (e.g. 'payment'),
            // not dotted sub-permissions like 'payment.ocr.scan'.
            // Extract the top-level key for the check.
            $topLevelModule = explode('.', $permission)[0];
            if (!userCanAccessModule($topLevelModule)) {
                throw new Exception("Access Denied: Missing required permission '$permission'.");
            }
        }

        // 2. Enforce object-level scope
        if ($role === 'student') {
            return $this->validateStudentOwnership($userId, $objectId, $objectType);
        }

        // 3. For Cashier/Accounting, if they have the permission, they can access the object 
        // within the system, but we can add further constraints here if needed in the future.
        return true;
    }

    /**
     * Validates that the given object belongs to the currently logged in student.
     */
    private function validateStudentOwnership(int $userId, int $objectId, string $objectType): bool {
        // Find the student_id for the logged in user
        $stmt = $this->pdo->prepare("SELECT student_id FROM students WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $studentId = $stmt->fetchColumn();

        if (!$studentId) {
            throw new Exception("Access Denied: User is not linked to a student profile.");
        }

        $isValid = false;

        switch ($objectType) {
            case 'billing':
                $stmt = $this->pdo->prepare("SELECT 1 FROM student_billing WHERE billing_id = ? AND student_id = ?");
                $stmt->execute([$objectId, $studentId]);
                $isValid = (bool) $stmt->fetchColumn();
                break;
            case 'payment':
                $stmt = $this->pdo->prepare("SELECT 1 FROM payments WHERE payment_id = ? AND student_id = ?");
                $stmt->execute([$objectId, $studentId]);
                $isValid = (bool) $stmt->fetchColumn();
                break;
            case 'concern':
                $stmt = $this->pdo->prepare("SELECT 1 FROM payment_concerns WHERE concern_id = ? AND student_id = ?");
                $stmt->execute([$objectId, $studentId]);
                $isValid = (bool) $stmt->fetchColumn();
                break;
            case 'student':
                $isValid = ($objectId == $studentId);
                break;
            default:
                throw new Exception("Unknown object type: $objectType");
        }

        if (!$isValid) {
            throw new Exception("Access Denied: You do not have ownership of this $objectType record.");
        }

        return true;
    }

    /**
     * Validates the context of a payment (e.g. checking if the amount is valid for the context)
     */
    public function validatePaymentContext(string $context, float $amount, float $outstandingBalance, array $billingData = [], ?int $itemId = null, ?int $categoryId = null): bool {
        if ($amount <= 0) {
            throw new Exception("Invalid Payment Amount: Must be greater than zero.");
        }

        if ($amount > $outstandingBalance && $context !== 'ENROLLMENT_PRIORITY') {
            throw new Exception("Invalid Payment Amount: Amount cannot exceed the outstanding balance.");
        }

        switch ($context) {
            case 'ENROLLMENT_PRIORITY':
                if (empty($billingData) || !isset($billingData['billing_type']) || $billingData['billing_type'] !== 'Enrollment') {
                    throw new Exception("Invalid Context: Cannot apply Enrollment Priority Mode to a non-enrollment billing.");
                }
                break;
            case 'GENERAL_PRIORITY':
                if ($outstandingBalance <= 0) {
                    throw new Exception("Invalid Context: Cannot apply General Priority to a fully paid billing.");
                }
                break;
            case 'SPECIFIC_ITEM':
                if (!$itemId) {
                    throw new Exception("Invalid Context: Specific item must be provided for SPECIFIC_ITEM context.");
                }
                $billingId = !empty($billingData['billing_id']) ? (int)$billingData['billing_id'] : 0;
                $sql = "SELECT remaining_amount FROM billing_items WHERE billing_item_id = ?";
                $params = [$itemId];
                if ($billingId > 0) {
                    $sql .= " AND billing_id = ?";
                    $params[] = $billingId;
                }
                $stmtItem = $this->pdo->prepare($sql);
                $stmtItem->execute($params);
                $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
                if (!$item || (float)$item['remaining_amount'] <= 0) {
                    throw new Exception("Invalid Context: The selected item is either not payable or does not exist.");
                }
                if ($amount > (float)$item['remaining_amount']) {
                    throw new Exception("Invalid Payment Amount: Amount (₱" . number_format($amount, 2) . ") exceeds the item's outstanding balance (₱" . number_format((float)$item['remaining_amount'], 2) . ").");
                }
                break;
            case 'CATEGORY_PRIORITY':
                if (!$categoryId) {
                    throw new Exception("Invalid Context: Category must be provided for CATEGORY_PRIORITY context.");
                }
                $billingId = !empty($billingData['billing_id']) ? (int)$billingData['billing_id'] : 0;
                $sql = "SELECT SUM(bi.remaining_amount) as cat_bal 
                        FROM billing_items bi 
                        JOIN fees f ON bi.fee_id = f.fee_id 
                        WHERE f.category_id = ? AND bi.status != 'Paid' AND bi.remaining_amount > 0";
                $params = [$categoryId];
                if ($billingId > 0) {
                    $sql .= " AND bi.billing_id = ?";
                    $params[] = $billingId;
                }
                $stmtCat = $this->pdo->prepare($sql);
                $stmtCat->execute($params);
                $catBal = $stmtCat->fetchColumn();
                if (!$catBal || (float)$catBal <= 0) {
                    throw new Exception("Invalid Context: The selected category has no outstanding balance.");
                }
                if ($amount > (float)$catBal) {
                    throw new Exception("Invalid Payment Amount: Amount (₱" . number_format($amount, 2) . ") exceeds the selected category's outstanding balance (₱" . number_format((float)$catBal, 2) . ").");
                }
                break;
            default:
                throw new Exception("Invalid Allocation Context: The provided context '$context' is not recognized.");
        }

        return true;
    }

    /**
     * Validates the state of a payment/billing before allowing processing.
     */
    public function validatePaymentState(string $currentState, array $allowedStates, string $entity = "Record"): bool {
        if (!in_array($currentState, $allowedStates)) {
            throw new Exception("Invalid State: $entity is currently '$currentState', expected one of: " . implode(', ', $allowedStates));
        }
        return true;
    }
}
