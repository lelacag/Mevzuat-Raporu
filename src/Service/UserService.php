<?php
namespace App\Service;

class UserService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function getById(int $userId): ?array { return get_user_by_id($userId) ?: null; }
    public function get(int $userId): ?array { return function_exists('get_user') ? (get_user($userId) ?: null) : $this->getById($userId); }
    public function getByUsername(string $username): ?array { return function_exists('get_user_by_username') ? (get_user_by_username($username) ?: null) : null; }
    public function getSlug(string $username): string { return get_user_slug($username); }
    public function isCreationRestricted(int $userId): bool { return is_user_creation_restricted($userId); }
    public function isPremium(int $userId): bool { return function_exists('is_premium') ? (bool) is_premium($userId) : false; }
    public function isSuspended(int $userId): bool { $u = $this->getById($userId); return $u && !empty($u['suspended_until']) && strtotime($u['suspended_until']) > time(); }
}