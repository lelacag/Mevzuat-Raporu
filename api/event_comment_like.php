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

$cCheck = $pdo->prepare("SELECT id, deleted_at FROM events_comments WHERE id = ? LIMIT 1");
$cCheck->execute([$comment_id]);
$target = $cCheck->fetch(PDO::FETCH_ASSOC);
if (!$target || !empty($target['deleted_at'])) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . event_view_url($event_id, '') . '#comment-' . $comment_id);
    exit;
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
    $redirect = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? event_view_url($event_id, '') . '#comment-' . $comment_id);
    $_SESSION['flash'] = 'Yorum beğenisi kaldırıldı.';
    header('Location: ' . $redirect);
    exit;
} else {
    $ins = $pdo->prepare("INSERT INTO event_comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())");
    $ins->execute([$comment_id, $user_id]);
    $pdo->prepare("UPDATE events_comments SET likes_count = likes_count + 1 WHERE id = ?")->execute([$comment_id]);
    $redirect = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? event_view_url($event_id, '') . '#comment-' . $comment_id);
    $_SESSION['flash'] = 'Yorum beğenildi.';
    header('Location: ' . $redirect);
    exit;
}
