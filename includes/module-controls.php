<?php
/**
 * SMS 2 – Per-module controls (maintenance + force logout)
 * + Global system maintenance mode
 */
declare(strict_types=1);

require_once __DIR__ . '/security.php';

/* ── Global system maintenance ─────────────────────────────────── */

function smsIsSystemInMaintenance(): bool
{
    return smsSetting('system_maintenance', '0') === '1';
}

function smsSystemMaintenanceMessage(): string
{
    $custom = trim(smsSetting('system_maintenance_msg', ''));
    if ($custom !== '') {
        return $custom;
    }
    return 'The system is temporarily unavailable for maintenance. Please try again later.';
}

function smsSystemMaintenanceKickEpoch(): int
{
    return (int) smsSetting('system_maintenance_kick_epoch', '0');
}

/**
 * Enable / disable global maintenance. When enabling, bump kick epoch so
 * non-admin sessions are ended on their next request (auto-logout).
 */
function smsSetSystemMaintenance(bool $enabled, string $message = ''): bool
{
    $ok = smsSetSetting('system_maintenance', $enabled ? '1' : '0');
    if ($message !== '') {
        smsSetSetting('system_maintenance_msg', mb_substr(trim($message), 0, 500));
    }
    if ($enabled) {
        smsSetSetting('system_maintenance_kick_epoch', (string) time());
    }
    return $ok;
}

/**
 * Auto-logout non-admin users when global maintenance is on.
 * Admins keep full access so they can turn it off.
 */
function smsEnforceSystemMaintenance(): void
{
    if (!smsIsSystemInMaintenance()) {
        return;
    }

    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowedSuffixes = [
        '/account/maintenance.php',
        '/login/logout.php',
        '/login/login.php',
        '/login/verify-2fa.php',
    ];
    foreach ($allowedSuffixes as $suffix) {
        if (str_ends_with($scriptPath, $suffix)) {
            return;
        }
    }

    $role = function_exists('getCurrentUserRoleKey') ? getCurrentUserRoleKey() : '';
    if ($role === 'admin') {
        return;
    }

    // End session (auto-logout) then show maintenance immediately
    if (!empty($_SESSION['user_id'])) {
        require_once __DIR__ . '/authentication.php';
        if (function_exists('logout')) {
            logout();
        } else {
            $_SESSION = [];
        }
    }

    if (!headers_sent()) {
        require_once __DIR__ . '/../config/config.php';
        header('Location: ' . BASE_URL . '/account/maintenance.php');
        exit;
    }
}

function smsNormalizeModuleControlKey(string $moduleKey): string
{
    $moduleKey = strtolower(trim($moduleKey));
    if ($moduleKey === 'student-portal') {
        $moduleKey = 'student_portal';
    }
    if (in_array($moduleKey, ['crud', 'crowd'], true)) {
        $moduleKey = 'crad';
    }
    return preg_replace('/[^a-z0-9_\-]/', '', $moduleKey) ?? '';
}

function smsModuleMaintenanceSettingKey(string $moduleKey): string
{
    return 'module_maintenance_' . smsNormalizeModuleControlKey($moduleKey);
}

function smsModuleMaintenanceMsgKey(string $moduleKey): string
{
    return 'module_maintenance_msg_' . smsNormalizeModuleControlKey($moduleKey);
}

function smsModuleKickEpochKey(string $moduleKey): string
{
    return 'module_kick_epoch_' . smsNormalizeModuleControlKey($moduleKey);
}

function smsIsModuleInMaintenance(string $moduleKey): bool
{
    $key = smsNormalizeModuleControlKey($moduleKey);
    if ($key === '' || $key === 'user-management' || $key === 'dashboard') {
        return false;
    }
    return smsSetting(smsModuleMaintenanceSettingKey($key), '0') === '1';
}

function smsModuleMaintenanceMessage(string $moduleKey): string
{
    $key = smsNormalizeModuleControlKey($moduleKey);
    $custom = trim(smsSetting(smsModuleMaintenanceMsgKey($key), ''));
    if ($custom !== '') {
        return $custom;
    }
    $label = function_exists('smsModuleLabel') ? smsModuleLabel($key) : $key;
    return $label . ' is temporarily unavailable for maintenance. Please try again later.';
}

function smsSetModuleMaintenance(string $moduleKey, bool $enabled, string $message = ''): bool
{
    $key = smsNormalizeModuleControlKey($moduleKey);
    if ($key === '') {
        return false;
    }
    $ok = smsSetSetting(smsModuleMaintenanceSettingKey($key), $enabled ? '1' : '0');
    if ($message !== '') {
        smsSetSetting(smsModuleMaintenanceMsgKey($key), mb_substr(trim($message), 0, 500));
    }
    return $ok;
}

function smsModuleKickEpoch(string $moduleKey): int
{
    return (int) smsSetting(smsModuleKickEpochKey($moduleKey), '0');
}

function smsUserKickEpochKey(int $userId): string
{
    return 'user_kick_epoch_' . max(0, $userId);
}

function smsUserKickEpoch(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    return (int) smsSetting(smsUserKickEpochKey($userId), '0');
}

/**
 * Atomically advance a user's session generation.
 *
 * The value remains timestamp-compatible for legacy sessions, while new
 * sessions compare the exact generation captured at login.  This removes the
 * same-second race without adding a second session store or changing schema.
 */
function smsBumpUserKickEpoch(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid user ID is required for session revocation.');
    }
    $key = smsUserKickEpochKey($userId);
    $floor = time() + 1;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value =
             CAST(MAX(CAST(system_settings.setting_value AS INTEGER) + 1, CAST(excluded.setting_value AS INTEGER)) AS TEXT)'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value =
             CAST(GREATEST(CAST(setting_value AS UNSIGNED) + 1, CAST(VALUES(setting_value) AS UNSIGNED)) AS CHAR)'
        );
    }
    $stmt->execute([$key, (string) $floor]);
    $read = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $read->execute([$key]);
    $generation = (int) $read->fetchColumn();
    if ($generation <= 0) {
        throw new RuntimeException('Unable to advance the user session generation.');
    }
    if (!isset($GLOBALS['__sms_settings_cache']) || !is_array($GLOBALS['__sms_settings_cache'])) {
        $GLOBALS['__sms_settings_cache'] = [];
    }
    $GLOBALS['__sms_settings_cache'][$key] = (string) $generation;
    return $generation;
}

function smsUserSessionGenerationRevoked(?int $sessionGeneration, int $currentGeneration, int $loginAt): bool
{
    if ($sessionGeneration !== null) {
        return $sessionGeneration !== $currentGeneration;
    }
    return $currentGeneration > 0 && $currentGeneration > $loginAt;
}

/** Bump epoch so module users with older sessions must sign in again. */
function smsForceLogoutModuleUsers(string $moduleKey): int
{
    $key = smsNormalizeModuleControlKey($moduleKey);
    if ($key === '') {
        return 0;
    }
    $epoch = time();
    smsSetSetting(smsModuleKickEpochKey($key), (string) $epoch);
    return $epoch;
}

/**
 * Force-logout specific users only (picked logout).
 * Returns how many user kick epochs were written.
 *
 * @param list<int> $userIds
 */
function smsForceLogoutUsers(array $userIds, ?PDO $pdo = null): int
{
    $pdo ??= db();
    if (!$pdo) {
        return 0;
    }
    $count = 0;
    foreach ($userIds as $rawId) {
        $userId = (int) $rawId;
        if ($userId <= 0) {
            continue;
        }
        smsBumpUserKickEpoch($pdo, $userId);
        $count++;
    }
    return $count;
}

/**
 * Whether this role should be kicked when a module force-logout fires.
 */
function smsRoleTiedToModule(string $roleKey, string $moduleKey): bool
{
    $roleKey = strtolower(trim($roleKey));
    $moduleKey = smsNormalizeModuleControlKey($moduleKey);
    if ($roleKey === '' || $moduleKey === '' || $roleKey === 'admin') {
        return false;
    }
    if (function_exists('smsPrimaryModuleForRole') && smsPrimaryModuleForRole($roleKey) === $moduleKey) {
        return true;
    }
    if (!function_exists('smsRolesForModule')) {
        return false;
    }
    $roles = smsRolesForModule($moduleKey);
    return in_array($roleKey, $roles, true);
}

/**
 * End session if Super Admin forced logout for this user (picked) or their module (all).
 */
function smsEnforceModuleForceLogout(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $roleKey = (string) ($_SESSION['user_role_key'] ?? '');
    if ($roleKey === 'admin') {
        return;
    }

    $loginAt = (int) ($_SESSION['login_at'] ?? 0);
    // Legacy sessions without login_at must still respect kicks (do not stamp "now")

    $userId = (int) $_SESSION['user_id'];
    $shouldKick = false;

    // Picked logout — only this account
    $userEpoch = smsUserKickEpoch($userId);
    $sessionGeneration = array_key_exists('user_kick_epoch', $_SESSION)
        ? (int) $_SESSION['user_kick_epoch']
        : null;
    $shouldKick = smsUserSessionGenerationRevoked($sessionGeneration, $userEpoch, $loginAt);

    // Module-wide logout — all roles tied to the module
    if (!$shouldKick) {
        $modules = [];
        if (function_exists('smsPrimaryModuleForRole')) {
            $modules[] = smsPrimaryModuleForRole($roleKey);
        }
        if (function_exists('getAllowedModuleKeys')) {
            foreach (getAllowedModuleKeys() as $m) {
                $modules[] = (string) $m;
            }
        }
        $modules = array_values(array_unique(array_filter($modules)));

        foreach ($modules as $mod) {
            if (!smsRoleTiedToModule($roleKey, $mod)) {
                continue;
            }
            $epoch = smsModuleKickEpoch($mod);
            if ($epoch > 0 && $epoch > $loginAt) {
                $shouldKick = true;
                break;
            }
        }
    }

    if (!$shouldKick) {
        // Legacy sessions: stamp login_at only after confirming no kick is pending
        if ($loginAt <= 0) {
            $_SESSION['login_at'] = time();
        }
        return;
    }

    require_once __DIR__ . '/authentication.php';
    if (function_exists('logout')) {
        logout();
    } else {
        $_SESSION = [];
    }
    if (!headers_sent()) {
        require_once __DIR__ . '/../config/config.php';
        header('Location: ' . BASE_URL . '/login/login.php?forced=1');
        exit;
    }
}

/**
 * If this user’s primary module is in maintenance, park them on the
 * maintenance page until it is turned off (no dashboard / module escape).
 */
function smsEnforcePrimaryModuleMaintenance(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $roleKey = (string) ($_SESSION['user_role_key'] ?? '');
    if ($roleKey === '' || $roleKey === 'admin') {
        return;
    }

    if (!function_exists('smsPrimaryModuleForRole')) {
        require_once __DIR__ . '/security-workflow.php';
    }
    $primary = function_exists('smsPrimaryModuleForRole')
        ? smsNormalizeModuleControlKey((string) smsPrimaryModuleForRole($roleKey))
        : '';
    if ($primary === '' || $primary === 'user-management' || $primary === 'system') {
        return;
    }
    if (!smsIsModuleInMaintenance($primary)) {
        return;
    }

    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (
        str_ends_with($scriptPath, '/account/module-unavailable.php')
        || str_ends_with($scriptPath, '/login/logout.php')
        || str_ends_with($scriptPath, '/account/maintenance.php')
    ) {
        return;
    }

    if (!headers_sent()) {
        require_once __DIR__ . '/../config/config.php';
        header('Location: ' . BASE_URL . '/account/module-unavailable.php?module=' . rawurlencode($primary));
        exit;
    }
}
