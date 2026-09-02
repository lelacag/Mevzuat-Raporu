<?php
/**
 * API: Edit Group Comment
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
$content = trim($_POST['content'] ?? '');

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
               WHERE c.id = ? AND c.deleted_at IS NULL", [$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Check ownership
if ($comment['user_id'] != $user_id) {
    $_SESSION['flash_error'] = 'Bu yorumu düzenleme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

// Validate content
if (empty($content)) {
    $_SESSION['flash_error'] = 'Yorum içeriği boş olamaz';
    header('Location: ' . BASE_PATH . '/group_comment_edit.php?id=' . $comment_id . '&post_id=' . $post_id);
    exit;
}

// Censor bad words
$censored = censor_bad_words($content);
$filtered_content = $censored['clean'];

// Update comment
$stmt = query("UPDATE group_post_comments SET content = ?, updated_at = NOW() WHERE id = ?", [$filtered_content, $comment_id]);

$_SESSION['flash'] = 'Yorumunuz güncellendi';
header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
exit;
