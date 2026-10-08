<?php
declare(strict_types=1);

final class PaymentPermissionMatrixService
{
    public const ROLES = [
        'mis_admin' => 'MIS Admin',
        'accounting_admin' => 'Accounting Admin',
        'accounting_officer' => 'Accounting Officer',
        'cashier' => 'Cashier',
    ];

    public function __construct(private readonly PDO $core)
    {
    }

    public function load(): array
    {
        $permissions = paymentPermissionCatalog();
        $coreRows = [];
        $duplicates = [];
        $available = true;
        try {
            $roles = array_keys(self::ROLES);
            $roleMarks = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $this->core->prepare(
                "SELECT role_key,module_key,granted FROM role_permissions
                 WHERE role_key IN ({$roleMarks})
                 ORDER BY role_key,module_key,granted"
            );
            $stmt->execute($roles);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $role = smsNormalizeRoleKey((string) $row['role_key']);
                $permission = paymentCanonicalPermission((string) $row['module_key']);
                if (!isset(self::ROLES[$role]) || !in_array($permission, $permissions, true)) continue;
                if (array_key_exists($permission, $coreRows[$role] ?? [])) {
                    $duplicates[] = $role . ':' . $permission;
                    if ((int) $row['granted'] === 0) $coreRows[$role][$permission] = false;
                    continue;
                }
                $coreRows[$role][$permission] = (int) $row['granted'] === 1;
            }
        } catch (Throwable $exception) {
            $available = false;
            error_log('Payment permission matrix Core lookup unavailable: ' . get_class($exception));
        }

        $rows = [];
        $versions = [];
        foreach (self::ROLES as $role => $label) {
            $versionState = [];
            foreach ($permissions as $permission) {
                $ceiling = paymentRoleAllowsPermission($role, $permission);
                $explicit = $coreRows[$role][$permission] ?? null;
                $core = !$available ? 'unavailable' : ($explicit === null ? 'absent' : ($explicit ? 'granted' : 'revoked'));
                if (!$ceiling) {
                    $effective = 'denied';
                    $source = 'role_ceiling';
                } elseif (!$available) {
                    $effective = 'unavailable';
                    $source = 'core_unavailable';
                } else {
                    $effective = paymentPermissionDecision($role, $permission, $explicit) ? 'allowed' : 'denied';
                    $source = $explicit === null ? 'compatibility_fallback' : ($explicit ? 'explicit_grant' : 'explicit_revocation');
                }
                $implemented = self::implemented($permission);
                if (!$implemented) { $effective = 'denied'; $source = 'not_implemented'; }
                $protected = !$ceiling || !$implemented || $this->critical($role, $permission);
                $rows[] = [
                    'role' => $role,
                    'role_label' => $label,
                    'module' => self::moduleFor($permission),
                    'permission' => $permission,
                    'role_ceiling' => $ceiling ? 'permitted' : 'denied',
                    'core_permission' => $core,
                    'effective_access' => $effective,
                    'source' => $source,
                    'protected' => $protected,
                    'available' => $implemented,
                    'editable' => $available && $ceiling && !$protected,
                ];
                $versionState[$permission] = $explicit;
            }
            $versions[$role] = hash('sha256', json_encode($versionState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $modules = [];
        foreach ($rows as $row) {
            $modules[$row['module']]['label'] = $row['module'];
            $modules[$row['module']]['permissions'][$row['permission']] = true;
            $modules[$row['module']]['roles'][$row['role']][] = $row['effective_access'];
        }
        $moduleRows = [];
        foreach ($modules as $label => $module) {
            $roleStates = [];
            foreach (self::ROLES as $role => $_) {
                $states = $module['roles'][$role] ?? [];
                $roleStates[$role] = in_array('allowed', $states, true) ? 'allowed'
                    : (in_array('unavailable', $states, true) ? 'unavailable' : 'denied');
            }
            $moduleRows[] = [
                'module' => $label,
                'permissions' => array_keys($module['permissions']),
                'roles' => $roleStates,
            ];
        }
        usort($moduleRows, static fn(array $a, array $b): int => $a['module'] <=> $b['module']);

        return [
            'authorization_available' => $available,
            'roles' => array_map(static fn(string $key, string $label): array => ['key'=>$key,'label'=>$label], array_keys(self::ROLES), array_values(self::ROLES)),
            'versions' => $versions,
            'duplicates' => array_values(array_unique($duplicates)),
            'modules' => $moduleRows,
            'rows' => $rows,
        ];
    }

    public static function moduleFor(string $permission): string
    {
        return match (true) {
            str_starts_with($permission, 'payment_users.') => 'User Management',
            $permission === 'payment.permissions.manage' => 'Roles & Permissions',
            $permission === 'payment.mis_overview' => 'MIS Overview',
            $permission === 'payment.security.view' => 'Security Monitoring',
            $permission === 'integration.paymongo.manage' => 'PayMongo Configuration',
            $permission === 'integration.ocr.manage' => 'Google OCR Configuration',
            str_starts_with($permission, 'fee.') => 'Fee Setup & Configuration',
            str_starts_with($permission, 'billing.') => 'Student Billing & Invoicing',
            $permission === 'payment.discount' => 'Discount & Scholarship',
            str_starts_with($permission, 'payment.concern.') => 'Payment Concerns',
            str_starts_with($permission, 'payment.reconciliation.') => 'Bank Reconciliation',
            str_starts_with($permission, 'ledger.') => 'Payment History & Ledger',
            str_starts_with($permission, 'report.') => 'Collection Reporting & Analytics',
            str_starts_with($permission, 'ar.') => 'Accounts Receivable',
            in_array($permission, ['payment.collection','payment.walkin_history','payment.cashier_dashboard'], true) => 'Cashier Collections',
            $permission === 'payment.school_sales' => 'School Sales Checkout',
            str_starts_with($permission, 'school_sales.catalog.') => 'School Sales Catalog',
            default => 'Payment Operations',
        };
    }

    public static function implemented(string $permission): bool
    {
        return paymentCapabilityImplemented($permission);
    }

    private function critical(string $role, string $permission): bool
    {
        return $role === 'mis_admin'
            && in_array($permission, ['payment.mis_overview','payment.permissions.manage'], true);
    }
}
