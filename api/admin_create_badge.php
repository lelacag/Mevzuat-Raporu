<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$admin = get_user($admin_id);
if (!$admin || !admin_has_perm($admin_id, 'manage_badges')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// Only accept POST from admin UI and require CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}

$name = trim($_POST['name'] ?? '');
$slug = trim($_POST['slug'] ?? '');
$min_likes = intval($_POST['min_likes'] ?? 0);
$description = trim($_POST['description'] ?? '');

if ($name && $slug) {
    create_badge($name, $slug, $description, $min_likes);
    log_admin_action('create_badge', 'created badge=' . $slug, $admin_id);
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/admin/badges.php';
$referer = validate_referer($referer, BASE_PATH . '/admin/badges.php', true);
header('Location: ' . $referer);
exit;
?>