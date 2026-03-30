<?php
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) { $_SESSION['flash_error'] = 'Giriş yapmalısınız'; header('Location: ' . BASE_PATH . '/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_PATH . '/events.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . BASE_PATH . '/events.php'); exit; }

$comment_id = (int)($_POST['comment_id'] ?? 0);
$event_id = (int)($_POST['event_id'] ?? 0);
if (!$comment_id || !$event_id) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id); exit; }

$pdo = db_connect();
// Defensive: ensure `image_path` column exists (older DBs may be missing it)
try {
    $col = $pdo->query("SHOW COLUMNS FROM events_comments LIKE 'image_path'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE events_comments ADD COLUMN image_path VARCHAR(1024) DEFAULT NULL");
    }
} catch (Exception $e) {
    // ignore - SELECT will fail below with helpful message if table missing
}

$stmt = $pdo->prepare("SELECT user_id, image_path FROM events_comments WHERE id = ? LIMIT 1");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$comment) { $_SESSION['flash_error'] = 'Yorum bulunamadı'; header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id); exit; }

if ($comment['user_id'] != $user_id && !is_admin()) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id);
    exit;
}

// delete image file if stored permanently
if (!empty($comment['image_path']) && strpos($comment['image_path'], '/assets/uploads/') === 0) {
    $fp = __DIR__ . '/..' . $comment['image_path'];
    if (file_exists($fp)) @unlink($fp);
}

$del = $pdo->prepare("DELETE FROM events_comments WHERE id = ?");
$del->execute([$comment_id]);

error_log('api/event_comment_delete.php: deleted comment id=' . intval($comment_id) . ' by user=' . intval($user_id));

$_SESSION['flash'] = 'Yorum silindi.';
header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id);
exit;