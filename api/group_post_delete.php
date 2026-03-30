<?php
/**
 * Delete a group post
 */
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token']))) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    $referer = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/groups.php'));
    $referer = validate_referer($referer, BASE_PATH . '/groups.php', false);
    header('Location: ' . $referer);
    exit;
}

$post_id = intval($_POST['post_id'] ?? 0);
$referer = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/groups.php'));

if ($post_id) {
    // Check if user owns this post or is admin
    $stmt = query("SELECT gp.*, g.created_by as group_owner FROM group_posts gp 
                   JOIN groups_table g ON gp.group_id = g.id 
                   WHERE gp.id = ?", [$post_id]);
    $post = $stmt->fetch();
    
    if ($post && ($post['user_id'] == $user_id || $post['group_owner'] == $user_id)) {
        // Delete post
        query("DELETE FROM group_posts WHERE id = ?", [$post_id]);
        $_SESSION['flash'] = 'Gönderi silindi.';
    } else {
        $_SESSION['flash_error'] = 'Bu gönderiyi silme yetkiniz yok.';
    }
}

header('Location: ' . $referer);
exit;
?>
