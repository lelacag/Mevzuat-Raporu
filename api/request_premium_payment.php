<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_PATH . "/premium.php");
    exit;
}

// CSRF protection
if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    header("Location: " . BASE_PATH . "/premium.php");
    exit;
}

$plan_type = $_POST['plan_type'] ?? 'yearly';
$email = $_POST['email'] ?? '';

$valid_plans = ['monthly', 'yearly', 'lifetime'];
if (!in_array($plan_type, $valid_plans)) {
    $_SESSION['flash_error'] = 'Geçersiz plan seçimi';
    header("Location: " . BASE_PATH . "/premium.php");
    exit;
}

$db = db_connect();

try {
    // Create pending subscription request (amount field removed from schema support)
    $stmt = $db->prepare("
        INSERT INTO premium_subscriptions (user_id, plan_type, status, created_at) 
        VALUES (?, ?, 'pending', NOW())
    ");
    $stmt->execute([$user_id, $plan_type]);
    
    $_SESSION['flash_success'] = 'Ödeme talebiniz alındı! Email adresinize ödeme bilgileri gönderildi. Ödeme yaptıktan sonra dekont gönderin ve admin onayını bekleyin.';
    header("Location: " . BASE_PATH . "/premium.php");
    
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Bir hata oluştu. Lütfen tekrar deneyin.';
    header("Location: " . BASE_PATH . "/premium_payment.php?plan=" . $plan_type);
}
