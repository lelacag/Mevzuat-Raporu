<?php /* EN + TR comments used. */
require_once __DIR__ . '/config.php';
// Ensure DB and helper functions are available for URL-session handling
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    // Secure flag: enabled in production (requires HTTPS), disabled in dev
    $secure = (ENVIRONMENT === 'production');
    // Use SameSite=Lax to allow POST-redirect login flows to work reliably on mobile browsers
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $secure,
        'cookie_samesite' => 'Lax',
        'cookie_lifetime' => SESSION_LIFETIME,
        'use_strict_mode' => true
    ]);
}

// URL-based session support for clients that block cookies
// If a `sid` parameter is present, we will validate it and make the user available
$GLOBALS['url_session_user_id'] = null;

// Stateless signed URL-session token helpers (fallback when cookies blocked)
function _base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function _base64url_decode($data) {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) $data .= str_repeat('=', $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function create_stateless_url_token($user_id, $ttl = null) {
    $db_ttl = null;
    try {
        $db_ttl = (int)get_premium_setting('url_session_ttl', URL_SESSION_TTL);
    } catch (Exception $e) {
        $db_ttl = URL_SESSION_TTL;
    }
    $ttl = $ttl ?? min(SESSION_LIFETIME, max(60, $db_ttl));
    $expires = time() + $ttl;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(hash('sha256', $_SERVER['HTTP_USER_AGENT']),0,16) : '';
    $ip = _ip_prefix();
    $payload = json_encode(['u' => (int)$user_id, 'e' => $expires, 'ua' => $ua, 'ip' => $ip, 'r' => bin2hex(random_bytes(8))]);
    $payload_b64 = _base64url_encode($payload);
    $sig = hash_hmac('sha256', $payload_b64, URL_SESSION_SECRET);
    return $payload_b64 . '.' . $sig;
}

// Compact stateless token (HackerNews-like): shorter payload, short TTL, HMAC truncated and base64url-encoded
function create_compact_stateless_token($user_id, $ttl = null) {
    $default_ttl = 3600; // 1 hour by default (compact/HN-like)
    $db_ttl = null;
    try {
        $db_ttl = (int)get_premium_setting('url_session_ttl', $default_ttl);
    } catch (Exception $e) {
        $db_ttl = $default_ttl;
    }
    $ttl = $ttl ?? min($default_ttl, max(60, $db_ttl));
    $expires = time() + $ttl;
    $nonce = bin2hex(random_bytes(6)); // 12 hex chars
    $payload = json_encode(['u' => (int)$user_id, 'e' => $expires, 'n' => $nonce]);
    $payload_b64 = _base64url_encode($payload);
    // Use raw binary HMAC and truncate to 12 bytes for compactness
    $raw_sig = hash_hmac('sha256', $payload_b64, URL_SESSION_SECRET, true);
    $trunc = substr($raw_sig, 0, 12);
    $sig_b64 = _base64url_encode($trunc);
    return $payload_b64 . '.' . $sig_b64;
}

function validate_compact_stateless_token($token) {
    if (!is_string($token) || strpos($token, '.') === false) return false;
    if (strlen($token) > 2048) return false;
    list($payload_b64, $sig_b64) = explode('.', $token, 2);
    if (empty($payload_b64) || empty($sig_b64)) return false;
    // Recompute raw HMAC and compare truncated binary
    $expected_raw = hash_hmac('sha256', $payload_b64, URL_SESSION_SECRET, true);
    $expected_trunc = substr($expected_raw, 0, 12);
    $sig_raw = _base64url_decode($sig_b64);
    if ($sig_raw === false || strlen($sig_raw) !== strlen($expected_trunc)) return false;
    if (!hash_equals($expected_trunc, $sig_raw)) return false;
    $payload_raw = _base64url_decode($payload_b64);
    if ($payload_raw === false || strlen($payload_raw) > 4096) return false;
    $data = json_decode($payload_raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return false;
    if (!is_array($data) || empty($data['u']) || empty($data['e'])) return false;
    if ($data['e'] < time()) return false;
    return (int)$data['u'];
}

function validate_stateless_url_token($token) {
    if (!is_string($token) || strpos($token, '.') === false) return false;
    // Defensive size limits to avoid large memory allocations from malformed tokens
    if (strlen($token) > 4096) return false;
    list($payload_b64, $sig) = explode('.', $token, 2);
    if (empty($payload_b64) || empty($sig)) return false;
    $expected = hash_hmac('sha256', $payload_b64, URL_SESSION_SECRET);
    if (!hash_equals($expected, $sig)) return false;
    // Limit payload size before decoding
    if (strlen($payload_b64) > 2048) return false;
    $payload_raw = _base64url_decode($payload_b64);
    if ($payload_raw === false || strlen($payload_raw) > 8192) return false;
    $data = json_decode($payload_raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return false;
    if (!is_array($data) || empty($data['u']) || empty($data['e'])) return false;
    if ($data['e'] < time()) return false;
    // Optional UA/IP binding checks
    if (!empty($data['ua'])) {
        $cur_ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(hash('sha256', $_SERVER['HTTP_USER_AGENT']),0,16) : '';
        if ($cur_ua !== $data['ua']) return false;
    }
    if (!empty($data['ip'])) {
        if ($data['ip'] !== _ip_prefix()) return false;
    }
    return (int)$data['u'];
}

function ensure_url_sessions_table() {
    // Check if table exists with a lightweight query; if not, create it.
    try {
        query("SELECT 1 FROM url_sessions LIMIT 1");
    } catch (Exception $e) {
        try {
            query("CREATE TABLE IF NOT EXISTS url_sessions (
            token_hash VARCHAR(64) PRIMARY KEY,
            raw_token_hash VARCHAR(64) DEFAULT '',
            user_id INT NOT NULL,
            ua_hash VARCHAR(64) DEFAULT '',
            ip_prefix VARCHAR(64) DEFAULT '',
            grants_premium TINYINT DEFAULT 0,
            revoked_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e2) {
            // ignore creation errors
        }
    }
    // Ensure one-time tokens table exists for single-use nonce tokens
    try {
        query("CREATE TABLE IF NOT EXISTS url_one_time_tokens (
            token_hash VARCHAR(64) PRIMARY KEY,
            raw_token_hash VARCHAR(64) DEFAULT '',
            user_id INT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // ignore
    }
    // Try adding raw_token_hash column if it doesn't exist yet (older installs)
    try {
        query("ALTER TABLE url_sessions ADD COLUMN raw_token_hash VARCHAR(64) DEFAULT ''");
    } catch (Exception $e) {
        // ignore errors (column may already exist)
    }
    // Try adding grants_premium column for token-level premium grants
    try {
        query("ALTER TABLE url_sessions ADD COLUMN grants_premium TINYINT DEFAULT 0");
    } catch (Exception $e) {
        // ignore (may already exist)
    }
    // Try adding revoked_at column for token revocation support
    try {
        query("ALTER TABLE url_sessions ADD COLUMN revoked_at DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // ignore (may already exist)
    }
}

/* UNUSED_START one_time_token
// One-time DB-backed nonce (single-use) helpers
function create_one_time_url_token($user_id, $ttl = 600) {
    // Default TTL 2 minutes for one-time tokens
    $ttl = (int)$ttl;
    if ($ttl <= 0) $ttl = 120;
    $token = bin2hex(random_bytes(32));
    $token_hash = _url_token_hash($token);
    $raw_token_hash = hash('sha256', $token);
    $expires_at = date('Y-m-d H:i:s', time() + $ttl);
    $now = date('Y-m-d H:i:s');
    try {
        // Ensure table exists
        ensure_url_sessions_table();
        query("REPLACE INTO url_one_time_tokens (token_hash, raw_token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?)", [$token_hash, $raw_token_hash, $user_id, $expires_at, $now]);
        $masked = substr($token,0,6) . '...' . substr($token,-6);
        error_log('create_one_time_url_token: created one-time token for user_id=' . $user_id . ' expires_at=' . $expires_at);
        // Immediate verification: read back the row we just wrote and log its presence
        try {
            $verify = query("SELECT token_hash, raw_token_hash, user_id, expires_at, created_at FROM url_one_time_tokens WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1", [$token_hash, $raw_token_hash]);
            $vrow = $verify->fetch(PDO::FETCH_ASSOC);
            if ($vrow) {
                error_log('[VERIFY_ONE_TIME] row written user_id=' . $vrow['user_id'] . ' expires_at=' . $vrow['expires_at']);
            } else {
                error_log('[VERIFY_ONE_TIME] row NOT found after write (token suppressed)');
            }
        } catch (Exception $ve) {
            error_log('[VERIFY_ONE_TIME] verification query error: ' . $ve->getMessage());
        }
        return $token;
    } catch (Exception $e) {
        error_log('create_one_time_url_token error: ' . $e->getMessage());
        return false;
    }
}
UNUSED_END one_time_token */

function validate_one_time_url_token($token) {
    if (!is_string($token) || $token === '') return false;
    $token = preg_replace('/[^A-Fa-f0-9]/', '', $token);
    if ($token === '') return false;
    $token_hash = _url_token_hash($token);
    $raw_token_hash = hash('sha256', $token);
    $pdo = db_connect();
    try {
        // Diagnostic: log token characteristics (masked) to help debug missing/consumed tokens
        $masked = substr($token,0,6) . '...' . substr($token,-6);
        error_log('[DIAG_VALIDATE] validate_one_time_url_token: attempt received (token masked and hash suppressed) len=' . strlen($token));
        // Log request-level context to help diagnose browser-specific behaviors (method, referer, cookies present)
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $cookie_keys = array_keys($_COOKIE ?? []);
        // Avoid logging cookie names/values; only report whether cookies are present
        error_log('[DIAG_VALIDATE] request method=' . $method . ' referer=' . ($referer?:'<none>') . ' cookies_present=' . (empty($cookie_keys) ? 0 : 1));
        $pdo->beginTransaction();
        // Select for update to avoid race conditions
        $stmt = $pdo->prepare("SELECT user_id FROM url_one_time_tokens WHERE (token_hash = ? OR raw_token_hash = ?) AND expires_at > NOW() LIMIT 1 FOR UPDATE");
        $stmt->execute([$token_hash, $raw_token_hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            error_log('validate_one_time_url_token: no matching token found (hash suppressed)');
            $pdo->rollBack();
            return false;
        }
        error_log('validate_one_time_url_token: row found for user_id=' . $row['user_id']);
        // Delete the token (single-use)
        $del = $pdo->prepare("DELETE FROM url_one_time_tokens WHERE token_hash = ? OR raw_token_hash = ?");
        $del->execute([$token_hash, $raw_token_hash]);
        error_log('validate_one_time_url_token: deleted token (hash suppressed)');
        $pdo->commit();
        return (int)$row['user_id'];
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
        error_log('validate_one_time_url_token error: ' . $e->getMessage());
        return false;
    }
}

function _url_token_hash($token) {
    return hash_hmac('sha256', $token, URL_SESSION_SECRET);
}

function _user_agent_hash() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? hash_hmac('sha256', $_SERVER['HTTP_USER_AGENT'], URL_SESSION_SECRET) : '';
}

function _ip_prefix() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return ($parts[0] ?? '') . '.' . ($parts[1] ?? ''); // first 2 octets
    }
    return substr($ip, 0, 64);
}

if (!empty($_REQUEST['sid'])) {
    // Allow base64url payloads and dot separator: keep A-Z a-z 0-9, dot, underscore and hyphen
    $sid_raw = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
    $sid_hash = _url_token_hash($sid_raw);
    try {
        // Debug: log incoming sid (masked) and hash prefix for troubleshooting
        $masked_in = substr($sid_raw, 0, 6) . '...' . substr($sid_raw, -6);
        error_log('URL session attempt: sid lookup (token suppressed)');
        // Ensure table exists (lightweight migration)
        ensure_url_sessions_table();

        $stmt = query("SELECT user_id, ua_hash, ip_prefix, grants_premium, revoked_at, expires_at, created_at FROM url_sessions WHERE (token_hash = ? OR raw_token_hash = ?) AND expires_at > NOW() LIMIT 1", [$sid_hash, hash('sha256', $sid_raw)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            error_log('URL session not found or expired (token/hash suppressed) (raw_len=' . strlen($sid_raw) . ')');
            // Extra debug: check for any approximate matches by prefix to help debug issues with variants
            try {
                $pref = substr($sid_hash,0,8);
                $stmtp = query("SELECT COUNT(*) AS c FROM url_sessions WHERE token_hash LIKE CONCAT(?, '%')", [$pref]);
                $ct = (int)$stmtp->fetch(PDO::FETCH_ASSOC)['c'];
                if ($ct > 0) {
                    error_log('URL session prefix match count for token_hash prefix ' . $pref . ' = ' . $ct);
                }
                $raw_pref = substr(hash('sha256', $sid_raw),0,8);
                $stmtr = query("SELECT COUNT(*) AS c FROM url_sessions WHERE raw_token_hash LIKE CONCAT(?, '%')", [$raw_pref]);
                $ctr = (int)$stmtr->fetch(PDO::FETCH_ASSOC)['c'];
                if ($ctr > 0) {
                    error_log('URL session prefix match count for raw_token_hash prefix ' . $raw_pref . ' = ' . $ctr);
                }
            } catch (Exception $_e) {
                // ignore
            }
            // Try stateless token fallback: validate signed token directly
            try {
                // Try compact token first (shorter HMAC/truncated form)
                $stateless_user = validate_compact_stateless_token($sid_raw);
                if (!$stateless_user) {
                    // Fallback to full stateless token format
                    $stateless_user = validate_stateless_url_token($sid_raw);
                }
                if ($stateless_user) {
                    $GLOBALS['url_session_user_id'] = $stateless_user;
                    error_log('Stateless URL token validated for user_id=' . $stateless_user);
                    // skip further DB checks
                    $row = null;
                }
            } catch (Exception $e) {
                error_log('Stateless token validation error: ' . $e->getMessage());
            }
        }
            if ($row && !empty($row['user_id'])) {
                // Skip tokens explicitly revoked
                if (!empty($row['revoked_at'])) {
                    error_log('URL session revoked for user_id=' . $row['user_id']);
                } else {
                    // For mobile compatibility, skip UA/IP checks entirely for URL sessions
                    $GLOBALS['url_session_user_id'] = (int)$row['user_id'];
                    $GLOBALS['url_session_grants_premium'] = !empty($row['grants_premium']) ? 1 : 0;
                    // Log successful URL session validation (mask actual token) and grant info
                    $masked = substr($sid_raw, 0, 6) . '...' . substr($sid_raw, -6);
                    error_log('URL session validated for user_id=' . $row['user_id'] . ' grants_premium=' . $GLOBALS['url_session_grants_premium']);
                }
            }
    } catch (Exception $e) {
        error_log('URL session validation error: ' . $e->getMessage());
    }
}

function create_url_session($user_id, $ttl = null, $grants_premium = false) {
    // Allow DB override via premium settings
    $db_ttl = null;
    try {
        $db_ttl = (int)get_premium_setting('url_session_ttl', URL_SESSION_TTL);
    } catch (Exception $e) {
        $db_ttl = URL_SESSION_TTL;
    }
    $ttl = $ttl ?? min(SESSION_LIFETIME, max(60, $db_ttl));
        $token = bin2hex(random_bytes(16));  // Shorter token for mobile URL compatibility
    $token_hash = _url_token_hash($token);
    $raw_token_hash = hash('sha256', $token);
    $ua_hash = _user_agent_hash();
    $ip_pref = _ip_prefix();
    $grants_premium = $grants_premium ? 1 : 0;
    try {
        query("CREATE TABLE IF NOT EXISTS url_sessions (
            token_hash VARCHAR(64) PRIMARY KEY,
            raw_token_hash VARCHAR(64) DEFAULT '',
            user_id INT NOT NULL,
            ua_hash VARCHAR(64) DEFAULT '',
            ip_prefix VARCHAR(64) DEFAULT '',
            grants_premium TINYINT DEFAULT 0,
            revoked_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Cleanup expired sessions opportunistically
        cleanup_expired_url_sessions();
        // Use DB time for expires_at to avoid DB/PHP clock skew issues
        try {
            $pdo = db_connect();
            $stmt = $pdo->prepare("REPLACE INTO url_sessions (token_hash, raw_token_hash, user_id, ua_hash, ip_prefix, grants_premium, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())");
            $stmt->execute([$token_hash, $raw_token_hash, $user_id, $ua_hash, $ip_pref, $grants_premium, $ttl]);
            // Log creation (masked token) for debugging
            $masked = substr($token, 0, 6) . '...' . substr($token, -6);
            // Fetch expires_at from DB to show accurate expiry
            $check = $pdo->prepare("SELECT expires_at, grants_premium FROM url_sessions WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1");
            $check->execute([$token_hash, $raw_token_hash]);
            $chkRow = $check->fetch(PDO::FETCH_ASSOC);
            $expires_at = $chkRow['expires_at'] ?? '<unknown>';
            $gp = !empty($chkRow['grants_premium']) ? 1 : 0;
            error_log('create_url_session: created url_session for user_id=' . $user_id . ' ua_hash_present=' . (!empty($ua_hash)?1:0) . ' ip_pref=' . $ip_pref . ' grants_premium=' . $gp . ' expires_at=' . $expires_at);
            // Verify row exists immediately (diagnostic)
            if ($chkRow) {
                error_log('create_url_session verification: row found user_id=' . $user_id . ' ua_hash_present=' . (!empty($ua_hash)?1:0) . ' ip_pref=' . ($ip_pref?:'<none>'));
            } else {
                error_log('create_url_session verification: row NOT found for token (hash suppressed)');
            }
            return $token;
        } catch (Exception $e) {
            error_log('create_url_session error: ' . $e->getMessage());
            return false;
        }
    } catch (Exception $e) {
        error_log('create_url_session error: ' . $e->getMessage());
        return false;
    }
}

function cleanup_expired_url_sessions() {
    try {
        query("DELETE FROM url_sessions WHERE expires_at <= NOW()");
    } catch (Exception $e) {
        error_log('cleanup_expired_url_sessions error: ' . $e->getMessage());
    }
}

// CSRF Protection Functions
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    // Standard session-based CSRF check
    if (isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    // If there's no session CSRF (users who rejected cookies), allow a sid-based verification
    if (!empty($GLOBALS['url_session_user_id'])) {
        $sid = $_REQUEST['sid'] ?? '';
        $sid = preg_replace('/[^A-Za-z0-9._-]/', '', $sid);
        if (empty($sid)) return false;
        try {
            $uid = validate_compact_stateless_token($sid);
            if (!$uid) {
                $uid = validate_stateless_url_token($sid);
            }
            return $uid && $uid === (int)$GLOBALS['url_session_user_id'];
        } catch (Exception $e) {
            return false;
        }
    }
    return false;
}

// Rate Limiting Functions
function check_rate_limit($action, $identifier, $max_attempts = null, $window = null) {
    $max_attempts = $max_attempts ?? MAX_LOGIN_ATTEMPTS;
    $window = $window ?? LOGIN_LOCKOUT_TIME;
    
    $key = $action . '_' . $identifier;
    $now = time();
    
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = ['count' => 0, 'first_attempt' => $now];
    }
    
    $data = &$_SESSION['rate_limit'][$key];
    
    // Reset if window expired
    if ($now - $data['first_attempt'] > $window) {
        $data = ['count' => 0, 'first_attempt' => $now];
    }
    
    $data['count']++;
    
    return $data['count'] <= $max_attempts;
}

function reset_rate_limit($action, $identifier) {
    $key = $action . '_' . $identifier;
    if (isset($_SESSION['rate_limit'][$key])) {
        unset($_SESSION['rate_limit'][$key]);
    }
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function get_current_user_id() {
    if (is_logged_in()) {
        return $_SESSION['user_id'];
    }
    // fallback to url session
    if (!empty($GLOBALS['url_session_user_id'])) {
        return $GLOBALS['url_session_user_id'];
    }
    return null;
}

function is_admin() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return false;
    }
    require_once __DIR__ . '/functions.php';
    $user = get_user($user_id);
    // Legacy check: keep compatibility with existing `users.role === 'admin'`
    if ($user && !empty($user['role']) && $user['role'] === 'admin') return true;

    // Prefer RBAC: if user has a role with broad permissions, consider admin-like
    $user_roles = get_user_roles($user_id);
    foreach ($user_roles as $r) {
        if ($r['key'] === 'superadmin') return true;
    }
    return false;
}

/**
 * Is the current logged-in user a superadmin role specifically?
 */
function is_superadmin($user_id = null) {
    if ($user_id === null) $user_id = get_current_user_id();
    if (!$user_id) return false;
    $roles = get_user_roles($user_id);
    foreach ($roles as $r) {
        if ($r['key'] === 'superadmin') return true;
    }
    return false;
}

/**
 * Check whether a given user (or current session user) has the named admin permission.
 * Returns boolean. This consults `user_roles` -> `role_permissions` -> `permissions.key`.
 */
function admin_has_perm($user_id = null, $perm_key = null) {
    if (!$perm_key) return false;
    if ($user_id === null) $user_id = get_current_user_id();
    if (!$user_id) return false;

    // Legacy "admin" role (stored on users.role) grants full access.
    $user = get_user($user_id);
    if ($user && !empty($user['role']) && $user['role'] === 'admin') {
        return true;
    }

    // Superadmin shortcut (fast-path)
    $roles = get_user_roles($user_id);
    foreach ($roles as $r) {
        if ($r['key'] === 'superadmin') return true;
    }

    // Query DB for permission via role_permissions
    $stmt = query("SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? AND p.`key` = ? LIMIT 1", [$user_id, $perm_key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (bool)$row;
}

/**
 * Require that current user has the named admin permission or die with a redirect/403.
 */
function require_admin_perm($perm_key) {
    if (!is_logged_in()) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
    $uid = get_current_user_id();
    if (!admin_has_perm($uid, $perm_key)) {
        header('Location: ' . BASE_PATH . '/admin/index.php');
        exit;
    }
}

// Blocked-IP middleware: check whether current remote address is blocked and deny access (except for admins)
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote_ip) {
    require_once __DIR__ . '/functions.php';
    try {
        if (is_ip_blocked($remote_ip)) {
            // Allow admins (if logged in) to access admin pages to unblock if necessary
            $allow_admin = false;
            if (isset($_SESSION['user_id'])) {
                $allow_admin = is_admin();
            }
            if (!$allow_admin) {
                // Render a simple friendly block page and exit
                header('Content-Type: text/html; charset=UTF-8', true, 403);
                echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Engellendiniz</title></head><body><div class="admin-empty-state">';
                echo '<h2 class="muted-strong">Erişim engellendi</h2>';
                echo '<p>Bu IP adresinden şüpheli faaliyetler tespit edildiği için erişiminiz kısıtlanmıştır. Lütfen birkaç dakika sonra tekrar deneyin veya yöneticilerle iletişime geçin.</p>';
                echo '</div></body></html>';
                exit;
            }
        }
    } catch (Exception $e) {
        // On error, do not block; just continue
    }
}

function login($username, $password) {
    // Rate limiting for login attempts
    $identifier = $username . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!check_rate_limit('login', $identifier)) {
        return false;
    }
    
    $stmt = query("SELECT id, password_hash, email_verified, deleted_at, is_active FROM users WHERE username = ?", [$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return false;
    }
    
    // Check if account is deleted
    if ($user['deleted_at']) {
        return ['error' => 'account_deleted'];
    }
    
    // Check if email is verified
    if (!$user['email_verified']) {
        return ['error' => 'email_not_verified'];
    }
    
    if (password_verify($password, $user['password_hash'])) {
        // Reactivate account if it was disabled
        if ($user['is_active'] == 0) {
            query("UPDATE users SET is_active = 1 WHERE id = ?", [$user['id']]);
        }
        
        // Mark user as online
        query("UPDATE users SET is_online = 1, last_activity = NOW() WHERE id = ?", [$user['id']]);
        
        // Regenerate session ID to prevent session fixation when cookies are in use
        if (!empty($_COOKIE[session_name()])) {
            session_regenerate_id(true);
        }

        // Avoid logging session IDs or header lists (sensitive). Keep a concise, non-sensitive marker in non-production.
        if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'production') {
            error_log('login: session regenerated for user_id=' . $user['id']);
        }

        $_SESSION['user_id'] = $user['id'];
        reset_rate_limit('login', $identifier);
        return true;
    }
    return false;
}

/* UNUSED_START register
function register($username, $password, $email = null) {
    // Validate password strength
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return false;
    }
    
    if (REQUIRE_PASSWORD_COMPLEXITY) {
        // Require at least one letter and one number
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return false;
        }
    }
    
    // Validate email format if provided
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    query("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)", [$username, $hash, $email]);
    return insert_id();
}
UNUSED_END register */

function logout() {
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        $path = $params['path'] ?? '/';
        $domain = $params['domain'] ?? '';
        $secure = $params['secure'] ?? false;
        $httponly = $params['httponly'] ?? true;
        // Expire the cookie using the same params the session was set with
        setcookie(session_name(), '', time() - 42000, $path, $domain, $secure, $httponly);
    }
    session_destroy();
}
?>