<?php
declare(strict_types=1);

require_once ROOT_PATH . '/includes/StructuredActivityAuditWriter.php';
require_once __DIR__ . '/PaymentPermissionMatrixService.php';

final class PaymentPermissionConflictException extends RuntimeException {}
final class PaymentPermissionDuplicateException extends RuntimeException {}

final class PaymentPermissionManagementService
{
    public function __construct(
        private readonly PDO $core,
        private readonly StructuredActivityAuditWriter $audit
    ) {}

    /** @param array<string,bool> $decisions @param array{id:int,name:string,role:string} $actor */
    public function update(string $role, array $decisions, string $expectedVersion, string $correlationId, array $actor): array
    {
        $role = smsNormalizeRoleKey($role);
        if (!isset(PaymentPermissionMatrixService::ROLES[$role])) throw new InvalidArgumentException('ROLE_INVALID');
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedVersion)) throw new InvalidArgumentException('VERSION_INVALID');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $correlationId)) throw new InvalidArgumentException('CORRELATION_INVALID');
        if (($actor['role'] ?? '') !== 'mis_admin') throw new DomainException('MIS_ADMIN_REQUIRED');

        $catalog = paymentPermissionCatalog();
        if (array_keys($decisions) !== $catalog) throw new InvalidArgumentException('DECISION_SET_INCOMPLETE');
        foreach ($decisions as $permission => $granted) {
            if (!is_bool($granted)) throw new InvalidArgumentException('DECISION_INVALID');
            if (!paymentRoleAllowsPermission($role, $permission) && $granted) throw new DomainException('ROLE_CEILING_EXCEEDED');
            if (!PaymentPermissionMatrixService::implemented($permission) && $granted) throw new DomainException('CAPABILITY_UNAVAILABLE');
            if ($role === 'mis_admin' && in_array($permission, ['payment.mis_overview','payment.permissions.manage'], true) && !$granted) {
                throw new DomainException('PERMISSION_ADMIN_LOCKOUT');
            }
        }

        $this->core->beginTransaction();
        try {
            $lock = $this->core->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $stmt = $this->core->prepare('SELECT module_key,granted FROM role_permissions WHERE role_key=? ORDER BY module_key,granted' . $lock);
            $stmt->execute([$role]);
            $current = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permission = paymentCanonicalPermission((string) $row['module_key']);
                if (!in_array($permission, $catalog, true)) continue;
                if (array_key_exists($permission, $current)) throw new PaymentPermissionDuplicateException('DUPLICATE_PERMISSION_ROWS');
                $current[$permission] = (int) $row['granted'] === 1;
            }
            $versionState = [];
            foreach ($catalog as $permission) $versionState[$permission] = $current[$permission] ?? null;
            $actualVersion = hash('sha256', json_encode($versionState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (!hash_equals($actualVersion, $expectedVersion)) throw new PaymentPermissionConflictException('PERMISSION_VERSION_CONFLICT');

            $before = [];
            $after = [];
            $delete = $this->core->prepare('DELETE FROM role_permissions WHERE role_key=? AND module_key=?');
            $insert = $this->core->prepare('INSERT INTO role_permissions (role_key,module_key,granted) VALUES (?,?,?)');
            foreach ($catalog as $permission) {
                $before[$permission] = paymentPermissionDecision($role, $permission, $current[$permission] ?? null);
                $grant = paymentRoleAllowsPermission($role, $permission) && $decisions[$permission];
                foreach (paymentEquivalentPermissionKeys($permission) as $storedKey) {
                    $delete->execute([$role, $storedKey]);
                }
                $insert->execute([$role, $permission, $grant ? 1 : 0]);
                $after[$permission] = $grant;
            }

            $this->audit->write([
                'user_id'=>$actor['id'],'user_name'=>$actor['name'],'role_key'=>$actor['role'],
                'action'=>'PAYMENT_ROLE_PERMISSIONS_UPDATED','module_key'=>'payment',
                'entity_type'=>'payment_role','entity_id'=>null,
                'detail'=>'Payment permissions updated for ' . PaymentPermissionMatrixService::ROLES[$role] . '.',
                'before_state'=>json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_state'=>json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'correlation_id'=>$correlationId,
                'ip_address'=>function_exists('smsClientIp')?smsClientIp():null,
                'user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
            ]);
            $this->core->commit();
            return ['role'=>$role,'changed'=>count(array_diff_assoc($after,$before))];
        } catch (Throwable $exception) {
            if ($this->core->inTransaction()) $this->core->rollBack();
            throw $exception;
        }
    }
}
