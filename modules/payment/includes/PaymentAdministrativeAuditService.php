<?php
declare(strict_types=1);

final class PaymentAdministrativeAuditService
{
    public const PAGE_SIZES = [25, 50, 100];
    public const CATEGORIES = ['USER_ADMINISTRATION', 'INTEGRATION_PAYMONGO', 'INTEGRATION_OCR', 'SECURITY_ADMINISTRATION'];
    private const EVENTS = [
        'PAYMENT_USER_CREATED' => ['canonical' => 'PAYMENT_USER_CREATED', 'label' => 'Payment user created', 'category' => 'USER_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_UPDATED' => ['canonical' => 'PAYMENT_USER_UPDATED', 'label' => 'Payment user updated', 'category' => 'USER_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_ROLE_CHANGED' => ['canonical' => 'PAYMENT_USER_ROLE_CHANGED', 'label' => 'Payment user role changed', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_ACTIVATED' => ['canonical' => 'PAYMENT_USER_ACTIVATED', 'label' => 'Payment user activated', 'category' => 'USER_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_DEACTIVATED' => ['canonical' => 'PAYMENT_USER_DEACTIVATED', 'label' => 'Payment user deactivated', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_UNLOCKED' => ['canonical' => 'PAYMENT_USER_UNLOCKED', 'label' => 'Payment user unlocked', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMENT_USER_PASSWORD_RESET' => ['canonical' => 'PAYMENT_USER_PASSWORD_RESET', 'label' => 'Payment user password reset', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'SUCCESS'],
        'PAYMONGO_CONFIGURATION_UPDATED' => ['canonical' => 'PAYMONGO_CONFIGURATION_UPDATED', 'label' => 'PayMongo configuration updated', 'category' => 'INTEGRATION_PAYMONGO', 'result' => 'SUCCESS'],
        'PAYMONGO_MODE_CHANGED' => ['canonical' => 'PAYMONGO_MODE_CHANGED', 'label' => 'PayMongo mode changed', 'category' => 'INTEGRATION_PAYMONGO', 'result' => 'SUCCESS'],
        'PAYMONGO_CONNECTION_TESTED' => ['canonical' => 'PAYMONGO_CONNECTION_TESTED', 'label' => 'PayMongo connection tested', 'category' => 'INTEGRATION_PAYMONGO', 'result' => 'RECORDED'],
        'OCR_CONFIGURATION_UPDATED' => ['canonical' => 'OCR_CONFIGURATION_UPDATED', 'label' => 'Google OCR configuration updated', 'category' => 'INTEGRATION_OCR', 'result' => 'SUCCESS'],
        'RECEIPT_OCR_EVIDENCE_RECORDED' => ['canonical' => 'RECEIPT_OCR_EVIDENCE_RECORDED', 'label' => 'OCR technical evidence recorded', 'category' => 'INTEGRATION_OCR', 'result' => 'RECORDED', 'ocr_private' => true],
        'RECEIPT_OCR_FALLBACK_RECORDED' => ['canonical' => 'RECEIPT_OCR_FALLBACK_RECORDED', 'label' => 'OCR fallback recorded', 'category' => 'INTEGRATION_OCR', 'result' => 'PARTIAL', 'ocr_private' => true],
        'accounting_user_create' => ['canonical' => 'PAYMENT_USER_CREATED', 'label' => 'Payment user created', 'category' => 'USER_ADMINISTRATION', 'result' => 'RECORDED', 'legacy' => true],
        'accounting_user_edit' => ['canonical' => 'PAYMENT_USER_UPDATED', 'label' => 'Payment user updated', 'category' => 'USER_ADMINISTRATION', 'result' => 'RECORDED', 'legacy' => true],
        'accounting_user_status' => ['canonical' => 'PAYMENT_USER_STATUS_CHANGED', 'label' => 'Payment user status changed', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'RECORDED', 'legacy' => true],
        'accounting_user_password_reset' => ['canonical' => 'PAYMENT_USER_PASSWORD_RESET', 'label' => 'Payment user password reset', 'category' => 'SECURITY_ADMINISTRATION', 'result' => 'RECORDED', 'legacy' => true],
    ];
    private const OCR_SAFE_KEYS = ['success', 'failure_category', 'manual_review_available', 'attempt_number', 'cache_or_idempotent_reuse', 'indicator_count'];

    public function __construct(private readonly PDO $pdo, private readonly ?DateTimeImmutable $clock = null) {}

    /** @return array<string,int> */
    public function summary(): array
    {
        $filters = $this->validateFilters([]);
        $counts = ['events_today' => 0, 'user_administration' => 0, 'integration_administration' => 0, 'security_administration' => 0];
        $today = $this->now()->format('Y-m-d');
        $actions = array_keys(self::EVENTS);
        [$where, $params] = $this->where($filters, $actions);
        $stmt = $this->pdo->prepare('SELECT action, DATE(created_at) AS event_date, COUNT(*) AS event_count FROM activity_logs WHERE ' . $where . ' GROUP BY action, DATE(created_at)');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = self::EVENTS[$row['action']]; $count = (int) $row['event_count'];
            if ($row['event_date'] === $today) $counts['events_today'] += $count;
            if ($meta['category'] === 'USER_ADMINISTRATION') $counts['user_administration'] += $count;
            if (str_starts_with($meta['category'], 'INTEGRATION_')) $counts['integration_administration'] += $count;
            if ($meta['category'] === 'SECURITY_ADMINISTRATION') $counts['security_administration'] += $count;
        }
        return $counts;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function events(array $input): array
    {
        $filters = $this->validateFilters($input);
        $actions = $this->filteredActions($filters);
        if ($actions === []) return ['items' => [], 'total' => 0, 'page' => $filters['page'], 'page_size' => $filters['page_size'], 'pages' => 1, 'filters' => $filters];
        [$where, $params] = $this->where($filters, $actions);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($filters['page'] - 1) * $filters['page_size'];
        $rows = $this->queryRows($filters, true, $filters['page_size'], $offset, $actions);
        return ['items' => array_map(fn(array $row): array => $this->tableEvent($row), $rows), 'total' => $total,
            'page' => $filters['page'], 'page_size' => $filters['page_size'], 'pages' => max(1, (int) ceil($total / $filters['page_size'])), 'filters' => $filters];
    }

    /** @return array<string,mixed> */
    public function detail(int $id): array
    {
        if ($id < 1) throw new InvalidArgumentException('A valid audit event is required.');
        $marks = implode(',', array_fill(0, count(self::EVENTS), '?'));
        $stmt = $this->pdo->prepare("SELECT id,user_id,user_name,role_key,action,module_key,entity_type,entity_id,before_state,after_state,correlation_id,ip_address,user_agent,created_at FROM activity_logs WHERE id=? AND module_key='payment' AND action IN ($marks) LIMIT 1");
        $stmt->execute(array_merge([$id], array_keys(self::EVENTS)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('AUDIT_EVENT_NOT_FOUND');
        return $this->detailEvent($row);
    }

    /** @return array<string,mixed> */
    public function correlation(string $correlationId): array
    {
        $this->assertCorrelation($correlationId);
        $filters = $this->validateFilters(['correlation_id' => $correlationId, 'page_size' => 100]);
        $rows = $this->queryRows($filters, true, 100, 0);
        return ['correlation_id' => $correlationId, 'items' => array_map(fn(array $row): array => $this->tableEvent($row), $rows), 'total' => count($rows)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function validateFilters(array $input): array
    {
        $allowed = ['date_from','date_to','category','event','actor','target','result','correlation_id','page','page_size'];
        foreach (array_keys($input) as $key) if (!in_array((string) $key, $allowed, true)) throw new InvalidArgumentException('Unsupported audit filter.');
        $defaultFrom = $this->now()->modify('-29 days')->format('Y-m-d');
        $from = $this->date((string) ($input['date_from'] ?? $defaultFrom));
        $to = $this->date((string) ($input['date_to'] ?? $this->now()->format('Y-m-d')));
        if ($from > $to) throw new InvalidArgumentException('Audit date range is invalid.');
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) throw new InvalidArgumentException('Audit date range cannot exceed 366 days.');
        $category = strtoupper(trim((string) ($input['category'] ?? '')));
        if ($category !== '' && !in_array($category, self::CATEGORIES, true)) throw new InvalidArgumentException('Invalid audit category.');
        $event = trim((string) ($input['event'] ?? ''));
        if ($event !== '' && !isset(self::EVENTS[$event]) && !in_array($event, array_column(self::EVENTS, 'canonical'), true)) throw new InvalidArgumentException('Invalid audit event.');
        $result = strtoupper(trim((string) ($input['result'] ?? '')));
        if (!in_array($result, ['', 'SUCCESS', 'FAILED', 'DENIED', 'PARTIAL', 'RECORDED'], true)) throw new InvalidArgumentException('Invalid audit result.');
        $correlation = strtolower(trim((string) ($input['correlation_id'] ?? '')));
        if ($correlation !== '') $this->assertCorrelation($correlation);
        $size = $this->positiveInt($input['page_size'] ?? 25, 'page size');
        if (!in_array($size, self::PAGE_SIZES, true)) throw new InvalidArgumentException('Page size must be 25, 50, or 100.');
        $actor = $this->optionalId($input['actor'] ?? null, 'actor');
        $target = $this->optionalId($input['target'] ?? null, 'target');
        return ['date_from'=>$from,'date_to'=>$to,'category'=>$category,'event'=>$event,'actor'=>$actor,'target'=>$target,
            'result'=>$result,'correlation_id'=>$correlation,'page'=>$this->positiveInt($input['page'] ?? 1, 'page'),'page_size'=>$size];
    }

    /** @param array<string,mixed> $filters @param list<string>|null $actions @return list<array<string,mixed>> */
    private function queryRows(array $filters, bool $paged, int $limit, int $offset, ?array $actions = null): array
    {
        $actions ??= $this->filteredActions($filters);
        if ($actions === []) return [];
        [$where, $params] = $this->where($filters, $actions);
        $sql = 'SELECT id,user_id,user_name,role_key,action,module_key,entity_type,entity_id,before_state,after_state,correlation_id,ip_address,user_agent,created_at FROM activity_logs WHERE ' . $where . ' ORDER BY created_at DESC,id DESC LIMIT ' . (int) $limit;
        if ($paged) $sql .= ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $filters @param list<string> $actions @return array{string,list<mixed>} */
    private function where(array $filters, array $actions): array
    {
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $where = ["module_key='payment'", "action IN ($marks)", 'created_at>=?', 'created_at<?'];
        $params = array_merge($actions, [$filters['date_from'].' 00:00:00', (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00')]);
        if ($filters['actor'] > 0) { $where[]='user_id=?'; $params[]=$filters['actor']; }
        if ($filters['target'] > 0) { $where[]='entity_id=?'; $params[]=$filters['target']; }
        if ($filters['correlation_id'] !== '') { $where[]='correlation_id=?'; $params[]=$filters['correlation_id']; }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $filters @return list<string> */
    private function filteredActions(array $filters): array
    {
        $actions = [];
        foreach (self::EVENTS as $raw => $meta) {
            if ($filters['category'] !== '' && $meta['category'] !== $filters['category']) continue;
            if ($filters['event'] !== '' && $raw !== $filters['event'] && $meta['canonical'] !== $filters['event']) continue;
            if ($filters['result'] !== '' && $meta['result'] !== $filters['result']) continue;
            $actions[] = $raw;
        }
        return $actions;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function tableEvent(array $row): array
    {
        $meta = self::EVENTS[$row['action']];
        return ['id'=>(int)$row['id'],'created_at'=>(string)$row['created_at'],'event'=>$meta['canonical'],'label'=>$meta['label'],
            'category'=>$meta['category'],'actor'=>$this->actor($row),'target'=>$this->target($row), 'result'=>$meta['result'],
            'correlation_id'=>$this->safeCorrelation($row['correlation_id'] ?? null)];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function detailEvent(array $row): array
    {
        $event = $this->tableEvent($row); $meta = self::EVENTS[$row['action']];
        $before = $this->state($row['before_state'] ?? null, !empty($meta['ocr_private']));
        $after = $this->state($row['after_state'] ?? null, !empty($meta['ocr_private']));
        $event['raw_action'] = (string) $row['action'];
        $event['change'] = ['before'=>$before,'after'=>$after,'fields'=>$this->diff($before,$after)];
        $event['request_context'] = ['ip_address'=>$this->safeIp($row['ip_address'] ?? null),
            'user_agent'=>$this->safeUserAgent($row['user_agent'] ?? null),'correlation_id'=>$event['correlation_id']];
        $event['summary'] = $meta['legacy'] ?? false ? 'Historical Payment administrative event.' : $meta['label'] . '.';
        return $event;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function actor(array $row): array
    {
        return ['id'=>isset($row['user_id'])?(int)$row['user_id']:null,'name'=>mb_substr((string)($row['user_name'] ?: 'System'),0,150),
            'role'=>mb_substr((string)($row['role_key'] ?: 'system'),0,40)];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function target(array $row): array
    {
        $type = (string)($row['entity_type'] ?? ''); $id = isset($row['entity_id']) ? (int)$row['entity_id'] : null;
        $label = match ($type) {
            'payment_user' => $id ? $this->paymentUserLabel($id) : 'Payment user',
            'paymongo_configuration' => 'PayMongo configuration', 'paymongo_connection' => 'PayMongo connection',
            'ocr_configuration' => 'Google OCR configuration',
            'payment_concern' => $id ? 'OCR technical event #' . $id : 'OCR technical event',
            default => $id ? 'Payment administrative entity #' . $id : 'Payment administration',
        };
        return ['type'=>$type !== '' ? $type : null,'id'=>$id,'label'=>$label];
    }

    private function paymentUserLabel(int $id): string
    {
        $stmt=$this->pdo->prepare("SELECT full_name FROM users WHERE id=? AND role_key IN ('accounting_admin','accounting_officer','cashier') LIMIT 1");
        $stmt->execute([$id]); $name=$stmt->fetchColumn();
        return $name ? mb_substr((string)$name,0,150) : 'Payment user #' . $id;
    }

    private function state(mixed $json, bool $ocrPrivate): array
    {
        if (!is_string($json) || trim($json)==='') return [];
        try { $value=json_decode($json,true,64,JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
        if (!is_array($value)) return [];
        $value=$this->redact($value);
        if (!$ocrPrivate) return $value;
        return array_intersect_key($value,array_flip(self::OCR_SAFE_KEYS));
    }

    private function redact(mixed $value, ?string $key=null): mixed
    {
        if ($key !== null && $this->sensitiveKey($key)) return '[REDACTED]';
        if (!is_array($value)) return is_string($value) ? mb_substr($value,0,500) : $value;
        $clean=[]; foreach($value as $k=>$v) $clean[$k]=$this->redact($v,is_string($k)?$k:null); return $clean;
    }

    private function sensitiveKey(string $key): bool
    {
        $key=strtolower(preg_replace('/[^a-z0-9]+/i','_',$key) ?? $key);
        if ($key === 'sessions_revoked') return false;
        foreach (['password','secret','token','totp','passkey','webauthn','credential','authorization','cookie','session_id','api_key','private_key','webhook_secret','access_token','refresh_token','credential_path','receipt_path','private_path','filesystem_path'] as $term) {
            if ($key===$term || str_contains($key,$term)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<array<string,mixed>> */
    private function diff(array $before,array $after): array
    {
        $changes=[]; foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $key){
            $old=$before[$key]??null; $new=$after[$key]??null;
            if($old!==$new)$changes[]=['field'=>(string)$key,'before'=>$old,'after'=>$new];
        } return $changes;
    }

    private function safeCorrelation(mixed $value): ?string
    { $value=strtolower(trim((string)$value)); return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)?$value:null; }
    private function assertCorrelation(string $value): void
    { if($this->safeCorrelation($value)!==$value)throw new InvalidArgumentException('Correlation ID must be a valid UUID v4.'); }
    private function safeIp(mixed $value): ?string
    { $value=trim((string)$value); return filter_var($value,FILTER_VALIDATE_IP)?$value:null; }
    private function safeUserAgent(mixed $value): ?string
    { $value=trim((string)$value); return $value!==''?mb_substr($value,0,255):null; }
    private function date(string $value): string
    { $value=trim($value);$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Invalid audit date.');return $value; }
    private function positiveInt(mixed $value,string $field): int
    { $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);if($id===false)throw new InvalidArgumentException("Invalid {$field}.");return(int)$id; }
    private function optionalId(mixed $value,string $field): int
    { if($value===null||$value==='')return 0;return $this->positiveInt($value,$field); }
    private function now(): DateTimeImmutable
    { return $this->clock??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')); }
}
