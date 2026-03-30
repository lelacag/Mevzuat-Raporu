<?php
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) { header('HTTP/1.1 403 Forbidden'); echo 'login required'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('HTTP/1.1 405 Method Not Allowed'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { header('HTTP/1.1 400 Bad Request'); echo 'invalid request'; exit; }

$comment_id = (int)($_POST['comment_id'] ?? 0);
$event_id = (int)($_POST['event_id'] ?? 0);
if (!$comment_id || !$event_id) { header('HTTP/1.1 400 Bad Request'); echo 'invalid'; exit; }

$pdo = db_connect();
error_log('api/event_comment_like.php: user=' . $user_id . ' comment_id=' . intval($comment_id) . ' event_id=' . intval($event_id));
// ensure likes table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS event_comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comment_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comment (comment_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Defensive DB fix: ensure the `events_comments` table has the expected columns
try {
    $col = $pdo->query("SHOW COLUMNS FROM events_comments LIKE 'likes_count'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE events_comments ADD COLUMN likes_count INT NOT NULL DEFAULT 0");
    }
    $col2 = $pdo->query("SHOW COLUMNS FROM events_comments LIKE 'reports_count'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE events_comments ADD COLUMN reports_count INT NOT NULL DEFAULT 0");
    }
    $col3 = $pdo->query("SHOW COLUMNS FROM events_comments LIKE 'image_path'")->fetch();
    if (!$col3) {
        $pdo->exec("ALTER TABLE events_comments ADD COLUMN image_path VARCHAR(1024) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('api/event_comment_like.php: DB column check error: ' . $e->getMessage());
    // ignore - we still attempt the operation and will bubble an error if necessary
}

// toggle like: check if exists
$check = $pdo->prepare("SELECT id FROM event_comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1");
$check->execute([$comment_id, $user_id]);
$exists = $check->fetch(PDO::FETCH_ASSOC);

if ($exists) {
    $del = $pdo->prepare("DELETE FROM event_comment_likes WHERE id = ?");
    $del->execute([$exists['id']]);
    $pdo->prepare("UPDATE events_comments SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?")->execute([$comment_id]);
    // Prefer explicit referer; fall back to HTTP_REFERER or to the event detail page
    $redirect = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/event_view.php?id=' . $event_id . '#comment-' . $comment_id);
    $_SESSION['flash'] = 'Yorum beğenisi kaldırıldı.';
    header('Location: ' . $redirect);
    exit;
} else {
    $ins = $pdo->prepare("INSERT INTO event_comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())");
    $ins->execute([$comment_id, $user_id]);
    $pdo->prepare("UPDATE events_comments SET likes_count = likes_count + 1 WHERE id = ?")->execute([$comment_id]);
    $redirect = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/event_view.php?id=' . $event_id . '#comment-' . $comment_id);
    $_SESSION['flash'] = 'Yorum beğenildi.';
    header('Location: ' . $redirect);
    exit;
}
