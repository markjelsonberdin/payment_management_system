<?php
declare(strict_types=1);

require_once __DIR__ . '/BillingService.php';

/** Accounting-owned, category-based bulk billing. BillingService is the only billing writer. */
final class FeeBillingWorkflow
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function available(): bool
    {
        try {
            foreach (['fee_billing_campaigns', 'fee_billing_campaign_items', 'fee_billing_assignments', 'payment_notifications'] as $table) {
                $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                if (!$stmt->fetchColumn()) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    public function generationEnabled(): bool { return $this->available() && getenv('PAYMENT_BULK_BILLING_ENABLED') === '1'; }

    public function submitCategory(int $categoryId, string $year, string $semester, string $level, int $actor): int
    {
        if (!$this->available()) throw new RuntimeException('Category billing migration is not installed.');
        if (!preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st', '2nd', 'Summer'], true)) throw new InvalidArgumentException('Invalid academic year or semester.');
        if (!in_array($level, ['1', '2', '3', '4'], true)) throw new InvalidArgumentException('Choose one target year level.');
        $this->pdo->beginTransaction();
        try {
            $category = $this->pdo->prepare("SELECT category_id, category_name FROM fee_categories WHERE category_id = ? AND status = 'Active' FOR UPDATE");
            $category->execute([$categoryId]);
            $categoryRow = $category->fetch(PDO::FETCH_ASSOC);
            if (!$categoryRow) throw new RuntimeException('Choose an active fee category.');
            $fees = $this->pdo->prepare("SELECT fee_id, fee_name, default_amount FROM fees WHERE category_id = ? AND status = 'Active' AND default_amount > 0 ORDER BY fee_id FOR UPDATE");
            $fees->execute([$categoryId]);
            $items = $fees->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) throw new RuntimeException('This category has no active fees with a positive amount.');
            $existing = $this->pdo->prepare('SELECT campaign_id FROM fee_billing_campaigns WHERE category_id = ? AND academic_year = ? AND semester = ? AND year_level = ? FOR UPDATE');
            $existing->execute([$categoryId, $year, $semester, $level]);
            if ($existing->fetchColumn()) throw new RuntimeException('This category already has a bulk billing run for the selected year level and term.');
            $total = array_sum(array_map(static fn(array $item): float => (float) $item['default_amount'], $items));
            $campaign = $this->pdo->prepare("INSERT INTO fee_billing_campaigns (fee_id, category_id, category_name_snapshot, academic_year, semester, course, year_level, fee_name_snapshot, amount_snapshot, submitted_by) VALUES (NULL, ?, ?, ?, ?, NULL, ?, ?, ?, ?)");
            $campaign->execute([$categoryId, $categoryRow['category_name'], $year, $semester, $level, $categoryRow['category_name'], $total, $actor]);
            $campaignId = (int) $this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare('INSERT INTO fee_billing_campaign_items (campaign_id, fee_id, fee_name_snapshot, amount_snapshot) VALUES (?, ?, ?, ?)');
            foreach ($items as $item) $itemInsert->execute([$campaignId, $item['fee_id'], $item['fee_name'], $item['default_amount']]);
            $this->pdo->commit();
            return $campaignId;
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function get(int $campaignId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, f.status AS fee_status, f.default_amount AS current_amount, f.fee_name AS current_name, fc.status AS category_status FROM fee_billing_campaigns c LEFT JOIN fees f ON f.fee_id = c.fee_id LEFT JOIN fee_categories fc ON fc.category_id = c.category_id WHERE c.campaign_id = ?');
        $stmt->execute([$campaignId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function list(): array
    {
        return $this->pdo->query('SELECT c.*, f.status AS fee_status, fc.status AS category_status FROM fee_billing_campaigns c LEFT JOIN fees f ON f.fee_id = c.fee_id LEFT JOIN fee_categories fc ON fc.category_id = c.category_id ORDER BY c.submitted_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function items(array $campaign): array
    {
        if (empty($campaign['category_id'])) return [];
        $stmt = $this->pdo->prepare('SELECT fee_id, fee_name_snapshot, amount_snapshot FROM fee_billing_campaign_items WHERE campaign_id = ? ORDER BY campaign_item_id');
        $stmt->execute([$campaign['campaign_id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function eligibleWhere(array $campaign, array &$params): string
    {
        $where = ["s.status = 'Enrolled'"];
        if (!empty($campaign['year_level'])) { $where[] = 's.year_level = :year_level'; $params[':year_level'] = $campaign['year_level']; }
        return implode(' AND ', $where);
    }

    public function preview(array $campaign, int $limit = 25): array
    {
        $params = []; $where = $this->eligibleWhere($campaign, $params);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM students s WHERE {$where}"); $count->execute($params); $eligible = (int) $count->fetchColumn();
        $totalStudents = (int) $this->pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
        $items = $this->items($campaign);
        $feeIds = array_map(static fn(array $item): int => (int) $item['fee_id'], $items);
        $already = 0; $projected = 0.0;
        if ($feeIds) {
            $marks = implode(',', array_fill(0, count($feeIds), '?'));
            $sql = "SELECT s.student_id, COUNT(DISTINCT bi.fee_id) AS fee_count FROM students s LEFT JOIN billing b ON b.student_id = s.student_id AND b.academic_year = ? AND b.semester = ? LEFT JOIN billing_items bi ON bi.billing_id = b.billing_id AND bi.fee_id IN ({$marks}) WHERE s.status = 'Enrolled' AND s.year_level = ? GROUP BY s.student_id";
            $existing = $this->pdo->prepare($sql);
            $positional = [$campaign['academic_year'], $campaign['semester'], ...$feeIds, $campaign['year_level']];
            $existing->execute($positional);
            $present = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) $present[(int) $row['student_id']] = (int) $row['fee_count'];
            foreach ($present as $feeCount) { if ($feeCount === count($feeIds)) $already++; }
            $itemTotal = array_sum(array_map(static fn(array $item): float => (float) $item['amount_snapshot'], $items));
            $projected = max(0, $eligible - $already) * $itemTotal;
        }
        $list = $this->pdo->prepare("SELECT s.student_id, s.student_number, s.full_name, s.course, s.year_level FROM students s WHERE {$where} ORDER BY s.student_id LIMIT :limit");
        foreach ($params as $key => $value) $list->bindValue($key, $value);
        $list->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT); $list->execute();
        return ['eligible' => $eligible, 'excluded' => max(0, $totalStudents - $eligible), 'unresolved' => 0, 'already_assigned' => $already, 'projected_amount' => $projected, 'students' => $list->fetchAll(PDO::FETCH_ASSOC), 'items' => $items];
    }

    private function campaignIsCurrent(array $campaign): bool
    {
        if (empty($campaign['category_id']) || $campaign['category_status'] !== 'Active') return false;
        $items = $this->items($campaign); if (!$items) return false;
        $check = $this->pdo->prepare("SELECT fee_id, fee_name, default_amount FROM fees WHERE fee_id = ? AND status = 'Active'");
        foreach ($items as $item) { $check->execute([$item['fee_id']]); $current = $check->fetch(PDO::FETCH_ASSOC); if (!$current || $current['fee_name'] !== $item['fee_name_snapshot'] || (float) $current['default_amount'] !== (float) $item['amount_snapshot']) return false; }
        return true;
    }

    public function start(int $campaignId, int $actor): bool
    {
        if (!$this->generationEnabled()) throw new RuntimeException('Bulk generation is disabled until the migration and production setting are ready.');
        $campaign = $this->get($campaignId);
        if (!$campaign || !$this->campaignIsCurrent($campaign)) throw new RuntimeException('The category or one of its fee snapshots changed or is inactive. Refresh the category and create a new run.');
        $stmt = $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Running', approved_by = ?, approved_at = NOW() WHERE campaign_id = ? AND status = 'Submitted'");
        $stmt->execute([$actor, $campaignId]); return $stmt->rowCount() === 1;
    }

    public function runChunk(int $campaignId, int $actor, int $chunkSize = 25): array
    {
        if (!$this->generationEnabled()) throw new RuntimeException('Bulk generation is disabled.');
        $token = bin2hex(random_bytes(16));
        $lease = $this->pdo->prepare("UPDATE fee_billing_campaigns SET runner_token = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE campaign_id = ? AND status = 'Running' AND (runner_token IS NULL OR lease_expires_at < NOW())");
        $lease->execute([$token, $campaignId]); if ($lease->rowCount() !== 1) throw new RuntimeException('Run is complete or another worker is processing it.');
        try {
            $campaign = $this->get($campaignId); if (!$campaign || !$this->campaignIsCurrent($campaign)) throw new RuntimeException('Category changed during the run; generation stopped.');
            $params = [':cursor' => (int) $campaign['cursor_student_id']]; $where = $this->eligibleWhere($campaign, $params);
            $query = $this->pdo->prepare("SELECT s.student_id, s.user_id FROM students s WHERE s.student_id > :cursor AND {$where} ORDER BY s.student_id LIMIT :limit");
            foreach ($params as $key => $value) $query->bindValue($key, $value, $key === ':cursor' ? PDO::PARAM_INT : PDO::PARAM_STR);
            $query->bindValue(':limit', max(1, min(50, $chunkSize)), PDO::PARAM_INT); $query->execute(); $students = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($students as $student) {
                try { $this->assign($campaign, (int) $student['student_id'], (int) ($student['user_id'] ?? 0), $actor); }
                catch (Throwable $e) { $this->record($campaign, (int) $student['student_id'], null, 'Failed', substr($e->getMessage(), 0, 500), 0); }
                $this->pdo->prepare('UPDATE fee_billing_campaigns SET cursor_student_id = ? WHERE campaign_id = ? AND runner_token = ?')->execute([(int) $student['student_id'], $campaignId, $token]);
            }
            if (count($students) < max(1, min(50, $chunkSize))) $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Completed' WHERE campaign_id = ? AND runner_token = ?")->execute([$campaignId, $token]);
            return $this->get($campaignId) ?? [];
        } finally { $this->pdo->prepare('UPDATE fee_billing_campaigns SET runner_token = NULL, lease_expires_at = NULL WHERE campaign_id = ? AND runner_token = ?')->execute([$campaignId, $token]); }
    }

    public function retryFailures(int $campaignId): bool
    {
        if (!$this->generationEnabled()) throw new RuntimeException('Bulk generation is disabled.');
        $stmt = $this->pdo->prepare("UPDATE fee_billing_campaigns SET status = 'Running', cursor_student_id = 0, failed_count = 0, last_error = NULL WHERE campaign_id = ? AND status = 'Completed' AND failed_count > 0 AND runner_token IS NULL");
        $stmt->execute([$campaignId]); return $stmt->rowCount() === 1;
    }

    private function assign(array $campaign, int $studentId, int $userId, int $actor): void
    {
        $eligible = $this->pdo->prepare("SELECT 1 FROM students WHERE student_id = ? AND status = 'Enrolled' AND year_level = ?");
        $eligible->execute([$studentId, $campaign['year_level']]);
        if (!$eligible->fetchColumn()) { $this->record($campaign, $studentId, null, 'Skipped', 'Eligibility changed before assignment', 0); return; }
        $check = $this->pdo->prepare('SELECT assignment_id FROM fee_billing_assignments WHERE campaign_id = ? AND student_id = ?'); $check->execute([$campaign['campaign_id'], $studentId]); if ($check->fetchColumn()) return;
        $items = $this->items($campaign); $feeIds = array_map(static fn(array $item): int => (int) $item['fee_id'], $items); if (!$feeIds) throw new RuntimeException('Campaign has no fee snapshot.');
        $latest = $this->pdo->prepare('SELECT billing_id, academic_year, semester FROM billing WHERE student_id = ? ORDER BY billing_id DESC LIMIT 1'); $latest->execute([$studentId]); $billing = $latest->fetch(PDO::FETCH_ASSOC);
        if ($billing && ($billing['academic_year'] !== $campaign['academic_year'] || $billing['semester'] !== $campaign['semester'])) { $this->record($campaign, $studentId, null, 'Skipped', 'Student latest SOA belongs to a different term; review required', 0); return; }
        $context = 'Bulk Category Campaign #' . $campaign['campaign_id']; $writer = new BillingService($this->pdo); $added = [];
        if ($billing) $added = $writer->appendFeesToBilling((int) $billing['billing_id'], $feeIds, $context, $actor)['added'];
        else { $writer->generateBilling($studentId, $campaign['academic_year'], $campaign['semester'], 'Assessment', $feeIds, 0, $actor, $context); $added = $items; }
        if (!$added) { $this->record($campaign, $studentId, null, 'Existing', 'All category fees are already assigned for the term', 0); return; }
        $first = $this->pdo->prepare('SELECT billing_item_id FROM billing_items WHERE billing_id = (SELECT billing_id FROM billing WHERE student_id = ? AND academic_year = ? AND semester = ? ORDER BY billing_id DESC LIMIT 1) AND source_context = ? ORDER BY billing_item_id LIMIT 1');
        $first->execute([$studentId, $campaign['academic_year'], $campaign['semester'], $context]);
        $this->record($campaign, $studentId, (int) $first->fetchColumn(), 'Added', null, $userId, $added);
    }

    private function record(array $campaign, int $studentId, ?int $itemId, string $outcome, ?string $reason, int $notifyUserId, array $added = []): void
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT IGNORE INTO fee_billing_assignments (campaign_id, student_id, billing_item_id, outcome, reason) VALUES (?, ?, ?, ?, ?)'); $insert->execute([$campaign['campaign_id'], $studentId, $itemId ?: null, $outcome, $reason]);
            if ($insert->rowCount() === 1) {
                $column = ['Added' => 'added_count', 'Existing' => 'existing_count', 'Skipped' => 'skipped_count', 'Failed' => 'failed_count'][$outcome]; $this->pdo->prepare("UPDATE fee_billing_campaigns SET {$column} = {$column} + 1 WHERE campaign_id = ?")->execute([$campaign['campaign_id']]);
                if ($outcome === 'Added' && $notifyUserId > 0) {
                    $names = implode(', ', array_map(static fn(array $fee): string => (string) ($fee['fee_name'] ?? $fee['fee_name_snapshot'] ?? ''), $added));
                    $amount = array_sum(array_map(static fn(array $fee): float => (float) ($fee['amount'] ?? $fee['amount_snapshot'] ?? 0), $added));
                    $message = $campaign['category_name_snapshot'] . ': ' . $names . ' (PHP ' . number_format($amount, 2) . ') was added to your billing for AY ' . $campaign['academic_year'] . ', ' . $campaign['semester'] . '.';
                    $notice = $this->pdo->prepare('INSERT IGNORE INTO payment_notifications (recipient_user_id, event_key, title, body, target_url) VALUES (?, ?, ?, ?, ?)');
                    $notice->execute([$notifyUserId, 'category-billing:' . $campaign['campaign_id'] . ':' . $studentId, 'New Billing Items', $message, '/modules/student-portal/pages/statement-of-account.php']);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}
