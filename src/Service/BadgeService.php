<?php
namespace App\Service;

class BadgeService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function create(string $name, string $slug, ?string $desc = null, int $minLikes = 0): int { return (int) create_badge($name, $slug, $desc, $minLikes); }
    public function update(int $id, string $name, string $slug, string $desc, int $minLikes): bool { return (bool) update_badge($id, $name, $slug, $desc, $minLikes); }
    public function delete(int $id): bool { return (bool) delete_badge($id); }
    public function getAll(int $limit = 100): array { return get_badges($limit); }
    public function getById(int $id): ?array { return get_badge($id) ?: null; }
    public function getUserBadges(int $userId): array { return get_user_badges($userId); }
    public function assign(int $userId, int $badgeId, ?int $assignedBy = null): bool { return (bool) assign_badge_to_user($userId, $badgeId, $assignedBy); }
    public function remove(int $userId, int $badgeId): bool { return (bool) remove_badge_from_user($userId, $badgeId); }
    public function syncByLikes(int $userId): void { sync_user_badges_by_likes($userId); }
    public function maybeSyncAfterLike(int $postId): void { maybe_sync_badges_after_like($postId); }
}