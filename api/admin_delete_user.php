<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$admin = get_user($admin_id);
if (!$admin || !admin_has_perm($admin_id, 'manage_users')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/admin/pending_users.php');

if ($user_id > 0) {
    admin_delete_user($admin_id, $user_id);
    log_admin_action('delete_user', 'deleted user_id=' . $user_id, $admin_id);
}

header('Location: ' . $referer);
exit;
?>