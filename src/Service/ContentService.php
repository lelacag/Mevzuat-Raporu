<?php
namespace App\Service;

class ContentService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    // Text rendering
    public function renderRichText(string $text): string { return render_rich_text($text); }
    public function linkifyMentions(string $text): string { return linkify_mentions($text); }
    public function linkifyText(string $text): string { return linkify_text($text); }
    public function badgeColorToClass(string $color): string { return badge_color_to_class($color); }

    // Diff
    public function renderDiff(string $old, string $new): string { return render_diff_html($old, $new); }
    public function renderDiffOld(string $old, string $new): string { return render_diff_old_html($old, $new); }
    public function renderDiffNew(string $old, string $new): string { return render_diff_new_html($old, $new); }

    // Slug
    public function generateSlug(string $text): string { return generate_slug($text); }

    // Drafts
    public function saveDraft(int $userId, string $content): void { save_draft($userId, $content); }
    public function getDraft(int $userId): string { return get_draft($userId) ?? ''; }
    public function insertTagIntoDraft(string $draft, string $tag): string { return insert_tag_into_text($draft, $tag); }
    public function insertTypeIntoDraft(int $userId, string $type, array $fields = []): string { return insert_type_or_append_to_draft($userId, $type, $fields); }
}