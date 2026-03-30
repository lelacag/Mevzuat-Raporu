<?php
/**
 * Edit a group post
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
$content = trim($_POST['content'] ?? '');
$referer = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/groups.php'));
$referer = validate_referer($referer, BASE_PATH . '/groups.php', false);

if ($post_id && !empty($content)) {
    // Check if user owns this post
    $stmt = query("SELECT * FROM group_posts WHERE id = ? AND user_id = ?", [$post_id, $user_id]);
    $post = $stmt->fetch();
    
    if ($post) {
        // Censor bad words
        $censored = censor_bad_words($content);
        $filtered_content = $censored['clean'];
        
        // Update post
        query("UPDATE group_posts SET content = ?, updated_at = NOW() WHERE id = ?", [$filtered_content, $post_id]);
        $_SESSION['flash'] = 'Gönderi düzenlendi.';
    } else {
        $_SESSION['flash_error'] = 'Bu gönderiyi düzenleme yetkiniz yok.';
    }
}

header('Location: ' . $referer);
exit;
?>
