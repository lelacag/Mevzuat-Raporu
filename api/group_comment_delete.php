<?php
/**
 * API: Soft-delete Group Comment
 * Keeps the row as a deleted placeholder so nested replies remain visible.
 */
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();

if (!$user_id) {
    $_SESSION['flash_error'] = 'Giriş yapmalısınız';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Verify CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

$comment_id = (int)($_POST['comment_id'] ?? 0);
$post_id = (int)($_POST['post_id'] ?? 0);

if (!$comment_id || !$post_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Get comment
$stmt = query("SELECT c.*, gp.group_id, g.slug 
               FROM group_post_comments c 
               JOIN group_posts gp ON c.post_id = gp.id
               JOIN groups_table g ON gp.group_id = g.id
               WHERE c.id = ?", [$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment || !empty($comment['deleted_at'])) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Check ownership or admin
$is_admin = function_exists('is_admin') && is_admin();
if ($comment['user_id'] != $user_id && !$is_admin) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

// Soft-delete only. Nested replies stay attached to this placeholder node.
query("UPDATE group_post_comments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL", [$comment_id]);

$_SESSION['flash'] = 'Yorumunuz silindi';
$redirect = !empty($comment['slug'])
    ? (BASE_PATH . '/g/' . rawurlencode($comment['slug']) . '/post/' . (int)$post_id)
    : (BASE_PATH . '/group_post.php?id=' . (int)$post_id);
header('Location: ' . $redirect);
exit;
