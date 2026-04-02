<?php
namespace App\Service;

class TagService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function normalize(string $tag): string { return normalize_tag($tag); }
    public function recordClick(string $tag): void { record_tag_click($tag); }
    public function extractFromText(string $text): array { return extract_hashtags_from_text($text); }
    public function getTop(int $limit = 10): array { return get_top_tags($limit); }
    public function getTrendingForGroup(int $groupId, int $limit = 10): array { return get_trending_tags_for_group($groupId, $limit); }
}