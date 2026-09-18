<?php
declare(strict_types=1);

/** Writes idempotent student-facing payment success notices without affecting payment posting. */
final class PaymentNotificationService
{
    public function __construct(private PDO $pdo) {}

    public function notifyVerifiedPayment(int $studentId, int $paymentId, float $amount, string $channel, string $reference): void
    {
        try {
            if ($studentId <= 0 || $paymentId <= 0) {
                return;
            }
            $student = $this->pdo->prepare('SELECT user_id FROM students WHERE student_id = ? LIMIT 1');
            $student->execute([$studentId]);
            $userId = (int) $student->fetchColumn();
            if ($userId <= 0) {
                return;
            }
            $channel = trim($channel) ?: 'Payment';
            $reference = trim($reference) ?: ('Payment #' . $paymentId);
            $body = sprintf('%s payment of PHP %s was successfully posted. Reference: %s.', $channel, number_format($amount, 2), $reference);
            $notice = $this->pdo->prepare('INSERT IGNORE INTO payment_notifications (recipient_user_id, event_key, title, body, target_url) VALUES (?, ?, ?, ?, ?)');
            $notice->execute([$userId, 'payment-success:' . $paymentId, 'Payment Successful', $body, '/modules/student-portal/pages/statement-of-account.php']);
        } catch (Throwable $e) {
            error_log('Student payment notification failed: ' . $e->getMessage());
        }
    }
}
