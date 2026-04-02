<?php
// API endpoint: validate in-app purchases (Android/iOS)
// POST JSON: { platform: 'android'|'ios', plan: 'monthly'|'yearly'|'lifetime', purchase_token: '', receipt_base64: '', metadata: {} }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only accept POST JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json', true, 405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$platform = strtolower(trim($data['platform'] ?? ''));
$plan = strtolower(trim($data['plan'] ?? ''));
$valid_plans = ['monthly','yearly','lifetime'];
if (!in_array($plan, $valid_plans)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_plan']);
    exit;
}

// Auth: prefer bearer token (for native apps); otherwise require logged-in user
$auth_user_id = get_current_user_id();
$bearer = null;
$headers = getallheaders();
if (!empty($headers['Authorization'])) {
    if (stripos($headers['Authorization'], 'Bearer ') === 0) $bearer = substr($headers['Authorization'], 7);
}
if (!$bearer && !empty($headers['authorization'])) { // some servers use lower-case
    if (stripos($headers['authorization'], 'Bearer ') === 0) $bearer = substr($headers['authorization'], 7);
}

$expected_token = getenv('IAP_API_TOKEN') ?: '';
if ($bearer) {
    if ($expected_token === '') {
        http_response_code(403);
        echo json_encode(['error' => 'server_not_configured', 'message' => 'IAP_API_TOKEN not configured']);
        exit;
    }
    if (!hash_equals($expected_token, $bearer)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }
    // In bearer mode the caller must provide user_id
    $user_id = intval($data['user_id'] ?? 0);
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_user_id']);
        exit;
    }
} else {
    // No bearer token, require an authenticated session
    if (!$auth_user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'auth_required']);
        exit;
    }
    $user_id = $auth_user_id;
}

// Basic payloads
$purchase_token = $data['purchase_token'] ?? '';
$receipt_base64 = $data['receipt_base64'] ?? '';
$metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

if ($platform !== 'android' && $platform !== 'ios') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_platform']);
    exit;
}

// Quick test mode: if IAP_TEST_MODE = 1, accept a special token for testing
$test_mode = getenv('IAP_TEST_MODE') === '1';
$test_success_token = getenv('IAP_TEST_SUCCESS_TOKEN') ?: 'TEST_SUCCESS';

$verified = false;
$vendor_payload = null;
$vendor_tx_id = null;
$vendor_status = 'unknown';

if ($test_mode && ($purchase_token === $test_success_token || ($receipt_base64 && $receipt_base64 === base64_encode('TEST_SUCCESS')))) {
    // Short-circuit & accept the purchase for testing
    $verified = true;
    $vendor_payload = ['test' => true, 'note' => 'test-mode auto-validated'];
    $vendor_tx_id = 'TEST-' . bin2hex(random_bytes(6));
    $vendor_status = 'purchased';
} else {
    // Attempt real vendor verification when not in test mode
    if ($platform === 'android') {
        require_once __DIR__ . '/../includes/google_play.php';
        $package = $metadata['package_name'] ?? '';
        $product = $metadata['product_id'] ?? '';
        if (!$package || !$product || !$purchase_token) {
            http_response_code(400);
            echo json_encode(['error' => 'missing_android_details', 'message' => 'package_name, product_id, and purchase_token are required for Android verification']);
            exit;
        }
        $res = google_play_verify_subscription($package, $product, $purchase_token);
        if (!$res['success']) {
            http_response_code(502);
            echo json_encode(['error' => 'google_play_verification_failed', 'details' => $res]);
            exit;
        }

        // Interrogate returned payload to determine status
        $payload = $res['payload'];
        // Common fields: orderId, expiryTimeMillis, autoRenewing, paymentState
        $vendor_payload = $payload;
        $vendor_tx_id = $payload['orderId'] ?? null;
        $vendor_status = 'active';
        if (isset($payload['cancelReason']) && intval($payload['cancelReason']) > 0) $vendor_status = 'cancelled';
        // Check expiry
        if (!empty($payload['expiryTimeMillis']) && intval($payload['expiryTimeMillis']) < (int)(microtime(true) * 1000)) {
            $vendor_status = 'expired';
        }
        $verified = true;
    } elseif ($platform === 'ios') {
        // App Store receipt verification
        require_once __DIR__ . '/../includes/apple_receipt.php';
        if (!$receipt_base64) {
            http_response_code(400);
            echo json_encode(['error' => 'missing_receipt']);
            exit;
        }

        $res = apple_verify_receipt($receipt_base64);
        if (!$res['success']) {
            http_response_code(502);
            echo json_encode(['error' => 'apple_verification_failed', 'details' => $res]);
            exit;
        }

        $payload = $res['payload'];
        $vendor_payload = $payload;
        // Attempt to derive transaction id and status from common fields
        // Look into latest_receipt_info or receipt in the response
        $tx = null;
        $status = 'unknown';
        $now_ms = (int)(microtime(true) * 1000);

        // latest_receipt_info may be an array of transactions
        $latest = $payload['latest_receipt_info'] ?? null;
        if ($latest && is_array($latest)) {
            // pick the latest by purchase_date_ms
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
            // Fallback: check receipt->in_app
            $rec = $payload['receipt'] ?? null;
            if ($rec && !empty($rec['in_app']) && is_array($rec['in_app'])) {
                $txinfo = $rec['in_app'][0];
                $tx = $txinfo['transaction_id'] ?? null;
                $status = 'purchased';
            }
        }

        $vendor_tx_id = $tx;
        $vendor_status = $status;
        $verified = true;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_platform']);
        exit;
    }
}

// If verified, create or update a premium_subscriptions record and promote user
$db = db_connect();
try {
    $db->beginTransaction();


    // compute start/end
    $start = date('Y-m-d H:i:s');
    $end = null;
    if ($plan === 'monthly') $end = date('Y-m-d H:i:s', strtotime('+1 month'));
    if ($plan === 'yearly') $end = date('Y-m-d H:i:s', strtotime('+1 year'));
    if ($plan === 'lifetime') $end = null;

    // Insert or update
    $stmt = $db->prepare("SELECT id FROM premium_subscriptions WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $payment_proof = json_encode(['platform' => $platform, 'payload' => $vendor_payload]);
    // store purchase token or receipt base64 in vendor_purchase_token for future re-verification
    $vendor_token_for_db = $purchase_token ?: ($receipt_base64 ?: null);

    if ($existing) {
        $db->prepare("UPDATE premium_subscriptions SET plan_type = ?, status = ?, start_date = ?, end_date = ?, payment_method = ?, payment_proof = ?, vendor_purchase_token = ?, vendor_transaction_id = ?, vendor_status = ?, vendor_payload = ?, validated_at = NOW() WHERE id = ?")
            ->execute([$plan, 'active', $start, $end, 'iap', $payment_proof, $vendor_token_for_db, $vendor_tx_id, $vendor_status, json_encode($vendor_payload), $existing['id']]);
        $sub_id = $existing['id'];
    } else {
        $db->prepare("INSERT INTO premium_subscriptions (user_id, plan_type, status, start_date, end_date, payment_method, payment_proof, vendor_purchase_token, vendor_transaction_id, vendor_status, vendor_payload, created_at, validated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$user_id, $plan, 'active', $start, $end, 'iap', $payment_proof, $vendor_token_for_db, $vendor_tx_id, $vendor_status, json_encode($vendor_payload)]);
        $sub_id = $db->lastInsertId();
    }

    // Promote user: set role member and is_approved = 1
    $db->prepare("UPDATE users SET role = 'member', is_approved = 1, is_premium = 1, premium_until = ? WHERE id = ?")->execute([$end, $user_id]);

    // Audit log (simple)
    $db->prepare("INSERT INTO audit_logs (admin_id, action, details, created_at) VALUES (?, ?, ?, NOW())")->execute([NULL, 'iap_validation', json_encode(['user_id' => $user_id, 'sub_id' => $sub_id, 'platform' => $platform, 'tx' => $vendor_tx_id])]);

    $db->commit();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'subscription_id' => (int)$sub_id, 'status' => 'active']);
    exit;
} catch (Exception $e) {
    $db->rollBack();
    error_log('validate_iap error: ' . $e->getMessage());
    http_response_code(500);
    $out = ['error' => 'server_error'];
    // In development show the exception message to help debugging
    if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
        $out['debug'] = $e->getMessage();
        $out['trace'] = substr($e->getTraceAsString(),0,1000);
    }
    echo json_encode($out);
    exit;
}
