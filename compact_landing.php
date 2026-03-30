<?php
// Compact landing page: accept compact stateless token via POST and render index.php in the same request
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

$sid = $_POST['sid'] ?? '';
$sid = preg_replace('/[^A-Za-z0-9\-_.]/', '', $sid);

try {
    $user_id = validate_compact_stateless_token($sid);
    if ($user_id) {
        // Redirect to the main index with the sid in the URL so the browser's address bar
        // reflects the feed page (avoids forms posting back to this landing URL).
        $sid_esc = rawurlencode($sid);
        header('Location: ' . BASE_PATH . '/index.php?sid=' . $sid_esc, true, 303);
        exit;
    }
} catch (Exception $e) {
    error_log('compact_landing validation error: ' . $e->getMessage());
}

// Invalid token: redirect back to landing with error
$_SESSION['login_error'] = 'Geçersiz oturum tokeni.';
header('Location: ' . BASE_PATH . '/landing.php');
exit;
?>