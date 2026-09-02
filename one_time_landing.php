<?php
// One-time landing: accept one-time DB nonce via POST, consume it, create persistent DB-backed url_session, redirect to index
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Fail-fast to avoid long hangs on DB issues
@set_time_limit(10);

// Minimal immediate response header to avoid some clients waiting indefinitely
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Accept POST or GET for the one-time token so the flow works without JavaScript.
$one = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $one = $_POST['one_time'] ?? '';
} else {
    $one = $_GET['one'] ?? '';
}
// Normalize token to hex chars only
$one = preg_replace('/[^A-Fa-f0-9]/', '', $one);
if (empty($one)) {
    $_SESSION['login_error'] = 'Geçersiz token.';
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

try {
    // Diagnostic: record that a one-time token was received (suppress token/header/cookie details)
    $req_uri = $_SERVER['REQUEST_URI'] ?? ('one=redacted');
    $qstr = $_SERVER['QUERY_STRING'] ?? '';
    error_log('one_time_landing: one-time token received (details suppressed) method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' from=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' uri=' . $req_uri . ' qs=' . $qstr);
    // Do NOT log headers, cookie names/values, or token fragments in server logs
    $has_session_cookie = isset($_COOKIE[session_name()]) ? 1 : 0;
    error_log('[DIAG_LANDING] session_active=' . (session_status() === PHP_SESSION_ACTIVE ? 1 : 0) . ' cookie_present=' . $has_session_cookie);

    $user_id = validate_one_time_url_token($one);
    if (!$user_id) {
        // If validation failed, attempt to inspect DB for a matching row (masked output)
        try {
            $pdo = db_connect();
            $th = _url_token_hash($one);
            $sh = hash('sha256', $one);
            $stmt = $pdo->prepare("SELECT user_id, expires_at, created_at FROM url_one_time_tokens WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1");
            $stmt->execute([$th, $sh]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                error_log('one_time_landing: token row exists user_id=' . $row['user_id'] . ' expires_at=' . $row['expires_at']);
            } else {
                error_log('one_time_landing: token row NOT found in DB (may be consumed or invalid)');
            }
        } catch (Exception $ee) {
            error_log('one_time_landing: DB inspect error: ' . $ee->getMessage());
        }
        // Redirect back to landing with error so user can retry the login flow
        $_SESSION['login_error'] = 'Tek kullanımlık token doğrulanamadı veya süresi dolmuş olabilir. Lütfen ana sayfaya dönüp tekrar deneyin.';
        header('Location: ' . BASE_PATH . '/landing.php');
        exit;
    }
    if ($user_id) {
        // Create a persistent DB-backed url session for the user
        $session_token = create_url_session($user_id);
        if ($session_token) {
            // Redirect to index with the persistent session token
            header('Location: ' . BASE_PATH . '/index.php?sid=' . rawurlencode($session_token), true, 303);
            exit;
        } else {
            error_log('one_time_landing: failed to create persistent url_session for user ' . $user_id);
        }
    } else {
        error_log('one_time_landing: one-time token validation failed');
    }
} catch (Exception $e) {
    error_log('one_time_landing error: ' . $e->getMessage());
}

$_SESSION['login_error'] = 'Oturum başlatılamadı.';
header('Location: ' . BASE_PATH . '/landing.php');
exit;
?>