<?php
namespace App\Service;

class SocialService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function follow(int $followerId, int $followingId): bool { return (bool) follow_user($followerId, $followingId); }
    public function getFollowedIds(int $userId): array { return get_followed_user_ids($userId); }
    public function getFriendSuggestions(int $userId, int $limit = 5): array { return get_friend_suggestions($userId, $limit); }
    public function getLikesReceived(int $userId): int { return (int) get_likes_received($userId); }
}