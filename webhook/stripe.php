<?php
/* EN + TR comments used. */
// Stripe Webhook endpoint
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stripe.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Ensure configured
if (!is_stripe_configured()) {
    http_response_code(404);
    echo 'Stripe not configured';
    exit;
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhook_secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';

try {
    if (!class_exists('Stripe\\Webhook')) throw new Exception('Stripe PHP library missing');
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
} catch (Exception $e) {
    error_log('Stripe webhook verification failed: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

// Dispatch to handler
try {
    stripe_handle_event($event);
    http_response_code(200);
    echo 'received';
} catch (Exception $e) {
    error_log('Stripe webhook handler error: ' . $e->getMessage());
    http_response_code(500);
}
