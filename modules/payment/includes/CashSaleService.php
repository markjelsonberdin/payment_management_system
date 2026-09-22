<?php

require_once __DIR__ . '/OfficialReceiptService.php';

/** Handles direct school-goods cash sales without touching academic billing. */
class CashSaleService
{
    public function __construct(private PDO $pdo) {}

    public function activeItems(): array
    {
        return $this->pdo->query(
            "SELECT i.sale_item_id, i.item_name, i.unit_price, c.sale_category_id, c.category_name
             FROM school_sale_items i
             JOIN school_sale_categories c ON c.sale_category_id = i.sale_category_id
             WHERE i.status = 'Active' AND c.status = 'Active'
             ORDER BY c.sort_order, c.category_name, i.item_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int,array{sale_item_id:mixed,quantity:mixed}> $items */
    public function create(int $studentId, int $cashierId, array $items, float $cashReceived, string $remarks = ''): array
    {
        if ($studentId <= 0 || $cashierId <= 0 || !$items) {
            throw new InvalidArgumentException('Student and at least one sale item are required.');
        }

        $this->pdo->beginTransaction();
        try {
            $student = $this->pdo->prepare(
                "SELECT s.student_id, s.year_level,
                        (SELECT b.academic_year FROM billing b WHERE b.student_id = s.student_id ORDER BY b.created_at DESC LIMIT 1) AS academic_year
                 FROM students s WHERE s.student_id = ? FOR UPDATE"
            );
            $student->execute([$studentId]);
            $student = $student->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new RuntimeException('Student record not found.');
            }

            $requested = [];
            foreach ($items as $item) {
                $id = (int) ($item['sale_item_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($id <= 0 || $quantity <= 0 || $quantity > 100) {
                    throw new InvalidArgumentException('Each sale item needs a valid quantity.');
                }
                $requested[$id] = ($requested[$id] ?? 0) + $quantity;
            }

            $placeholders = implode(',', array_fill(0, count($requested), '?'));
            $itemStmt = $this->pdo->prepare(
                "SELECT i.sale_item_id, i.item_name, i.unit_price, c.sale_category_id, c.category_name
                 FROM school_sale_items i
                 JOIN school_sale_categories c ON c.sale_category_id = i.sale_category_id
                 WHERE i.status = 'Active' AND c.status = 'Active'
                   AND i.sale_item_id IN ({$placeholders}) FOR UPDATE"
            );
            $itemStmt->execute(array_keys($requested));
            $catalog = [];
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $catalog[(int) $row['sale_item_id']] = $row;
            }
            if (count($catalog) !== count($requested)) {
                throw new RuntimeException('One or more selected school-sale items are no longer active.');
            }

            $lines = [];
            $total = 0.0;
            foreach ($requested as $id => $quantity) {
                $row = $catalog[$id];
                $lineTotal = round((float) $row['unit_price'] * $quantity, 2);
                $total += $lineTotal;
                $lines[] = [$row, $quantity, $lineTotal];
            }
            $total = round($total, 2);
            if ($cashReceived < $total) {
                throw new RuntimeException('Cash received cannot be less than the sale total.');
            }

            $receipt = (new OfficialReceiptService($this->pdo))->reserve();
            $sale = $this->pdo->prepare(
                'INSERT INTO cash_sales (student_id, cashier_id, receipt_number, academic_year_snapshot, year_level_snapshot, total_amount, cash_received, change_amount, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $sale->execute([
                $studentId, $cashierId, $receipt, $student['academic_year'] ?? null,
                $student['year_level'] ?? null, $total, $cashReceived, round($cashReceived - $total, 2), $remarks ?: null,
            ]);
            $saleId = (int) $this->pdo->lastInsertId();

            $lineStmt = $this->pdo->prepare(
                'INSERT INTO cash_sale_items (cash_sale_id, sale_item_id, sale_category_id, category_name_snapshot, item_name_snapshot, unit_price_snapshot, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as [$row, $quantity, $lineTotal]) {
                $lineStmt->execute([$saleId, $row['sale_item_id'], $row['sale_category_id'], $row['category_name'], $row['item_name'], $row['unit_price'], $quantity, $lineTotal]);
            }
            $this->pdo->commit();
            return ['cash_sale_id' => $saleId, 'receipt_number' => $receipt, 'total' => $total, 'change' => round($cashReceived - $total, 2)];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
