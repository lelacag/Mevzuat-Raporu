<?php
/**
 * Login API Endpoint
 * Handles user authentication from the landing page
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
// Helpers (sanitize_input, etc.)
require_once __DIR__ . '/../includes/functions.php';


// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

// Diagnostic logging (kept concise)
error_log('api/login.php POST from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' UA=' . substr(($_SERVER['HTTP_USER_AGENT'] ?? ''),0,120));

// Get input
$email_or_username = sanitize_input($_POST['email_or_username'] ?? '');
$password = $_POST['password'] ?? '';

// Validate input
if (empty($email_or_username) || empty($password)) {
    $_SESSION['login_error'] = 'E-posta/kullanıcı adı ve şifre gereklidir.';
    error_log('login: missing credentials in POST');
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

// First, try to find the user to get the username (if email was provided)
$username = $email_or_username;
$stmt = query("SELECT username FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1", [$email_or_username, $email_or_username]);
$user_record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user_record) {
    $username = $user_record['username'];
}

// Attempt login
$login_result = login($username, $password);

if ($login_result === true) {
    // Login successful
    error_log('Login success for user: ' . $username . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' (headers suppressed)');

    // Determine user_id for URL-session creation
    $user_id = $_SESSION['user_id'] ?? null;
    if (empty($user_id)) {
        // Fallback: resolve from username
        try {
            $stmtUid = query("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
            $rowUid = $stmtUid->fetch(PDO::FETCH_ASSOC);
            $user_id = $rowUid['id'] ?? null;
            error_log('api/login: resolved user_id via DB=' . var_export($user_id, true));
        } catch (Exception $e) {
            error_log('api/login: could not resolve user_id: ' . $e->getMessage());
        }
    }

    // If the user explicitly requested to reject cookies, create a URL session token
    // (this lets users choose URL-based sessions even when cookies would be available).
    $session_cookie_name = session_name();
    if (!empty($_POST['reject_cookies'])) {
        error_log('User requested reject_cookies; creating URL session');
        $force_url_session = true;
    } else {
        $force_url_session = false;
    }

    // If the client blocked cookies (no session cookie present) OR user requested rejection,
    // create a persistent DB-backed URL session immediately and redirect with `?sid=`.
    if ($force_url_session || empty($_COOKIE) || !isset($_COOKIE[$session_cookie_name])) {
        // Resolve user_id reliably from session or DB
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) {
            try {
                $tmp = query("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
                $r = $tmp->fetch(PDO::FETCH_ASSOC);
                $user_id = $r['id'] ?? null;
            } catch (Exception $e) {
                error_log('api/login: failed to resolve user_id after login: ' . $e->getMessage());
            }
        }
        if (!$user_id) {
            error_log('api/login: user_id could not be determined; aborting URL session creation');
        } else {
            // Simple approach: directly create a persistent URL session and redirect to index with sid
            $sid = create_url_session($user_id);
            if ($sid) {
                // Verify the row was inserted
                try {
                    $pdo = db_connect();
                    $stmt = $pdo->prepare("SELECT user_id FROM url_sessions WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1");
                    $stmt->execute([hash_hmac('sha256', $sid, URL_SESSION_SECRET), hash('sha256', $sid)]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        error_log('api/login: verified url_session row inserted for user_id=' . $row['user_id']);
                    } else {
                        error_log('api/login: url_session row NOT inserted');
                    }
                } catch (Exception $e) {
                    error_log('api/login: verification error: ' . $e->getMessage());
                }
                error_log('api/login: created persistent url_session for user_id=' . $user_id);
                // Prepare absolute URL target
                $base_host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
                $url = 'http://' . $base_host . BASE_PATH . '/anasayfa?sid=' . rawurlencode($sid);
                // Prefer an explicit 303 See Other redirect after POST for better compatibility on mobile
                if (!headers_sent()) {
                    header('Location: ' . $url, true, 303);
                    exit;
                }
                // If headers already sent (or client doesn't follow redirects), show a no-JS POST form fallback
                error_log('api/login: headers already sent; falling back to HTML form for url_session');
                $action = 'http://' . $base_host . BASE_PATH . '/anasayfa';
                echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Continue to site</title></head><body style=\"font-family:Arial,sans-serif;line-height:1.6;\">";
                echo "<p>Login successful. Please tap <strong>Continue</strong> to proceed.</p>";
                echo "<form method=\"POST\" action=\"" . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . "\">";
                echo "<input type=\"hidden\" name=\"sid\" value=\"" . htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') . "\">";
                echo "<button type=\"submit\">Continue</button>";
                echo "</form>";
                echo "<p>If that does not work, click <a href=\"" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "\">this link</a>.</p>";
                echo "</body></html>";
                exit;
            } else {
                error_log('api/login: failed to create persistent url_session for user_id=' . $user_id);
            }
        }
    }

    header('Location: ' . BASE_PATH . '/anasayfa');
    exit;
} elseif (is_array($login_result) && isset($login_result['error'])) {
    // Specific error returned
    $error = $login_result['error'];
    if ($error === 'account_deleted') {
        $_SESSION['login_error'] = 'Bu hesap silinmiştir.';
        error_log('Login failed (deleted) for ' . $username . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    } elseif ($error === 'email_not_verified') {
        $_SESSION['login_error'] = 'Lütfen e-postanızı doğrulayın.';
        error_log('Login failed (email not verified) for ' . $username . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    } else {
        $_SESSION['login_error'] = 'Giriş başarısız oldu.';
        error_log('Login failed (other error) for ' . $username . ' result: ' . json_encode($login_result));
    }
} else {
    // Login failed
    $_SESSION['login_error'] = 'E-posta/kullanıcı adı veya şifre yanlış.';
    error_log('Login failed (invalid creds) for ' . $username . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

// Redirect back to landing page with error
error_log('login: login failure (headers suppressed)');
header('Location: ' . BASE_PATH . '/landing.php');
exit;
