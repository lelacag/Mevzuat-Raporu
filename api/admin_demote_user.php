<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in() || !admin_has_perm(null, 'manage_users')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['user_id'])) {
    $_SESSION['flash_error'] = 'User ID is required';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

$user_id = intval($_POST['user_id']);
$db = db_connect();
try {
    $stmt = $db->prepare("UPDATE users SET role = 'rookie' WHERE id = ?");
    $stmt->execute([$user_id]);
    $_SESSION['flash'] = 'User demoted to rookie successfully';
    log_admin_action('demote_user', 'demoted user_id=' . $user_id, get_current_user_id());
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Database error: ' . $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}
