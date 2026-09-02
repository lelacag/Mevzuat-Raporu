<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Accept form POST from admin UI (no JSON)
if (!is_logged_in() || !admin_has_perm(null, 'manage_billing')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['subscription_id']) || empty($_POST['user_id']) || empty($_POST['plan_type'])) {
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
$user_id = intval($_POST['user_id']);
$plan_type = $_POST['plan_type'];

$db = db_connect();

try {
    $db->beginTransaction();
    $start_date = date('Y-m-d H:i:s');
    if ($plan_type === 'monthly') {
        $end_date = date('Y-m-d H:i:s', strtotime('+1 month'));
    } elseif ($plan_type === 'yearly') {
        $end_date = date('Y-m-d H:i:s', strtotime('+1 year'));
    } else {
        $end_date = date('Y-m-d H:i:s', strtotime('+100 years')); // Lifetime
    }

    $stmt = $db->prepare("UPDATE premium_subscriptions SET status = 'active', start_date = ?, end_date = ? WHERE id = ?");
    $stmt->execute([$start_date, $end_date, $subscription_id]);

    $stmt = $db->prepare("UPDATE users SET is_premium = 1, premium_until = ?, role = 'member' WHERE id = ?");
    $stmt->execute([$end_date, $user_id]);

    $db->commit();
    log_admin_action('approve_premium', 'subscription_id=' . $subscription_id . ' user_id=' . $user_id, get_current_user_id());
    $_SESSION['flash'] = 'Subscription approved';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash_error'] = 'Database error';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php');
    exit;
}
