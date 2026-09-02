<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Allow either ADMIN_API_TOKEN via Authorization: Bearer <token> header (preferred)
// or admin session-based POST+CSRF. Do NOT accept tokens via query string.
$expected = getenv('ADMIN_API_TOKEN') ?: '';
$session_access = false;

// Reject token-in-query to avoid leakage via referer/logs
if (isset($_GET['admin_token'])) {
    http_response_code(400);
    echo 'Token in query string is not allowed. Use Authorization header.';
    exit;
}

// Check Authorization header for Bearer token
$headers = getallheaders();
$bearer = null;
if (!empty($headers['Authorization'])) {
    if (stripos($headers['Authorization'], 'Bearer ') === 0) $bearer = substr($headers['Authorization'], 7);
}
if (!$bearer && !empty($headers['authorization'])) {
    if (stripos($headers['authorization'], 'Bearer ') === 0) $bearer = substr($headers['authorization'], 7);
}

if ($expected && $bearer && hash_equals($expected, $bearer)) {
    // token-based access OK (header only)
    // Audit token-based access
    if (function_exists('log_admin_action')) {
        log_admin_action('export_premium_subscriptions', 'exported via token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), null);
    }
} else {
    // session-based access: require admin session + POST + CSRF
    if (!is_admin()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $session_access = true;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="premium_subscriptions.csv"');
$fp = fopen('php://output', 'w');
fputcsv($fp, ['id','user_id','username','email','plan_type','status','vendor_platform','vendor_tx','start_date','end_date','validated_at']);

$db = db_connect();
$stmt = $db->query("SELECT ps.*, u.username, u.email FROM premium_subscriptions ps JOIN users u ON ps.user_id = u.id ORDER BY ps.created_at DESC");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($fp, [
        $r['id'], $r['user_id'], $r['username'], $r['email'], $r['plan_type'], $r['status'], $r['platform'] ?? '', $r['vendor_transaction_id'] ?? $r['vendor_purchase_token'] ?? '', $r['start_date'], $r['end_date'], $r['validated_at']
    ]);
}

if ($session_access) {
    log_admin_action('export_premium_subscriptions', 'exported via session', get_current_user_id());
}

fclose($fp);
exit;