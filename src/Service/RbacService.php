<?php
namespace App\Service;

class RbacService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function isReservedUsername(string $u): bool { return (bool) is_reserved_username($u); }
    public function getAllRoles(): array { return get_all_roles(); }
    public function getAllPermissions(): array { return get_all_permissions(); }
    public function getRoleByKey(string $key): ?array { return get_role_by_key($key) ?: null; }
    public function getRolePermissions(int $roleId): array { return get_role_permissions($roleId); }
    public function getUserRoles(int $userId): array { return get_user_roles($userId); }
    public function getUserPrimaryRole(int $userId): ?array { return get_user_primary_role($userId) ?: null; }
    public function userHasPermission(int $userId, string $perm): bool {
        foreach ($this->getUserRoles($userId) as $r) {
            $perms = $this->getRolePermissions($r['id'] ?? $r['role_id'] ?? 0);
            if (isset($perms[$perm])) return true;
        }
        return false;
    }
}