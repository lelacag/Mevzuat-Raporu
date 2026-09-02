<?php
// Consume one-time token via GET (no-JS friendly magic-link style)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Accept token via query param `one`
$one = $_GET['one'] ?? '';
$one = preg_replace('/[^A-Fa-f0-9]/', '', $one);

if (empty($one)) {
    session_start();
    $_SESSION['login_error'] = 'Geçersiz token.';
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

try {
    // Try to validate and consume the one-time token
    // Diagnostic: log incoming request context for consumption attempts
    // Do not log token fragments or cookie names/values. Record attempt with minimal context.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    error_log('[DIAG_CONSUME] one_time_consume: attempt (token/cookies suppressed) method=' . $method . ' referer=' . ($referer?:'<none>') . ' cookie_present=' . (empty($_COOKIE) ? 0 : 1));

    $user_id = validate_one_time_url_token($one);
    if ($user_id) {
        $session_token = create_url_session($user_id);
        if ($session_token) {
            header('Location: ' . BASE_PATH . '/index.php?sid=' . rawurlencode($session_token), true, 303);
            exit;
        } else {
            error_log('one_time_consume: failed to create url_session for user ' . $user_id);
        }
    } else {
        error_log('one_time_consume: token validation failed for masked=' . substr($one,0,6) . '...' . substr($one,-6));
    }
} catch (Exception $e) {
    error_log('one_time_consume error: ' . $e->getMessage());
}

session_start();
$_SESSION['login_error'] = 'Oturum başlatılamadı. Lütfen tekrar deneyin.';
header('Location: ' . BASE_PATH . '/landing.php');
exit;
