<?php /* EN + TR comments used. */
// Google Play RTDN (Real-Time Developer Notifications) endpoint stub
// See: https://developer.android.com/google/play/billing/realtime_developer_notifications
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Accept POST
$payload = @file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo 'no payload';
    exit;
}

// Log payload for manual inspection
error_log('google_play webhook: ' . $payload);

// TODO: verify JWT / signed message from Google Play and act accordingly
// For now, respond 200 so Google doesn't retry excessively
http_response_code(200);
echo json_encode(['received' => true]);
