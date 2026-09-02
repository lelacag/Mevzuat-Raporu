<?php
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) { $_SESSION['flash_error'] = 'Giriş yapmalısınız'; header('Location: ' . BASE_PATH . '/giris'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_PATH . '/events.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . BASE_PATH . '/events.php'); exit; }

$comment_id = (int)($_POST['comment_id'] ?? 0);
$event_id = (int)($_POST['event_id'] ?? 0);
$content = trim($_POST['content'] ?? '');
if (!$comment_id || !$event_id || $content === '') { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . event_view_url($event_id, '')); exit; }

$pdo = db_connect();
error_log('api/event_comment_edit.php: user=' . get_current_user_id() . ' comment_id=' . intval($comment_id) . ' event_id=' . intval($event_id));
$stmt = $pdo->prepare("SELECT user_id, deleted_at FROM events_comments WHERE id = ? LIMIT 1");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$comment || !empty($comment['deleted_at'])) { error_log('api/event_comment_edit.php: comment not found id=' . intval($comment_id)); $_SESSION['flash_error'] = 'Yorum bulunamadı'; header('Location: ' . event_view_url($event_id, '')); exit; }

if ($comment['user_id'] != $user_id && !is_admin()) {
    $_SESSION['flash_error'] = 'Bu yorumu düzenleme yetkiniz yok';
    header('Location: ' . event_view_url($event_id, ''));
    exit;
}

$upd = $pdo->prepare("UPDATE events_comments SET content = ?, edited_at = NOW() WHERE id = ?");
$upd->execute([$content, $comment_id]);

$_SESSION['flash'] = 'Yorum güncellendi.';
header('Location: ' . event_view_url($event_id, '') . '#comment-' . $comment_id);
exit;