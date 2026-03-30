<?php
// Token-based admin reverify API for CI/testing.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/google_play.php';

// Accept only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$headers = getallheaders();
$bearer = null;
if (!empty($headers['Authorization'])) {
    if (stripos($headers['Authorization'], 'Bearer ') === 0) $bearer = substr($headers['Authorization'], 7);
}
if (!$bearer && !empty($headers['authorization'])) {
    if (stripos($headers['authorization'], 'Bearer ') === 0) $bearer = substr($headers['authorization'], 7);
}
$expected = getenv('ADMIN_API_TOKEN') ?: '';
// Allow either ADMIN_API_TOKEN (CI) OR an admin session + CSRF token
if ($expected && $bearer && hash_equals($expected, $bearer)) {
    // token-based access OK
} elseif (is_logged_in() && admin_has_perm(null, 'manage_billing')) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid_csrf']);
        exit;
    }
} else {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;
$id = intval($data['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_id']);
    exit;
}

$db = db_connect();
$stmt = $db->prepare("SELECT * FROM premium_subscriptions WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$platform = $sub['platform'] ?? '';
$purchase_token = $sub['vendor_purchase_token'] ?? '';
$metadata = json_decode($sub['vendor_payload'] ?? 'null', true) ?: [];

if ($platform === 'android') {
    $package = $metadata['package_name'] ?? '';
    $product = $metadata['product_id'] ?? '';
    if (!$package || !$product || !$purchase_token) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_android_details']);
        exit;
    }
    $res = google_play_verify_subscription($package, $product, $purchase_token);
    if (!$res['success']) {
        http_response_code(502);
        echo json_encode(['error' => 'google_verify_failed','details'=>$res]);
        exit;
    }
    $payload = $res['payload'];
    $vendor_tx_id = $payload['orderId'] ?? null;
    $vendor_status = 'active';
    if (isset($payload['cancelReason']) && intval($payload['cancelReason']) > 0) $vendor_status = 'cancelled';
    if (!empty($payload['expiryTimeMillis']) && intval($payload['expiryTimeMillis']) < (int)(microtime(true) * 1000)) $vendor_status = 'expired';

    $db->prepare("UPDATE premium_subscriptions SET vendor_transaction_id = ?, vendor_status = ?, vendor_payload = ?, validated_at = NOW() WHERE id = ?")
        ->execute([$vendor_tx_id, $vendor_status, json_encode($payload), $id]);

    echo json_encode(['success'=>true,'status'=>$vendor_status,'id'=>$id]);
    exit;
}

if ($platform === 'ios') {
    require_once __DIR__ . '/../includes/apple_receipt.php';
    $receipt_b64 = $purchase_token ?: ($metadata['receipt_base64'] ?? '');
    if (!$receipt_b64) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_receipt_for_reverify']);
        exit;
    }
    $res = apple_verify_receipt($receipt_b64);
    if (!$res['success']) {
        http_response_code(502);
        echo json_encode(['error' => 'apple_verify_failed', 'details' => $res]);
        exit;
    }
    $payload = $res['payload'];
    // derive status similar to validate_iap
    $tx = null;
    $status = 'unknown';
    $now_ms = (int)(microtime(true) * 1000);
    $latest = $payload['latest_receipt_info'] ?? null;
    if ($latest && is_array($latest)) {
        usort($latest, function($a, $b){ return intval($b['purchase_date_ms'] ?? 0) - intval($a['purchase_date_ms'] ?? 0); });
        $txinfo = $latest[0];
        $tx = $txinfo['transaction_id'] ?? ($txinfo['original_transaction_id'] ?? null);
        if (!empty($txinfo['cancellation_date_ms'])) {
            $status = 'cancelled';
        } elseif (!empty($txinfo['expires_date_ms']) && intval($txinfo['expires_date_ms']) > $now_ms) {
            $status = 'active';
        } else {
            $status = 'expired';
        }
    } else {
        $rec = $payload['receipt'] ?? null;
        if ($rec && !empty($rec['in_app']) && is_array($rec['in_app'])) {
            $txinfo = $rec['in_app'][0];
            $tx = $txinfo['transaction_id'] ?? null;
            $status = 'purchased';
        }
    }

    $db->prepare("UPDATE premium_subscriptions SET vendor_transaction_id = ?, vendor_status = ?, vendor_payload = ?, validated_at = NOW() WHERE id = ?")
        ->execute([$tx, $status, json_encode($payload), $id]);

    echo json_encode(['success'=>true,'status'=>$status,'id'=>$id]);
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'unsupported_platform']);
