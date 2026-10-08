<?php
declare(strict_types=1);

/**
 * Canonical allocation boundary shared by Cashier, live PayMongo QR Ph, and
 * Accounting Officer-approved Payment Concerns.
 */
final class PaymentAllocationService
{
    public const TUITION_CATEGORY_ID = 1;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{payment_id:int,allocated_amount:string,idempotent_replay:bool}
     */
    public function allocatePayment(
        int $paymentId,
        int $studentId,
        int $billingId,
        float $amountPaid,
        string $allocationContext = 'ENROLLMENT_PRIORITY',
        ?int $billingItemId = null
    ): array {
        $ownsTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $ownsTransaction = true;
            }

            $lock = $this->lockClause();
            $paymentStmt = $this->pdo->prepare(
                "SELECT payment_id, student_id, billing_id, transaction_type, payment_method,
                        payment_channel, gateway_environment, payment_intent_id, payment_status,
                        verified_by, verified_at, amount
                 FROM payments WHERE payment_id = :payment_id{$lock}"
            );
            $paymentStmt->execute([':payment_id' => $paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) throw new RuntimeException('Payment record not found.');
            if ((int) $payment['student_id'] !== $studentId || (int) $payment['billing_id'] !== $billingId) {
                throw new RuntimeException('Payment ownership or billing association does not match.');
            }
            if ((string) $payment['payment_status'] !== 'Verified' || empty($payment['verified_at'])) {
                throw new RuntimeException('Only a verified payment can be allocated.');
            }

            $paymentCents = $this->toCentavos($payment['amount'], 'Payment amount');
            $requestedCents = $this->toCentavos($amountPaid, 'Allocation amount');
            if ($paymentCents !== $requestedCents) {
                throw new RuntimeException('Allocation request must equal the verified payment amount.');
            }
            $this->assertEligibleSource($payment);

            $billingStmt = $this->pdo->prepare(
                "SELECT billing_id, student_id FROM billing WHERE billing_id = :billing_id{$lock}"
            );
            $billingStmt->execute([':billing_id' => $billingId]);
            $billing = $billingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$billing) throw new RuntimeException('Billing record not found.');
            if ((int) $billing['student_id'] !== $studentId) {
                throw new RuntimeException('Billing does not belong to the payment student.');
            }

            $existingStmt = $this->pdo->prepare(
                "SELECT pa.billing_item_id, pa.allocated_amount, bi.billing_id
                 FROM payment_allocations pa
                 JOIN billing_items bi ON bi.billing_item_id = pa.billing_item_id
                 WHERE pa.payment_id = :payment_id
                 ORDER BY pa.allocation_id{$lock}"
            );
            $existingStmt->execute([':payment_id' => $paymentId]);
            $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            $alreadyAllocatedCents = 0;
            foreach ($existing as $allocation) {
                if ((int) $allocation['billing_id'] !== $billingId) {
                    throw new RuntimeException('Existing allocation belongs to another billing record.');
                }
                $alreadyAllocatedCents += $this->toCentavos($allocation['allocated_amount'], 'Existing allocation');
            }
            if ($alreadyAllocatedCents > $paymentCents) {
                throw new RuntimeException('Existing allocations exceed the verified payment amount.');
            }
            if ($alreadyAllocatedCents === $paymentCents) {
                if ($ownsTransaction) $this->pdo->commit();
                return [
                    'payment_id' => $paymentId,
                    'allocated_amount' => $this->fromCentavos($paymentCents),
                    'idempotent_replay' => true,
                ];
            }
            if ($alreadyAllocatedCents !== 0) {
                throw new RuntimeException('Partially allocated payment requires financial reconciliation.');
            }

            [$sql, $params] = $this->eligibleItemQuery(
                $allocationContext,
                $billingId,
                $billingItemId,
                (string) $payment['transaction_type'],
                $lock
            );
            $itemStmt = $this->pdo->prepare($sql);
            $itemStmt->execute($params);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($items === []) throw new RuntimeException('No eligible billing items found for allocation.');

            $totalPayableCents = 0;
            foreach ($items as $item) {
                $itemCents = $this->toCentavos($item['remaining_amount'], 'Billing item remaining amount');
                if ($itemCents <= 0) throw new RuntimeException('Allocation target has no positive remaining balance.');
                $totalPayableCents += $itemCents;
            }
            if ($paymentCents > $totalPayableCents) {
                throw new RuntimeException('Verified payment exceeds the payable balance in the selected scope.');
            }

            $remainingCents = $paymentCents;
            $insert = $this->pdo->prepare(
                'INSERT INTO payment_allocations (payment_id, billing_item_id, allocated_amount)
                 VALUES (:payment_id, :billing_item_id, :allocated_amount)'
            );
            foreach ($items as $item) {
                if ($remainingCents === 0) break;
                $itemCents = $this->toCentavos($item['remaining_amount'], 'Billing item remaining amount');
                $allocationCents = min($remainingCents, $itemCents);
                if ($allocationCents <= 0) throw new RuntimeException('Allocation amount must be positive.');
                try {
                    $insert->execute([
                        ':payment_id' => $paymentId,
                        ':billing_item_id' => (int) $item['billing_item_id'],
                        ':allocated_amount' => $this->fromCentavos($allocationCents),
                    ]);
                } catch (PDOException $exception) {
                    if ($this->isUniqueViolation($exception)) {
                        throw new RuntimeException('Duplicate payment-item allocation detected.', 0, $exception);
                    }
                    throw $exception;
                }
                $remainingCents -= $allocationCents;
            }
            if ($remainingCents !== 0) {
                throw new RuntimeException('Payment value could not be fully allocated; no unapplied-credit policy exists.');
            }

            $this->updateBillingSummary($billingId);
            if ($ownsTransaction) $this->pdo->commit();
            return [
                'payment_id' => $paymentId,
                'allocated_amount' => $this->fromCentavos($paymentCents),
                'idempotent_replay' => false,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payment */
    private function assertEligibleSource(array $payment): void
    {
        $type = (string) $payment['transaction_type'];
        if ($type === 'Online') {
            if ((string) ($payment['gateway_environment'] ?? '') !== 'live'
                || strcasecmp((string) ($payment['payment_channel'] ?? ''), 'QRPh') !== 0
                || (string) ($payment['payment_method'] ?? '') !== 'Online'
                || trim((string) ($payment['payment_intent_id'] ?? '')) === '') {
                throw new RuntimeException('Online payment is not an authorized live QR Ph settlement.');
            }
            return;
        }
        if ($type === 'Walk-in') {
            if (strcasecmp((string) ($payment['payment_channel'] ?? ''), 'Cash') !== 0
                || (string) ($payment['payment_method'] ?? '') !== 'Walk-in'
                || empty($payment['verified_by'])) {
                throw new RuntimeException('Walk-in payment lacks verified Cashier provenance.');
            }
            return;
        }
        if ($type === 'Payment Concern') {
            if (empty($payment['verified_by'])) {
                throw new RuntimeException('Payment Concern lacks Accounting Officer verification.');
            }
            return;
        }
        throw new RuntimeException('Payment source is not eligible for allocation.');
    }

    /** @return array{0:string,1:array<string,int>} */
    private function eligibleItemQuery(
        string $context,
        int $billingId,
        ?int $targetId,
        string $transactionType,
        string $lock
    ): array {
        if ($context === 'GENERAL_PRIORITY') {
            return [
                "SELECT bi.billing_item_id, bi.remaining_amount
                 FROM billing_items bi
                 JOIN fees f ON bi.fee_id = f.fee_id
                 JOIN fee_categories fc ON f.category_id = fc.category_id
                 WHERE bi.billing_id = :billing_id AND bi.status != 'Paid' AND bi.remaining_amount > 0
                 ORDER BY fc.priority_order, bi.billing_item_id{$lock}",
                [':billing_id' => $billingId],
            ];
        }
        if ($context === 'ENROLLMENT_PRIORITY') {
            return [
                "SELECT bi.billing_item_id, bi.remaining_amount
                 FROM billing_items bi
                 JOIN fees f ON bi.fee_id = f.fee_id
                 JOIN fee_categories fc ON f.category_id = fc.category_id
                 WHERE bi.billing_id = :billing_id
                   AND bi.source_context IN ('Enrollment Assessment', 'Enrollment')
                   AND bi.status != 'Paid' AND bi.remaining_amount > 0
                   AND fc.category_id != :tuition_category_id
                 ORDER BY fc.priority_order, bi.billing_item_id{$lock}",
                [':billing_id' => $billingId, ':tuition_category_id' => self::TUITION_CATEGORY_ID],
            ];
        }
        if ($context === 'SPECIFIC_ITEM') {
            if (!$targetId) throw new RuntimeException('Billing item ID is required for specific-item allocation.');
            $onlineFilter = $transactionType === 'Online'
                ? ' AND f.category_id IS NOT NULL AND f.category_id != :tuition_category_id'
                : '';
            $params = [':billing_id' => $billingId, ':target_id' => $targetId];
            if ($transactionType === 'Online') $params[':tuition_category_id'] = self::TUITION_CATEGORY_ID;
            return [
                "SELECT bi.billing_item_id, bi.remaining_amount
                 FROM billing_items bi JOIN fees f ON bi.fee_id = f.fee_id
                 WHERE bi.billing_id = :billing_id AND bi.billing_item_id = :target_id
                   AND bi.status != 'Paid' AND bi.remaining_amount > 0{$onlineFilter}{$lock}",
                $params,
            ];
        }
        if ($context === 'CATEGORY_PRIORITY') {
            if (!$targetId) throw new RuntimeException('Category ID is required for category-priority allocation.');
            return [
                "SELECT bi.billing_item_id, bi.remaining_amount
                 FROM billing_items bi JOIN fees f ON bi.fee_id = f.fee_id
                 WHERE bi.billing_id = :billing_id AND f.category_id = :target_id
                   AND bi.status != 'Paid' AND bi.remaining_amount > 0
                 ORDER BY bi.billing_item_id{$lock}",
                [':billing_id' => $billingId, ':target_id' => $targetId],
            ];
        }
        throw new RuntimeException('Invalid payment allocation context.');
    }

    private function updateBillingSummary(int $billingId): void
    {
        $remaining = $this->pdo->prepare(
            'SELECT COALESCE(SUM(remaining_amount), 0) FROM billing_items WHERE billing_id = :billing_id'
        );
        $remaining->execute([':billing_id' => $billingId]);
        $remainingCents = $this->toCentavos($remaining->fetchColumn(), 'Billing remaining amount', true);

        $original = $this->pdo->prepare(
            'SELECT total_amount, discount_amount FROM billing WHERE billing_id = :billing_id'
        );
        $original->execute([':billing_id' => $billingId]);
        $billing = $original->fetch(PDO::FETCH_ASSOC);
        if (!$billing) throw new RuntimeException('Billing record disappeared during allocation.');
        $totalCents = $this->toCentavos($billing['total_amount'], 'Billing total', true);
        $discountCents = $this->toCentavos($billing['discount_amount'], 'Billing discount', true);
        $actualRemainingCents = max(0, $remainingCents - $discountCents);
        $netPayableCents = max(0, $totalCents - $discountCents);
        $status = $actualRemainingCents === 0 ? 'Paid'
            : ($actualRemainingCents < $netPayableCents ? 'Partial' : 'Unpaid');

        $update = $this->pdo->prepare(
            'UPDATE billing SET remaining_balance = :balance, billing_status = :status,
             updated_at = CURRENT_TIMESTAMP WHERE billing_id = :billing_id'
        );
        $update->execute([
            ':balance' => $this->fromCentavos($actualRemainingCents),
            ':status' => $status,
            ':billing_id' => $billingId,
        ]);
    }

    private function toCentavos(mixed $value, string $label, bool $allowZero = false): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException($label . ' is invalid.');
        }
        if (is_float($value)) {
            if (!is_finite($value) || abs($value - round($value, 2)) > 0.000001) {
                throw new RuntimeException($label . ' must use at most two decimal places.');
            }
            $normalized = number_format($value, 2, '.', '');
        } else {
            $normalized = trim((string) $value);
        }
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $normalized, $matches)) {
            throw new RuntimeException($label . ' must be a nonnegative decimal with at most two places.');
        }
        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents < 0 || (!$allowZero && $cents === 0)) {
            throw new RuntimeException($label . ' must be positive.');
        }
        return $cents;
    }

    private function fromCentavos(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function lockClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return str_contains(strtolower($exception->getMessage()), 'unique constraint failed');
        }
        return isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062;
    }
}
