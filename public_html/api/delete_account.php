<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$referer = $_POST['referer'] ?? BASE_PATH . '/delete_account_confirm.php';

$user_id = get_current_user_id();
if (!$user_id) {
    $_SESSION['flash_error'] = 'Lütfen giriş yapın.';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Geçersiz istek.';
    header('Location: ' . $referer);
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Geçersiz CSRF token.';
    header('Location: ' . $referer);
    exit;
}

// Get user details
$user = get_user($user_id);
if (!$user) {
    $_SESSION['flash_error'] = 'Kullanıcı bulunamadı.';
    header('Location: ' . $referer);
    exit;
}

// If user has email, check if confirmation token is provided
if (!empty($user['email'])) {
    $token = $_POST['token'] ?? '';
    
    if (empty($token)) {
        // Generate and send confirmation token
        $confirmation_token = bin2hex(random_bytes(32));
        $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in session
        $_SESSION['account_deletion_token'] = $confirmation_token;
        $_SESSION['account_deletion_expiry'] = $token_expiry;
        
        // Send email if mail is enabled
        if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
            $subject = 'Hesap Silme Onayı - ' . SITE_NAME;
            $message = "Merhaba " . htmlspecialchars($user['username']) . ",\n\n";
            $message .= "Hesabınızı silmek istediğinizi bildirdiniz. Bu işlemi onaylamak için aşağıdaki kodu kullanın:\n\n";
            $message .= "Onay Kodu: " . $confirmation_token . "\n\n";
            $message .= "Bu kod 1 saat geçerlidir.\n\n";
            $message .= "Bu işlemi siz yapmadıysanız, bu mesajı görmezden gelebilirsiniz.\n\n";
            $message .= SITE_NAME;
            
            send_email($user['email'], $subject, $message);
        }
        
        $_SESSION['flash'] = 'E-posta adresinize onay kodu gönderildi.';
        header('Location: ' . BASE_PATH . '/delete_account_confirm.php');
        exit;
    } else {
        // Verify token
        $stored_token = $_SESSION['account_deletion_token'] ?? '';
        $token_expiry = $_SESSION['account_deletion_expiry'] ?? '';
        
        if ($token !== $stored_token) {
            $_SESSION['flash_error'] = 'Geçersiz onay kodu.';
            header('Location: ' . BASE_PATH . '/delete_account_confirm.php');
            exit;
        }
        
        if (strtotime($token_expiry) < time()) {
            unset($_SESSION['account_deletion_token']);
            unset($_SESSION['account_deletion_expiry']);
            $_SESSION['flash_error'] = 'Onay kodu süresi doldu. Lütfen tekrar deneyin.';
            header('Location: ' . BASE_PATH . '/delete_account_confirm.php');
            exit;
        }
        
        // Clear token from session
        unset($_SESSION['account_deletion_token']);
        unset($_SESSION['account_deletion_expiry']);
    }
}

// Soft delete: Set deleted_at timestamp
$now = date('Y-m-d H:i:s');
query("UPDATE users SET deleted_at = ? WHERE id = ?", [$now, $user_id]);

// Logout user
session_destroy();

$_SESSION['flash'] = 'Hesabınız başarıyla silindi.';
header('Location: ' . BASE_PATH . '/landing.php');
exit;
