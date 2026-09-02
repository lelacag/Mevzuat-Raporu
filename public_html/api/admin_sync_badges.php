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
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}

$stmt = query("SELECT id FROM users WHERE deleted_at IS NULL");
$users = $stmt->fetchAll();
foreach ($users as $u) {
    sync_user_badges_by_likes($u['id']);
}

log_admin_action('sync_badges', 'synchronized badges for all users', $admin_id);

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/admin/badges.php';
$referer = validate_referer($referer, BASE_PATH . '/admin/badges.php', true);
header('Location: ' . $referer);
exit;
?>