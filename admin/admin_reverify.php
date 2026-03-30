<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google_play.php';

if (!is_admin()) {
    $_SESSION['flash_error'] = 'Admin access required';
    header('Location: ' . BASE_PATH . '/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Invalid request';
    header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Invalid CSRF token';
    header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
    exit;
}

$id = intval($_POST['id'] ?? 0);
if (!$id) {
    $_SESSION['flash_error'] = 'Missing subscription id';
    header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
    exit;
}

$db = db_connect();
$stmt = $db->prepare("SELECT * FROM premium_subscriptions WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    $_SESSION['flash_error'] = 'Subscription not found';
    header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
    exit;
}

$platform = $sub['platform'] ?? '';
$purchase_token = $sub['vendor_purchase_token'] ?? '';
$metadata = json_decode($sub['vendor_payload'] ?? 'null', true) ?? [];

try {
    if ($platform === 'android') {
        $package = $metadata['package_name'] ?? '';
        $product = $metadata['product_id'] ?? '';
        if (!$package || !$product || !$purchase_token) {
            $_SESSION['flash_error'] = 'Missing package/product/purchase token';
            header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
            exit;
        }
        $res = google_play_verify_subscription($package, $product, $purchase_token);
        if (!$res['success']) {
            $_SESSION['flash_error'] = 'Google Play verification failed: ' . json_encode($res);
            header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
            exit;
        }

        $payload = $res['payload'];
        $vendor_tx_id = $payload['orderId'] ?? null;
        $vendor_status = 'active';
        if (isset($payload['cancelReason']) && intval($payload['cancelReason']) > 0) $vendor_status = 'cancelled';
        if (!empty($payload['expiryTimeMillis']) && intval($payload['expiryTimeMillis']) < (int)(microtime(true) * 1000)) $vendor_status = 'expired';

        $db->prepare("UPDATE premium_subscriptions SET vendor_transaction_id = ?, vendor_status = ?, vendor_payload = ?, validated_at = NOW() WHERE id = ?")
            ->execute([$vendor_tx_id, $vendor_status, json_encode($payload), $id]);

        $_SESSION['flash'] = 'Reverification completed: ' . htmlspecialchars($vendor_status);
    } elseif ($platform === 'ios') {
        $_SESSION['flash_error'] = 'iOS verification not implemented yet';
    } else {
        $_SESSION['flash_error'] = 'Unknown platform';
    }
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
}

header('Location: ' . BASE_PATH . '/admin/premium_reconcile.php');
exit;
