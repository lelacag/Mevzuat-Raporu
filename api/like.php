<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

require_csrf();

$post_id = intval($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_favorite_action = $action === 'favorite' || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/favorite.php') !== false);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php');
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

if ($post_id) {
    $post_link = post_url($post_id);
    $post_label = htmlspecialchars('#' . $post_id, ENT_QUOTES, 'UTF-8');

    if ($is_favorite_action) {
        if (favorites_table_exists()) {
            $favorited = toggle_favorite($user_id, $post_id);
            if ($favorited) {
                $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> favorilere eklendi.';
            } else {
                $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> favorilerden çıkarıldı.';
            }
        } else {
            $_SESSION['flash'] = '<strong>⚠️</strong> Favoriler özelliği etkin değil.';
        }
    } else {
        $liked = toggle_like($user_id, $post_id);

        // Flash notification for successful like interaction (like/unlike gives feedback)
        if ($liked) {
            $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> için beğeniniz gönderildi.';
        } else {
            $_SESSION['flash'] = '<strong>✔</strong> <a href="' . htmlspecialchars($post_link, ENT_QUOTES, 'UTF-8') . '">' . $post_label . '</a> için beğeniniz geri alındı.';
        }
    }
}

header('Location: ' . $referer);
exit;
?>

