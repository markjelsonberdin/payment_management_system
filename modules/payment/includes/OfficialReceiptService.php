<?php

/** Allocates immutable, database-backed official receipt numbers. */
class OfficialReceiptService
{
    public function __construct(private PDO $pdo) {}

    /** Must be called inside the caller's open transaction. */
    public function reserve(): string
    {
        $key = 'OR-' . date('Y');
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO official_receipt_sequences (sequence_key, next_number) VALUES (?, 1)'
        );
        $insert->execute([$key]);

        $lock = $this->pdo->prepare(
            'SELECT next_number FROM official_receipt_sequences WHERE sequence_key = ? FOR UPDATE'
        );
        $lock->execute([$key]);
        $number = (int) $lock->fetchColumn();
        if ($number < 1) {
            throw new RuntimeException('Unable to reserve an official receipt number.');
        }

        $update = $this->pdo->prepare(
            'UPDATE official_receipt_sequences SET next_number = ? WHERE sequence_key = ?'
        );
        $update->execute([$number + 1, $key]);

        return sprintf('%s-%06d', $key, $number);
    }
}
