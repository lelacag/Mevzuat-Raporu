<?php
/**
 * API: Like/Unlike Group Comment
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

if (!$comment_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Check if comment exists
$stmt = query("SELECT id FROM group_post_comments WHERE id = ?", [$comment_id]);
if (!$stmt->fetch()) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Check if already liked
$stmt = query("SELECT id FROM group_comment_likes WHERE comment_id = ? AND user_id = ?", [$comment_id, $user_id]);
$existing = $stmt->fetch();

if ($existing) {
    // Unlike
    query("DELETE FROM group_comment_likes WHERE comment_id = ? AND user_id = ?", [$comment_id, $user_id]);
} else {
    // Like
    query("INSERT INTO group_comment_likes (comment_id, user_id) VALUES (?, ?)", [$comment_id, $user_id]);
}

// Redirect back
if ($post_id) {
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
} else {
    header('Location: ' . BASE_PATH . '/groups.php');
}
exit;
