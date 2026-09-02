<?php
/**
 * Module: admin.php — Admin-only operations (suspend, delete, reports, etc.)
 */

if (!function_exists('admin_suspend_user')) {
function admin_suspend_user($admin_id, $user_id, $days = 30, $reason = null) {
    $until = date('Y-m-d H:i:s', strtotime("+" . intval($days) . " days"));
    query("UPDATE users SET suspended_until = ? WHERE id = ?", [$until, $user_id]);
    create_notification($user_id, 'suspended', $admin_id, null);
}
}

if (!function_exists('admin_unsuspend_user')) {
function admin_unsuspend_user($admin_id, $user_id) {
    query("UPDATE users SET suspended_until = NULL WHERE id = ?", [$user_id]);
    create_notification($user_id, 'unsuspended', $admin_id, null);
}
}

if (!function_exists('admin_delete_user')) {
function admin_delete_user($admin_id, $user_id) {
    $pdo = db_connect();
    $pdo->beginTransaction();
    try {
        query("UPDATE users SET deleted_at = NOW() WHERE id = ?", [$user_id]);
        query("UPDATE posts SET deleted_at = NOW() WHERE user_id = ?", [$user_id]);
        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
}

if (!function_exists('admin_resolve_report')) {
function admin_resolve_report($admin_id, $report_id) {
    query("UPDATE reports SET status = 'resolved' WHERE id = ?", [$report_id]);
}
}

if (!function_exists('admin_delete_post')) {
function admin_delete_post($admin_id, $post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return ['error' => 'not_found'];
    }
    // Soft-delete only; replies with children remain as deleted placeholders in the thread.
    query("UPDATE posts SET deleted_at = NOW() WHERE id = ?", [$post_id]);
    if (!empty($post['parent_id'])) {
        query("UPDATE posts SET replies_count = GREATEST(0, replies_count - 1) WHERE id = ?", [$post['parent_id']]);
    }
    create_notification($post['user_id'], 'report', $admin_id, $post_id);
    query("UPDATE reports SET status = 'resolved' WHERE target_id = ? AND (target_type = 'post' OR target_type = 'reply')", [$post_id]);
    return true;
}
}

if (!function_exists('get_reports')) {
function get_reports($status = null, $limit = 200) {
    $sql = "SELECT r.*, u.username as reporter_username, p.content as target_content, p.user_id AS target_user_id, p.deleted_at as target_deleted_at FROM reports r LEFT JOIN users u ON r.reporter_id = u.id LEFT JOIN posts p ON r.target_id = p.id";
    $params = [];
    if ($status === 'open') $sql .= " WHERE (r.status IS NULL OR r.status = 'open')";
    elseif ($status === 'resolved') $sql .= " WHERE r.status = 'resolved'";
    $sql .= " ORDER BY r.created_at DESC LIMIT ?";
    $params[] = $limit;
    return query($sql, $params)->fetchAll();
}
}

if (!function_exists('get_deleted_post_reports')) {
function get_deleted_post_reports($year = null, $month = null, $limit = 500) {
    $sql = "SELECT r.*, u.username as reporter_username, p.content as target_content, p.user_id AS target_user_id, p.deleted_at as target_deleted_at FROM reports r LEFT JOIN users u ON r.reporter_id = u.id LEFT JOIN posts p ON r.target_id = p.id WHERE p.deleted_at IS NOT NULL";
    $params = [];
    if ($year !== null) { $sql .= " AND YEAR(p.deleted_at) = ?"; $params[] = intval($year); }
    if ($month !== null) { $sql .= " AND MONTH(p.deleted_at) = ?"; $params[] = intval($month); }
    $sql .= " ORDER BY p.deleted_at DESC LIMIT ?";
    $params[] = $limit;
    return query($sql, $params)->fetchAll();
}
}

if (!function_exists('get_deleted_post_months')) {
function get_deleted_post_months() {
    return query("SELECT YEAR(deleted_at) AS y, MONTH(deleted_at) AS m, COUNT(*) AS c FROM posts WHERE deleted_at IS NOT NULL GROUP BY y, m ORDER BY y DESC, m DESC")->fetchAll();
}
}

if (!function_exists('format_time')) {
function format_time($timestamp) {
    $tzName = date_default_timezone_get() ?: 'Europe/Istanbul';
    $tz = new DateTimeZone($tzName);
    try {
        $dt = new DateTimeImmutable($timestamp, $tz);
    } catch (Exception $e) {
        $ts = @strtotime($timestamp);
        if ($ts === false) $ts = time();
        $dt = (new DateTimeImmutable())->setTimestamp($ts)->setTimezone($tz);
    }
    $now = new DateTimeImmutable('now', $tz);
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 60) return 'az önce';
    elseif ($diff < 3600) return floor($diff / 60) . ' dakika önce';
    elseif ($diff < 86400) return floor($diff / 3600) . ' saat önce';
    elseif ($diff < 604800) return floor($diff / 86400) . ' gün önce';
    else return $dt->format('d.m.Y H:i');
}
}

if (!function_exists('column_exists')) {
function column_exists($table, $column) {
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace("`","",$table) . "` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log('column_exists error: ' . $e->getMessage());
        return false;
    }
}
}
