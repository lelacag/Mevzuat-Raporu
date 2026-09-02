<?php /* EN + TR comments used. */
// IAP module stub. To re-enable: set IAP_ENABLED=true and ensure modules/iap/ files are in place.
if (!defined('IAP_ENABLED')) define('IAP_ENABLED', false);
if (IAP_ENABLED) {
    require_once __DIR__ . '/../modules/iap/admin_reverify_all.php';
    exit;
}
// Module inactive — redirect back silently
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_admin_perm('manage_billing');
$_SESSION['flash'] = 'IAP module is inactive. Enable it in modules/iap/config.php first.';
header('Location: ' . BASE_PATH . '/admin/premium_users.php#reconcile'); exit;
// — original file content below is unreachable when IAP is disabled —
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_admin_perm('manage_billing');

// CSRF check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf'] ?? '')) {
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#reconcile');
    exit;
}

$db = db_connect();
// Find subscriptions needing reverify
$stmt = $db->prepare("SELECT id FROM premium_subscriptions WHERE (status != 'active' OR vendor_status IN ('unknown','expired','cancelled')) LIMIT 200");
$stmt->execute();
$ids = array_map(function($r){ return (int)$r['id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Dispatch background job by writing to a small table or running script in background (best-effort)
if (!empty($ids)) {
    // Try to run the script in background (non-blocking) if php-cli available
    $script = __DIR__ . "/../scripts/reverify_pending_iap.php";
    if (is_executable($script)) {
        // fire and forget using a safe escapeshellarg
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
            @exec($cmd);
            $_SESSION['flash'] = 'Reverify job dispatched.';
        } else {
            $_SESSION['flash'] = 'Reverify job queued; run scripts/reverify_pending_iap.php from server.';
        }
    } else {
        $_SESSION['flash'] = 'Reverify job could not be auto-dispatched; run scripts/reverify_pending_iap.php manually.';
    }
} else {
    $_SESSION['flash'] = 'No subscriptions require reverify.';
}

header('Location: ' . BASE_PATH . '/admin/premium_users.php#reconcile');
exit;