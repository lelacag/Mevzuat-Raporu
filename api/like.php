<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$post_id = $_POST['post_id'] ?? $_GET['post_id'] ?? 0;
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php');
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

if ($post_id) {
    $liked = toggle_like($user_id, $post_id);

    // Flash notification for successful like interaction (like/unlike gives feedback)
    $post_link = post_url($post_id);
    $post_label = htmlspecialchars('#' . (int)$post_id, ENT_QUOTES, 'UTF-8');
    if ($liked) {
        $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> için beğeniniz gönderildi.';
    } else {
        $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> için beğeniniz geri alındı.';
    }
}

header('Location: ' . $referer);
exit;
?>

