<?php
namespace App\Service;

class GroupService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function getComments(int $postId, ?int $viewerId = null, ?int $parentId = null, int $depth = 0, int $maxDepth = 5): array { return get_group_comments($postId, $viewerId, $parentId, $depth, $maxDepth); }
    public function countComments(int $postId): int { return (int) count_group_comments($postId); }
    public function url(string $slug): string { return group_url($slug); }
    public function postUrl(string $slug, int $postId): string { return group_post_url($slug, $postId); }
    public function announcementUrl(string $slug, int $id, ?string $createdAt = null): string { return announcement_url($slug, $id, $createdAt); }
    public function trackActivity(int $userId): void { track_user_activity($userId); }
}