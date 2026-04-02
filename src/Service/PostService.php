<?php
namespace App\Service;

class PostService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function getById(int $postId): ?array { return get_post_by_id($postId) ?: null; }
    public function getPaginated(int $limit = 40, ?int $viewerId = null, ?string $after = null, ?string $before = null): array { return get_posts_paginated($limit, $viewerId, $after, $before); }
    public function getRelevant(?int $userId = null, int $limit = 40, ?string $after = null): array { return get_relevant_posts($userId, $limit, $after); }
    public function getRelevantPaginated(?int $userId = null, int $limit = 40, ?string $after = null): array { return get_relevant_posts_paginated($userId, $limit, $after); }
    public function getNewFeedCount(int $viewerId, string $since): int { return (int) get_new_feed_count($viewerId, $since); }
    public function getTrendingTags(int $limit = 10, ?int $userId = null): array { return get_trending_tags($limit, $userId); }
    public function getUserGroupPosts(int $userId, int $limit = 50, ?int $viewerId = null): array { return get_user_group_posts($userId, $limit, $viewerId); }
    public function create(int $userId, string $content, ?int $groupId = null): int|false { return function_exists('create_post') ? create_post($userId, $content, $groupId) : false; }
    public function edit(int $postId, string $content): bool { return function_exists('edit_post') ? edit_post($postId, $content) : false; }
    public function canEdit(int $postId, int $userId): bool { return function_exists('can_edit_post') ? can_edit_post($postId, $userId) : false; }
}