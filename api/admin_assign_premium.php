<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Form-only admin endpoint for assigning premium
if (!is_logged_in() || !admin_has_perm(null, 'manage_billing')) {
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
$plan_type = $_POST['plan_type'] ?? 'yearly';

$db = db_connect();

try {
    $db->beginTransaction();
    
    // Calculate end date based on plan type
    $start_date = date('Y-m-d H:i:s');
    if ($plan_type === 'monthly') {
        $end_date = date('Y-m-d H:i:s', strtotime('+1 month'));
    } elseif ($plan_type === 'yearly') {
        $end_date = date('Y-m-d H:i:s', strtotime('+1 year'));
    } else {
        $end_date = date('Y-m-d H:i:s', strtotime('+100 years')); // Lifetime
    }
    
    // Update user premium status and promote role to member
    $stmt = $db->prepare("UPDATE users SET is_premium = 1, premium_until = ?, role = 'member' WHERE id = ?");
    $stmt->execute([$end_date, $user_id]);
    
    // Create subscription record (amount field removed from schema support)
    $stmt = $db->prepare("INSERT INTO premium_subscriptions (user_id, plan_type, status, start_date, end_date) VALUES (?, ?, 'active', ?, ?)");
    $stmt->execute([$user_id, $plan_type, $start_date, $end_date]);
    
    $db->commit();
    
    // Audit and redirect
    log_admin_action('assign_premium', 'assigned ' . $plan_type . ' premium to user_id=' . $user_id, get_current_user_id());
    $_SESSION['flash'] = 'Premium assigned successfully';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash_error'] = 'Database error: ' . $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}
