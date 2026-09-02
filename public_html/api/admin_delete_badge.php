<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/giris');
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

$badge_id = intval($_POST['badge_id'] ?? 0);
if ($badge_id) {
    delete_badge($badge_id);
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/admin/badges.php';
$referer = validate_referer($referer, BASE_PATH . '/admin/badges.php', true);
header('Location: ' . $referer);
exit;
?>