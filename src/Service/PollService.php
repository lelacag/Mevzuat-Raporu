<?php
namespace App\Service;

class PollService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function create(int $userId, string $title, ?int $postId = null, ?int $groupPostId = null, array $options = []): int|false { return create_poll($userId, $title, $postId, $groupPostId, $options); }
    public function vote(int $userId, int $pollId, int $optionId): bool { return (bool) vote_poll($userId, $pollId, $optionId); }
    public function getForPost(int $postId): ?array { return get_poll_for_post($postId) ?: null; }
    public function getForGroupPost(int $groupPostId): ?array { return get_poll_for_group_post($groupPostId) ?: null; }
}