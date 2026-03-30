<?php
// Simple status endpoint to report IAP readiness for mobile teams and CI
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$ok = true;
$messages = [];

// Check envs
$iap_test_mode = getenv('IAP_TEST_MODE') === '1';
$iap_api_token = getenv('IAP_API_TOKEN') ?: null;
$admin_api_token = getenv('ADMIN_API_TOKEN') ?: null;
$gp_json = getenv('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON') ?: null;
$apple_secret = getenv('APPLE_SHARED_SECRET') ?: null;

if (!$iap_api_token) {
    $ok = false; $messages[] = 'IAP_API_TOKEN missing';
}
if (!$admin_api_token) {
    $ok = false; $messages[] = 'ADMIN_API_TOKEN missing';
}
if (!$gp_json) {
    $messages[] = 'GOOGLE_PLAY_SERVICE_ACCOUNT_JSON not set (required for production Play verification)';
}
if (!$apple_secret) {
    $messages[] = 'APPLE_SHARED_SECRET not set (required for App Store verification)';
}

$result = [
    'ok' => $ok,
    'test_mode' => $iap_test_mode,
    'env' => [
        'IAP_API_TOKEN' => $iap_api_token ? 'configured' : 'missing',
        'ADMIN_API_TOKEN' => $admin_api_token ? 'configured' : 'missing',
        'GOOGLE_PLAY_SERVICE_ACCOUNT_JSON' => $gp_json ? 'configured' : 'missing',
        'APPLE_SHARED_SECRET' => $apple_secret ? 'configured' : 'missing',
    ],
    'messages' => $messages,
];

echo json_encode($result);
