<?php

declare(strict_types=1);

final class PayMongoIntegrationSecurity
{
    /** @return array{id:int,name:string,role:string} */
    public static function requireActiveMisActor(PDO $corePdo): array
    {
        $userId = getCurrentUserId();
        if ($userId === null) throw new DomainException('AUTHENTICATION_REQUIRED');

        $stmt = $corePdo->prepare('SELECT id, full_name, role_key, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor || ($actor['status'] ?? '') !== 'active'
            || ($actor['role_key'] ?? '') !== 'mis_admin'
            || ($actor['role_key'] ?? '') !== getCurrentUserRoleKey()) {
            throw new DomainException('ACTOR_SESSION_STALE');
        }
        requirePaymentPermission('integration.paymongo.manage');
        return ['id' => (int) $actor['id'], 'name' => (string) $actor['full_name'], 'role' => 'mis_admin'];
    }
}
