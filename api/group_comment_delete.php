<?php
/**
 * API: Delete Group Comment
 */
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();

if (!$user_id) {
    $_SESSION['flash_error'] = 'Giriş yapmalısınız';
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Verify CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

$comment_id = (int)($_POST['comment_id'] ?? 0);
$post_id = (int)($_POST['post_id'] ?? 0);

if (!$comment_id || !$post_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Get comment
$stmt = query("SELECT c.*, gp.group_id, g.slug 
               FROM group_post_comments c 
               JOIN group_posts gp ON c.post_id = gp.id
               JOIN groups_table g ON gp.group_id = g.id
               WHERE c.id = ?", [$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Check ownership
if ($comment['user_id'] != $user_id) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

// Delete comment
$stmt = query("DELETE FROM group_post_comments WHERE id = ?", [$comment_id]);

$_SESSION['flash'] = 'Yorumunuz silindi';
header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
exit;
