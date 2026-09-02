<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Permission + CSRF checks
if (!is_logged_in() || !admin_has_perm(null, 'manage_billing')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/premium_settings.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/premium_settings.php');
    exit;
}

// Validate and sanitize inputs
$monthly_price = isset($_POST['monthly_price']) ? number_format((float)$_POST['monthly_price'], 2, '.', '') : '5.00';
$yearly_price = isset($_POST['yearly_price']) ? number_format((float)$_POST['yearly_price'], 2, '.', '') : '50.00';
$lifetime_price = isset($_POST['lifetime_price']) ? number_format((float)$_POST['lifetime_price'], 2, '.', '') : '150.00';
$url_session_ttl = isset($_POST['url_session_ttl']) ? max(60, intval($_POST['url_session_ttl'])) : (defined('URL_SESSION_TTL') ? URL_SESSION_TTL : 3600);
$default_premium_badge = isset($_POST['default_premium_badge']) ? trim(substr($_POST['default_premium_badge'], 0, 64)) : '⭐ Premium';
$currency = isset($_POST['currency']) ? preg_replace('/[^A-Z]/', '', strtoupper(substr($_POST['currency'], 0, 8))) : 'USD';
$payment_email = isset($_POST['payment_email']) ? filter_var($_POST['payment_email'], FILTER_SANITIZE_EMAIL) : 'admin@example.com';

$db = db_connect();

$settings = [
    'monthly_price' => $monthly_price,
    'yearly_price' => $yearly_price,
    'lifetime_price' => $lifetime_price,
    'url_session_ttl' => $url_session_ttl,
    'default_premium_badge' => $default_premium_badge,
    'currency' => $currency,
    'payment_email' => $payment_email
];

try {
    $db->beginTransaction();
    
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare(
            "INSERT INTO premium_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$key, $value, $value]);
    }
    
    $db->commit();

    // Audit
    log_admin_action('update_premium_settings', 'updated premium settings', get_current_user_id());
    
    $_SESSION['flash_success'] = 'Ayarlar başarıyla güncellendi!';
    header('Location: ' . BASE_PATH . '/admin/premium_settings.php');
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash_error'] = 'Ayarlar güncellenirken bir hata oluştu';
    header('Location: ' . BASE_PATH . '/admin/premium_settings.php');
    exit;
}