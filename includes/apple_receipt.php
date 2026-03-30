<?php /* EN + TR comments used. */
/**
 * Minimal App Store receipt verification helper.
 * Supports:
 *  - Test-mode short-circuit when IAP_TEST_MODE=1 and receipt matches TEST_SUCCESS
 *  - Shared secret verification via APPLE_SHARED_SECRET
 *  - Transparent production->sandbox fallback when Apple returns status 21007
 */

function apple_verify_receipt($receipt_base64) {
    $test_mode = getenv('IAP_TEST_MODE') === '1';
    $test_token = getenv('IAP_TEST_SUCCESS_TOKEN') ?: 'TEST_SUCCESS';

    if ($test_mode && $receipt_base64 === base64_encode($test_token)) {
        return ['success' => true, 'payload' => ['test' => true, 'note' => 'test-mode auto-validated']];
    }

    $shared = getenv('APPLE_SHARED_SECRET') ?: '';
    if ($shared === '') {
        return ['success' => false, 'error' => 'apple_shared_secret_missing', 'message' => 'Set APPLE_SHARED_SECRET to enable receipt verification'];
    }

    $payload = json_encode(['receipt-data' => $receipt_base64, 'password' => $shared, 'exclude-old-transactions' => false]);

    $endpoints = [
        'https://buy.itunes.apple.com/verifyReceipt', // production
        'https://sandbox.itunes.apple.com/verifyReceipt', // sandbox fallback
    ];

    foreach ($endpoints as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            error_log('apple_verify_receipt curl error: ' . $err);
            return ['success' => false, 'error' => 'network_error', 'details' => $err];
        }

        $obj = json_decode($resp, true);
        if (!$obj) return ['success' => false, 'error' => 'invalid_json', 'body' => $resp];

        // Apple returns a 'status' field; 0 == OK, 21007 == sandbox receipt sent to production
        $status = intval($obj['status'] ?? -1);
        if ($status === 0) {
            return ['success' => true, 'payload' => $obj, 'endpoint' => $url];
        }

        if ($status === 21007 && $i === 0) {
            // Receipt is a sandbox receipt but sent to production; continue to try sandbox endpoint
            continue;
        }

        // Non-OK status
        return ['success' => false, 'error' => 'apple_status', 'status' => $status, 'body' => $obj, 'endpoint' => $url];
    }

    return ['success' => false, 'error' => 'unknown_failure'];
}
