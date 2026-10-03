<?php

declare(strict_types=1);

final class StructuredAuditConflictException extends RuntimeException
{
}

/** Strict, idempotent writer for structured events projected from durable outboxes. */
final class StructuredActivityAuditWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $event */
    public function write(array $event): int
    {
        $event = $this->validate($event);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO activity_logs
                    (user_id, user_name, role_key, action, module_key, entity_type,
                     entity_id, detail, before_state, after_state, correlation_id,
                     ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $event['user_id'], $event['user_name'], $event['role_key'],
                $event['action'], $event['module_key'], $event['entity_type'],
                $event['entity_id'], $event['detail'], $event['before_state'],
                $event['after_state'], $event['correlation_id'],
                $event['ip_address'], $event['user_agent'],
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        $existing = $this->findByCorrelationId($event['correlation_id']);
        if ($existing === null || !$this->sameImmutableEvent($existing, $event)) {
            throw new StructuredAuditConflictException('CORE_AUDIT_CORRELATION_CONFLICT');
        }
        return (int) $existing['id'];
    }

    /** @return array<string,mixed>|null */
    private function findByCorrelationId(string $correlationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, user_name, role_key, action, module_key,
                    entity_type, entity_id, detail, before_state, after_state,
                    correlation_id, ip_address, user_agent
             FROM activity_logs WHERE correlation_id = ?'
        );
        $stmt->execute([$correlationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $event */
    private function sameImmutableEvent(array $existing, array $event): bool
    {
        foreach (['user_id', 'user_name', 'role_key', 'action', 'module_key', 'entity_type',
                  'entity_id', 'detail', 'before_state', 'after_state', 'correlation_id',
                  'ip_address', 'user_agent'] as $field) {
            $left = $existing[$field] ?? null;
            $right = $event[$field] ?? null;
            if (in_array($field, ['user_id', 'entity_id'], true)) {
                $left = $left === null ? null : (int) $left;
                $right = $right === null ? null : (int) $right;
            }
            if ($left !== $right) return false;
        }
        return true;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function validate(array $event): array
    {
        foreach (['action', 'module_key', 'entity_type', 'detail', 'correlation_id'] as $field) {
            if (!isset($event[$field]) || !is_string($event[$field]) || trim($event[$field]) === '') {
                throw new InvalidArgumentException("Structured audit field {$field} is required.");
            }
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event['correlation_id'])) {
            throw new InvalidArgumentException('Structured audit correlation_id must be a UUID v4.');
        }
        $limits = ['action' => 40, 'module_key' => 60, 'entity_type' => 60, 'detail' => 500,
            'user_name' => 150, 'role_key' => 40, 'ip_address' => 45, 'user_agent' => 255];
        foreach ($limits as $field => $limit) {
            if (isset($event[$field]) && mb_strlen((string) $event[$field]) > $limit) {
                throw new InvalidArgumentException("Structured audit field {$field} exceeds {$limit} characters.");
            }
        }
        foreach (['before_state', 'after_state'] as $field) {
            if (($event[$field] ?? null) !== null) {
                json_decode((string) $event[$field], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        foreach (['user_id', 'user_name', 'role_key', 'entity_id', 'before_state', 'after_state',
                  'ip_address', 'user_agent'] as $field) {
            $event[$field] = $event[$field] ?? null;
        }
        return $event;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000'
            || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);
    }
}
