<?php /* EN + TR comments used. */
// App Store Server Notifications endpoint stub
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$payload = @file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo 'no payload';
    exit;
}

// Log payload for manual inspection
error_log('apple webhook: ' . $payload);

// TODO: verify signed payload using App Store keys (App Store Server Notifications V2 uses JWS)
// Best-effort parsing of the common fields for auditing
$body = json_decode($payload, true);
if ($body) {
    // Example fields: notificationType / notification_type (legacy), unifiedReceipt, data
    $type = $body['notificationType'] ?? $body['notification_type'] ?? null;
    $relevant = $body['unifiedReceipt'] ?? ($body['data'] ?? $body);
    error_log('apple webhook parsed type=' . json_encode($type) . ' data=' . json_encode($relevant));
}

http_response_code(200);
echo json_encode(['received' => true]);
