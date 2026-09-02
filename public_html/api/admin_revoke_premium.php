<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

function admin_revoke_redirect_target(): string {
    $default = BASE_PATH . '/admin/users.php';
    $ref = $_POST['referer'] ?? '';
    if (!is_string($ref) || $ref === '') {
        return $default;
    }
    // Allow only same-site relative admin/api paths
    if ($ref[0] !== '/') {
        return $default;
    }
    if (strpos($ref, '//') !== false || strpos($ref, '\\') !== false) {
        return $default;
    }
    if (strpos($ref, '/admin/') !== 0 && strpos($ref, BASE_PATH . '/admin/') !== 0) {
        // also allow BASE_PATH-prefixed paths already relative-looking
        if (strpos($ref, '/admin/') === false) {
            return $default;
        }
    }
    return $ref;
}

if (!is_logged_in() || !admin_has_perm(null, 'manage_billing')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . admin_revoke_redirect_target());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['user_id'])) {
    $_SESSION['flash_error'] = 'User ID is required';
    header('Location: ' . admin_revoke_redirect_target());
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . admin_revoke_redirect_target());
    exit;
}

$user_id = intval($_POST['user_id']);

$db = db_connect();

try {
    $db->beginTransaction();
    
    // Update user premium status
    $stmt = $db->prepare("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    
    // Deactivate all subscriptions
    $stmt = $db->prepare("UPDATE premium_subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    
    $db->commit();
    
    log_admin_action('revoke_premium', 'revoked premium for user_id=' . $user_id, get_current_user_id());
    $_SESSION['flash'] = 'Premium revoked successfully';
    header('Location: ' . admin_revoke_redirect_target());
    exit;

} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['flash_error'] = 'Database error: ' . $e->getMessage();
    header('Location: ' . admin_revoke_redirect_target());
    exit;
}
