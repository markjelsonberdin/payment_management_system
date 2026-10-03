<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/StructuredActivityAuditWriter.php';

final class CatalogCorrelationConflictException extends RuntimeException
{
}

final class CatalogMutationOutcome
{
    /** @param array<string,mixed> $audit */
    public function __construct(public readonly mixed $result, public readonly array $audit = [])
    {
    }
}

final class CatalogCanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function fingerprint(mixed $request): string
    {
        return hash('sha256', self::encode($request));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_string($value)) return trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (is_object($value)) {
            $value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::normalize($item);
        return $value;
    }
}

final class CatalogCorrelationId
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function assertValid(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException('correlation_id must be a UUID v4.');
        }
    }
}

/** Payment-side durable outbox storage and delivery lifecycle. */
final class PaymentAuditOutboxService
{
    public function __construct(
        private readonly PDO $paymentPdo,
        private readonly ?StructuredActivityAuditWriter $coreWriter = null,
        private readonly int $maxAttempts = 10
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $correlationId, bool $lock = false): ?array
    {
        $sql = 'SELECT * FROM payment_audit_outbox WHERE correlation_id = ?';
        if ($lock && $this->paymentPdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $sql .= ' FOR UPDATE';
        $stmt = $this->paymentPdo->prepare($sql);
        $stmt->execute([$correlationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $event */
    public function insertPending(string $correlationId, string $fingerprint, array $event, mixed $committedResult): void
    {
        $stmt = $this->paymentPdo->prepare(
            'INSERT INTO payment_audit_outbox
                (correlation_id, request_fingerprint, action, module_key, entity_type,
                 entity_id, detail, before_state, after_state, committed_result,
                 actor_user_id, actor_user_name, actor_role_key, actor_ip_address,
                 actor_user_agent, delivery_status, attempt_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', 0)'
        );
        $stmt->execute([
            $correlationId, $fingerprint, $event['action'], $event['module_key'] ?? 'payment',
            $event['entity_type'], $event['entity_id'] ?? null, $event['detail'],
            self::nullableJson($event['before_state'] ?? null),
            self::nullableJson($event['after_state'] ?? null),
            CatalogCanonicalJson::encode($committedResult), $event['actor_user_id'] ?? null,
            $event['actor_user_name'] ?? null, $event['actor_role_key'] ?? null,
            $event['actor_ip_address'] ?? null, $event['actor_user_agent'] ?? null,
        ]);
    }

    /** Attempt one post-commit delivery. Business state is never rolled back here. */
    public function deliver(string $correlationId): string
    {
        if ($this->coreWriter === null) return 'Pending';
        $now = self::now();
        $claim = $this->paymentPdo->prepare(
            "UPDATE payment_audit_outbox
             SET delivery_status = 'Processing', attempt_count = attempt_count + 1,
                 last_attempt_at = ?, updated_at = ?
             WHERE correlation_id = ? AND delivery_status IN ('Pending', 'Failed')"
        );
        $claim->execute([$now, $now, $correlationId]);
        if ($claim->rowCount() !== 1) {
            return (string) (($this->find($correlationId)['delivery_status'] ?? 'Pending'));
        }

        $row = $this->find($correlationId);
        try {
            $coreId = $this->coreWriter->write($this->coreEvent($row ?? []));
            $done = $this->paymentPdo->prepare(
                "UPDATE payment_audit_outbox
                 SET delivery_status = 'Delivered', delivered_at = ?, core_activity_log_id = ?,
                     last_error = NULL, next_attempt_at = NULL, updated_at = ?
                 WHERE correlation_id = ? AND delivery_status = 'Processing'"
            );
            $done->execute([$now, $coreId, $now, $correlationId]);
            return 'Delivered';
        } catch (Throwable $e) {
            $attempts = (int) (($row['attempt_count'] ?? 1));
            $status = $attempts >= $this->maxAttempts ? 'Failed' : 'Pending';
            $next = $status === 'Pending' ? gmdate('Y-m-d H:i:s', time() + self::retryDelay($attempts)) : null;
            $failed = $this->paymentPdo->prepare(
                'UPDATE payment_audit_outbox
                 SET delivery_status = ?, next_attempt_at = ?, last_error = ?, updated_at = ?
                 WHERE correlation_id = ? AND delivery_status = \'Processing\''
            );
            $failed->execute([$status, $next, substr($e->getMessage(), 0, 500), self::now(), $correlationId]);
            return $status;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function unresolved(int $limit = 100, int $staleSeconds = 300): array
    {
        if ($limit < 1 || $limit > 1000) throw new InvalidArgumentException('Outbox reconciliation limit must be between 1 and 1000.');
        $now = self::now();
        $stale = gmdate('Y-m-d H:i:s', time() - $staleSeconds);
        $sql = "SELECT * FROM payment_audit_outbox
                WHERE (delivery_status = 'Pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                   OR (delivery_status = 'Processing' AND last_attempt_at <= ?)
                   OR delivery_status = 'Failed'
                ORDER BY created_at, audit_outbox_id LIMIT " . (int) $limit;
        $stmt = $this->paymentPdo->prepare($sql);
        $stmt->execute([$now, $stale]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,string> correlation_id => resulting delivery status */
    public function reconcileDue(int $limit = 100, int $staleSeconds = 300): array
    {
        $results = [];
        foreach ($this->unresolved($limit, $staleSeconds) as $row) {
            $correlationId = (string) $row['correlation_id'];
            if ($row['delivery_status'] === 'Processing') {
                $stale = gmdate('Y-m-d H:i:s', time() - $staleSeconds);
                $recover = $this->paymentPdo->prepare(
                    "UPDATE payment_audit_outbox
                     SET delivery_status = 'Pending', next_attempt_at = NULL, updated_at = ?
                     WHERE correlation_id = ? AND delivery_status = 'Processing'
                       AND last_attempt_at <= ?"
                );
                $recover->execute([self::now(), $correlationId, $stale]);
                if ($recover->rowCount() !== 1) continue;
            }
            $results[$correlationId] = $this->deliver($correlationId);
        }
        return $results;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function coreEvent(array $row): array
    {
        return [
            'user_id' => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
            'user_name' => $row['actor_user_name'] ?? null,
            'role_key' => $row['actor_role_key'] ?? null,
            'action' => $row['action'] ?? '', 'module_key' => $row['module_key'] ?? 'payment',
            'entity_type' => $row['entity_type'] ?? '',
            'entity_id' => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
            'detail' => $row['detail'] ?? '', 'before_state' => $row['before_state'] ?? null,
            'after_state' => $row['after_state'] ?? null,
            'correlation_id' => $row['correlation_id'] ?? '',
            'ip_address' => $row['actor_ip_address'] ?? null,
            'user_agent' => $row['actor_user_agent'] ?? null,
        ];
    }

    private static function nullableJson(mixed $value): ?string
    {
        return $value === null ? null : CatalogCanonicalJson::encode($value);
    }

    private static function retryDelay(int $attempt): int
    {
        return [1 => 60, 2 => 300, 3 => 900, 4 => 3600][$attempt] ?? 3600;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

/** Common transaction/idempotency envelope for future catalog mutation methods. */
final class SchoolSalesCatalogMutationInfrastructure
{
    public function __construct(
        private readonly PDO $paymentPdo,
        private readonly PaymentAuditOutboxService $outbox
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $auditEvent
     * @param callable(PDO):mixed $mutation Future domain services supply the locked business mutation.
     * @return array<string,mixed>
     */
    public function execute(string $correlationId, array $request, array $auditEvent, callable $mutation): array
    {
        CatalogCorrelationId::assertValid($correlationId);
        $fingerprint = CatalogCanonicalJson::fingerprint($request);
        $this->paymentPdo->beginTransaction();
        try {
            $existing = $this->outbox->find($correlationId, true);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_fingerprint'], $fingerprint)) {
                    throw new CatalogCorrelationConflictException('CORRELATION_ID_CONFLICT');
                }
                $result = json_decode((string) $existing['committed_result'], true, 512, JSON_THROW_ON_ERROR);
                $this->paymentPdo->commit();
                $auditStatus = in_array($existing['delivery_status'], ['Pending', 'Failed'], true)
                    ? $this->outbox->deliver($correlationId)
                    : (string) $existing['delivery_status'];
                return $this->response($correlationId, $fingerprint, $result, true, $auditStatus);
            }

            $outcome = $mutation($this->paymentPdo);
            if ($outcome instanceof CatalogMutationOutcome) {
                $result = $outcome->result;
                $auditEvent = array_replace($auditEvent, $outcome->audit);
            } else {
                $result = $outcome;
            }
            // Return the same canonical representation on the first commit and
            // every later replay, including stable object-key ordering.
            $result = json_decode(CatalogCanonicalJson::encode($result), true, 512, JSON_THROW_ON_ERROR);
            $this->outbox->insertPending($correlationId, $fingerprint, $auditEvent, $result);
            $this->paymentPdo->commit();
        } catch (Throwable $e) {
            if ($this->paymentPdo->inTransaction()) $this->paymentPdo->rollBack();
            // A concurrent request may win the unique correlation insert after our
            // initial lookup. Recover it as replay/conflict after rolling back all
            // tentative business work from this transaction.
            if ($e instanceof PDOException && $this->isUniqueViolation($e)) {
                $existing = $this->outbox->find($correlationId);
                if ($existing !== null) {
                    if (!hash_equals((string) $existing['request_fingerprint'], $fingerprint)) {
                        throw new CatalogCorrelationConflictException('CORRELATION_ID_CONFLICT', 0, $e);
                    }
                    $result = json_decode((string) $existing['committed_result'], true, 512, JSON_THROW_ON_ERROR);
                    $auditStatus = in_array($existing['delivery_status'], ['Pending', 'Failed'], true)
                        ? $this->outbox->deliver($correlationId)
                        : (string) $existing['delivery_status'];
                    return $this->response($correlationId, $fingerprint, $result, true, $auditStatus);
                }
            }
            throw $e;
        }

        $auditStatus = $this->outbox->deliver($correlationId);
        return $this->response($correlationId, $fingerprint, $result, false, $auditStatus);
    }

    /** @return array<string,mixed> */
    private function response(string $correlationId, string $fingerprint, mixed $result, bool $replay, string $auditStatus): array
    {
        return [
            'committed' => true, 'correlation_id' => $correlationId,
            'request_fingerprint' => $fingerprint, 'idempotent_replay' => $replay,
            'audit_status' => strtolower($auditStatus), 'result' => $result,
        ];
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        return $e->getCode() === '23000'
            || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);
    }
}
