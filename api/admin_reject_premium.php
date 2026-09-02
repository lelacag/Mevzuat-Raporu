<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in() || !admin_has_perm(null, 'manage_billing')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['subscription_id'])) {
    $_SESSION['flash_error'] = 'Eksik parametre';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}

$subscription_id = intval($_POST['subscription_id']);
$db = db_connect();

try {
    $stmt = $db->prepare("UPDATE premium_subscriptions SET status = 'rejected' WHERE id = ?");
    $stmt->execute([$subscription_id]);
    log_admin_action('reject_premium', 'subscription_id=' . $subscription_id, get_current_user_id());
    $_SESSION['flash'] = 'Subscription rejected';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Database error';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}
