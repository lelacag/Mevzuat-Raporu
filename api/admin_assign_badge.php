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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badges.php');
    exit;
}

$badge_id = intval($_POST['badge_id'] ?? 0);
$username = trim($_POST['username'] ?? '');

if ($badge_id && $username) {
    $u = get_user_by_username($username);
    if ($u) {
        assign_badge_to_user($u['id'], $badge_id, $admin_id);
        log_admin_action('assign_badge', 'assigned badge_id=' . $badge_id . ' to user=' . $u['username'], $admin_id);
    }
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/admin/badges.php';
$referer = validate_referer($referer, BASE_PATH . '/admin/badges.php', true);
header('Location: ' . $referer);
exit;
?>