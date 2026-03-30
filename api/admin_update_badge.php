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
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}

$id = intval($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$slug = trim($_POST['slug'] ?? '');
$min_likes = intval($_POST['min_likes'] ?? 0);
$description = trim($_POST['description'] ?? '');

if ($id && $name && $slug) {
    update_badge($id, $name, $slug, $description, $min_likes);
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/admin/badges.php';
header('Location: ' . $referer);
exit;
?>