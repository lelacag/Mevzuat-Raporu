<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_admin_logged_in()) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// CSRF check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf_token($_POST['csrf'] ?? '')) {
    header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php'); exit;
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

header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
exit;