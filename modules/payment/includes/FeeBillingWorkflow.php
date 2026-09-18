<?php
declare(strict_types=1);

require_once __DIR__ . '/BillingService.php';

/** Coordinates Accounting-owned bulk billing; BillingService remains the only billing writer. */
final class FeeBillingWorkflow
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function available(): bool
    {
        try {
            foreach (['fee_billing_campaigns', 'fee_billing_assignments', 'payment_notifications'] as $table) {
                $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                if (!$stmt->fetchColumn()) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function generationEnabled(): bool
    {
        return $this->available() && getenv('PAYMENT_BULK_BILLING_ENABLED') === '1';
    }

    public function submit(int $feeId, string $year, string $semester, ?string $course, ?string $level, int $actor): int
    {
        if (!$this->available()) {
            throw new RuntimeException('Fee billing workflow migration is not installed.');
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
            throw new InvalidArgumentException('Invalid academic year or semester.');
        }
        $course = trim((string) $course) ?: null;
        $level = trim((string) $level) ?: null;
        if (strlen((string) $course) > 100 || strlen((string) $level) > 20) {
            throw new InvalidArgumentException('Cohort criterion is too long.');
        }
        $this->pdo->beginTransaction();
        try {
            $feeStmt = $this->pdo->prepare("SELECT fee_id, fee_name, default_amount FROM fees WHERE fee_id = ? AND status = 'Active' FOR UPDATE");
            $feeStmt->execute([$feeId]);
            $fee = $feeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee || (float) $fee['default_amount'] <= 0) {
                throw new RuntimeException('Active fee with a positive amount required.');
            }
            if (!in_array($level, ['1', '2', '3', '4'], true)) {
                throw new InvalidArgumentException('Choose one target year level.');
            }
            $existingStmt = $this->pdo->prepare('SELECT campaign_id, status FROM fee_billing_campaigns WHERE fee_id = ? AND academic_year = ? AND semester = ? AND year_level <=> ? FOR UPDATE');
            $existingStmt->execute([$feeId, $year, $semester, $level]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['status'] !== 'Returned') {
                    throw new RuntimeException('This fee already has a bulk billing run for the selected year level and term.');
                }
                $stmt = $this->pdo->prepare("UPDATE fee_billing_campaigns SET course = ?, year_level = ?, fee_name_snapshot = ?, amount_snapshot = ?, status = 'Submitted', version = version + 1, submitted_by = ?, submitted_at = NOW(), approved_by = NULL, approved_at = NULL WHERE campaign_id = ?");
                $stmt->execute([$course, $level, $fee['fee_name'], $fee['default_amount'], $actor, $existing['campaign_id']]);
                $campaignId = (int) $existing['campaign_id'];
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO fee_billing_campaigns (fee_id, academic_year, semester, course, year_level, fee_name_snapshot, amount_snapshot, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$feeId, $year, $semester, $course, $level, $fee['fee_name'], $fee['default_amount'], $actor]);
                $campaignId = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
            return $campaignId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function get(int $campaignId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, f.status AS fee_status, f.default_amount AS current_amount, f.fee_name AS current_name FROM fee_billing_campaigns c JOIN fees f ON f.fee_id = c.fee_id WHERE c.campaign_id = ?');
        $stmt->execute([$campaignId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function list(): array
    {
        return $this->pdo->query('SELECT c.*, f.status AS fee_status FROM fee_billing_campaigns c JOIN fees f ON f.fee_id = c.fee_id ORDER BY c.submitted_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function eligibleWhere(array $campaign, array &$params): string
    {
        // Bulk billing applies only to locally marked Enrolled students in the
        // Accounting-selected year level.
        $where = ["s.status = 'Enrolled'"];
        if ($campaign['course'] !== null) {
            $where[] = 's.course = :course';
            $params[':course'] = $campaign['course'];
        }
        if ($campaign['year_level'] !== null) {
            $where[] = 's.year_level = :year_level';
            $params[':year_level'] = $campaign['year_level'];
        }
        return implode(' AND ', $where);
    }

    public function preview(array $campaign, int $limit = 25, int $offset = 0): array
    {
        $params = [];
        $where = $this->eligibleWhere($campaign, $params);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM students s WHERE {$where}");
        $count->execute($params);
        $eligible = (int) $count->fetchColumn();
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
        $unresolved = 0;
        $existingSql = "SELECT COUNT(DISTINCT s.student_id) FROM students s JOIN billing b ON b.student_id = s.student_id AND b.academic_year = :ay AND b.semester = :sem JOIN billing_items bi ON bi.billing_id = b.billing_id AND bi.fee_id = :fee WHERE {$where}";
        $existingStmt = $this->pdo->prepare($existingSql);
        $existingStmt->execute(array_merge($params, [':ay' => $campaign['academic_year'], ':sem' => $campaign['semester'], ':fee' => $campaign['fee_id']]));
        $existing = (int) $existingStmt->fetchColumn();
        $list = $this->pdo->prepare("SELECT s.student_id, s.student_number, s.full_name, s.course, s.year_level FROM students s WHERE {$where} ORDER BY s.student_id LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $list->bindValue($key, $value);
        }
        $list->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $list->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $list->execute();
        return [
            'eligible' => $eligible,
            'excluded' => max(0, $total - $eligible - $unresolved),
            'unresolved' => $unresolved,
            'already_assigned' => $existing,
            'projected_amount' => max(0, $eligible - $existing) * (float) $campaign['amount_snapshot'],
            'students' => $list->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function start(int $campaignId, int $actor): bool
    {
        if (!$this->generationEnabled()) {
            throw new RuntimeException('Bulk generation is disabled until student cohort data and deployment are verified.');
        }
        $campaign = $this->get($campaignId);
        if (!$campaign || $campaign['fee_status'] !== 'Active' || $campaign['current_name'] !== $campaign['fee_name_snapshot'] || (float) $campaign['current_amount'] !== (float) $campaign['amount_snapshot']) {
            throw new RuntimeException('Fee changed or is inactive. Refresh the active fee list before generating billing.');
        }
        $stmt = $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Running', approved_by = ?, approved_at = NOW() WHERE campaign_id = ? AND status = 'Submitted'");
        $stmt->execute([$actor, $campaignId]);
        return $stmt->rowCount() === 1;
    }

    public function runChunk(int $campaignId, int $actor, int $chunkSize = 25): array
    {
        if (!$this->generationEnabled()) {
            throw new RuntimeException('Bulk generation is disabled.');
        }
        $token = bin2hex(random_bytes(16));
        $lease = $this->pdo->prepare("UPDATE fee_billing_campaigns SET runner_token = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE campaign_id = ? AND status = 'Running' AND (runner_token IS NULL OR lease_expires_at < NOW())");
        $lease->execute([$token, $campaignId]);
        if ($lease->rowCount() !== 1) {
            throw new RuntimeException('Run is complete or another worker is processing it.');
        }
        try {
            $campaign = $this->get($campaignId);
            if (!$campaign || $campaign['fee_status'] !== 'Active' || $campaign['current_name'] !== $campaign['fee_name_snapshot'] || (float) $campaign['current_amount'] !== (float) $campaign['amount_snapshot']) {
                throw new RuntimeException('Fee changed during the run; generation stopped.');
            }
            $params = [':cursor' => (int) $campaign['cursor_student_id']];
            $where = $this->eligibleWhere($campaign, $params);
            $query = $this->pdo->prepare("SELECT s.student_id, s.user_id FROM students s WHERE s.student_id > :cursor AND {$where} ORDER BY s.student_id LIMIT :limit");
            foreach ($params as $key => $value) {
                $query->bindValue($key, $value, $key === ':cursor' ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $query->bindValue(':limit', max(1, min(50, $chunkSize)), PDO::PARAM_INT);
            $query->execute();
            $students = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($students as $student) {
                $studentId = (int) $student['student_id'];
                try {
                    $this->assign($campaign, $studentId, (int) ($student['user_id'] ?? 0), $actor);
                } catch (Throwable $e) {
                    $this->record($campaign, $studentId, null, 'Failed', substr($e->getMessage(), 0, 500), 0);
                }
                $this->pdo->prepare('UPDATE fee_billing_campaigns SET cursor_student_id = ? WHERE campaign_id = ? AND runner_token = ?')->execute([$studentId, $campaignId, $token]);
            }
            if (count($students) < max(1, min(50, $chunkSize))) {
                $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Completed' WHERE campaign_id = ? AND runner_token = ?")->execute([$campaignId, $token]);
            }
            return $this->get($campaignId) ?? [];
        } finally {
            $this->pdo->prepare('UPDATE fee_billing_campaigns SET runner_token = NULL, lease_expires_at = NULL WHERE campaign_id = ? AND runner_token = ?')->execute([$campaignId, $token]);
        }
    }

    public function retryFailures(int $campaignId): bool
    {
        if (!$this->generationEnabled()) {
            throw new RuntimeException('Bulk generation is disabled.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT status, failed_count, runner_token FROM fee_billing_campaigns WHERE campaign_id = ? FOR UPDATE");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign || $campaign['status'] !== 'Completed' || (int) $campaign['failed_count'] === 0 || $campaign['runner_token'] !== null) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->prepare("DELETE FROM fee_billing_assignments WHERE campaign_id = ? AND outcome = 'Failed'")->execute([$campaignId]);
            $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Running', cursor_student_id = 0, failed_count = 0, last_error = NULL WHERE campaign_id = ?")->execute([$campaignId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function assign(array $campaign, int $studentId, int $userId, int $actor): void
    {
        $eligibilityParams = [':student_id' => $studentId];
        $eligibilityWhere = $this->eligibleWhere($campaign, $eligibilityParams);
        $eligibility = $this->pdo->prepare("SELECT 1 FROM students s WHERE s.student_id = :student_id AND {$eligibilityWhere}");
        $eligibility->execute($eligibilityParams);
        if (!$eligibility->fetchColumn()) {
            $this->record($campaign, $studentId, null, 'Skipped', 'Eligibility changed before assignment', 0);
            return;
        }
        $check = $this->pdo->prepare('SELECT assignment_id FROM fee_billing_assignments WHERE campaign_id = ? AND student_id = ?');
        $check->execute([$campaign['campaign_id'], $studentId]);
        if ($check->fetchColumn()) {
            return;
        }
        $term = $this->pdo->prepare('SELECT b.billing_id, bi.billing_item_id, bi.source_context FROM billing b JOIN billing_items bi ON bi.billing_id = b.billing_id WHERE b.student_id = ? AND b.academic_year = ? AND b.semester = ? AND bi.fee_id = ? ORDER BY bi.billing_item_id LIMIT 1');
        $term->execute([$studentId, $campaign['academic_year'], $campaign['semester'], $campaign['fee_id']]);
        $existingItem = $term->fetch(PDO::FETCH_ASSOC);
        $context = 'Bulk Campaign #' . $campaign['campaign_id'];
        if ($existingItem) {
            $own = $existingItem['source_context'] === $context;
            $this->record($campaign, $studentId, (int) $existingItem['billing_item_id'], $own ? 'Added' : 'Existing', $own ? 'Recovered after interrupted run' : 'Fee already assigned for term', $own ? $userId : 0);
            return;
        }
        $latest = $this->pdo->prepare('SELECT billing_id, academic_year, semester FROM billing WHERE student_id = ? ORDER BY billing_id DESC LIMIT 1');
        $latest->execute([$studentId]);
        $latestBilling = $latest->fetch(PDO::FETCH_ASSOC);
        if ($latestBilling && ($latestBilling['academic_year'] !== $campaign['academic_year'] || $latestBilling['semester'] !== $campaign['semester'])) {
            $this->record($campaign, $studentId, null, 'Skipped', 'Student latest SOA belongs to a different term; review required', 0);
            return;
        }
        $billingId = $latestBilling['billing_id'] ?? null;
        $writer = new BillingService($this->pdo);
        if ($billingId) {
            $result = $writer->appendFeesToBilling((int) $billingId, [(int) $campaign['fee_id']], $context, $actor);
            if (empty($result['added'])) {
                $this->record($campaign, $studentId, null, 'Existing', 'Fee already assigned', 0);
                return;
            }
        } else {
            $billingId = $writer->generateBilling($studentId, $campaign['academic_year'], $campaign['semester'], 'Assessment', [(int) $campaign['fee_id']], 0, $actor, $context);
        }
        $item = $this->pdo->prepare('SELECT billing_item_id FROM billing_items WHERE billing_id = ? AND fee_id = ? AND source_context = ? ORDER BY billing_item_id DESC LIMIT 1');
        $item->execute([(int) $billingId, $campaign['fee_id'], $context]);
        $itemId = (int) $item->fetchColumn();
        if ($itemId <= 0) {
            throw new RuntimeException('Billing item was not found after billing commit.');
        }
        $this->record($campaign, $studentId, $itemId, 'Added', null, $userId);
    }

    private function record(array $campaign, int $studentId, ?int $itemId, string $outcome, ?string $reason, int $notifyUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT IGNORE INTO fee_billing_assignments (campaign_id, student_id, billing_item_id, outcome, reason) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$campaign['campaign_id'], $studentId, $itemId, $outcome, $reason]);
            if ($insert->rowCount() === 1) {
                $column = ['Added' => 'added_count', 'Existing' => 'existing_count', 'Skipped' => 'skipped_count', 'Failed' => 'failed_count'][$outcome];
                $this->pdo->prepare("UPDATE fee_billing_campaigns SET {$column} = {$column} + 1 WHERE campaign_id = ?")->execute([$campaign['campaign_id']]);
                if ($outcome === 'Added' && $notifyUserId > 0 && $itemId !== null) {
                    $message = $campaign['fee_name_snapshot'] . ' - PHP ' . number_format((float) $campaign['amount_snapshot'], 2) . ' was added to your billing for AY ' . $campaign['academic_year'] . ', ' . $campaign['semester'] . '.';
                    $notice = $this->pdo->prepare('INSERT IGNORE INTO payment_notifications (recipient_user_id, event_key, title, body, target_url) VALUES (?, ?, ?, ?, ?)');
                    $notice->execute([$notifyUserId, 'billing-item:' . $itemId, 'New Billing Item', $message, '/modules/student-portal/pages/statement-of-account.php']);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
