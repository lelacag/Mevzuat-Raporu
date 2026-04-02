<?php
namespace App\Service;

class AdminService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function suspendUser(int $adminId, int $userId, int $days = 30, ?string $reason = null): bool { return (bool) admin_suspend_user($adminId, $userId, $days, $reason); }
    public function unsuspendUser(int $adminId, int $userId): bool { return (bool) admin_unsuspend_user($adminId, $userId); }
    public function deleteUser(int $adminId, int $userId): bool { return (bool) admin_delete_user($adminId, $userId); }
    public function deletePost(int $adminId, int $postId): bool { return (bool) admin_delete_post($adminId, $postId); }
    public function resolveReport(int $adminId, int $reportId): bool { return (bool) admin_resolve_report($adminId, $reportId); }
    public function getReports(?string $status = null, int $limit = 200): array { return get_reports($status, $limit); }
    public function getDeletedPostReports(?int $year = null, ?int $month = null, int $limit = 500): array { return get_deleted_post_reports($year, $month, $limit); }
    public function getDeletedPostMonths(): array { return get_deleted_post_months(); }
    public function blockIp(string $ip): bool { return (bool) block_ip($ip); }
    public function isIpBlocked(string $ip): bool { return (bool) is_ip_blocked($ip); }
    public function logAction(int $adminId, string $action, ?string $target = null): void { log_admin_action($adminId, $action, $target); }
    public function getApprovedWords(): array { return function_exists('get_approved_words') ? get_approved_words() : []; }
    public function deleteApprovedWord(int $id): bool { return function_exists('delete_approved_word') ? (bool) delete_approved_word($id) : false; }
    public function getPendingPosts(int $limit = 50): array { return function_exists('get_pending_posts') ? get_pending_posts($limit) : []; }
    public function approvePostReview(int $postId, int $adminId, array $words = []): bool { return function_exists('approve_post_review') ? (bool) approve_post_review($postId, $adminId, $words) : false; }
    public function columnExists(string $table, string $column): bool { return (bool) column_exists($table, $column); }
}