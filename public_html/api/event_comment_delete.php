<?php
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) { $_SESSION['flash_error'] = 'Giriş yapmalısınız'; header('Location: ' . BASE_PATH . '/giris'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_PATH . '/events.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . BASE_PATH . '/events.php'); exit; }

$comment_id = (int)($_POST['comment_id'] ?? 0);
$event_id = (int)($_POST['event_id'] ?? 0);
if (!$comment_id || !$event_id) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . event_view_url($event_id, '')); exit; }

$pdo = db_connect();

$stmt = $pdo->prepare("SELECT id, user_id, parent_id, image_path, deleted_at FROM events_comments WHERE id = ? LIMIT 1");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$comment || !empty($comment['deleted_at'])) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . event_view_url($event_id, ''));
    exit;
}

if ($comment['user_id'] != $user_id && !is_admin()) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . event_view_url($event_id, ''));
    exit;
}

// Soft-delete only. Keep the row so nested replies remain visible under a deleted placeholder.
// Image files are retained while children may still exist; content is hidden by the template.
$del = $pdo->prepare("UPDATE events_comments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
$del->execute([$comment_id]);

// Decrement parent replies_count when this was a nested reply.
if (!empty($comment['parent_id'])) {
    try {
        $pdo->prepare("UPDATE events_comments SET replies_count = GREATEST(0, replies_count - 1) WHERE id = ?")
            ->execute([(int)$comment['parent_id']]);
    } catch (Exception $e) {
        // column may not exist in older schemas
    }
}

error_log('api/event_comment_delete.php: soft-deleted comment id=' . intval($comment_id) . ' by user=' . intval($user_id));

$_SESSION['flash'] = 'Yorum silindi.';
header('Location: ' . event_view_url($event_id, ''));
exit;
