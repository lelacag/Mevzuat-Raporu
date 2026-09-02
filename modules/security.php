<?php
// security module — per-request CSRF token pool
// Each call to generate_csrf_token() creates a fresh token and adds it to a
// rotating pool (max 10).  verify_csrf_token() checks against any token in the
// pool and removes the matched one (single-use).  This limits the blast radius
// if a token leaks — only that one form is compromised, not the entire session.

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $token = bin2hex(random_bytes(32));

        // Maintain a rotating pool of recent tokens (FIFO, max 10)
        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        $_SESSION['csrf_tokens'][] = $token;
        // Keep only the 10 most recent tokens
        if (count($_SESSION['csrf_tokens']) > 10) {
            $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], -10);
        }

        // Backward compat: also store as single token for any code that reads it directly
        $_SESSION['csrf_token'] = $token;

        return $token;
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($token)) return false;

        // Check against the token pool first
        if (!empty($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            foreach ($_SESSION['csrf_tokens'] as $i => $stored) {
                if (hash_equals($stored, (string)$token)) {
                    // Remove used token (single-use)
                    unset($_SESSION['csrf_tokens'][$i]);
                    $_SESSION['csrf_tokens'] = array_values($_SESSION['csrf_tokens']);
                    return true;
                }
            }
        }

        // Fallback: check legacy single-token field (covers tokens generated
        // before the pool upgrade, or by code that sets csrf_token directly)
        if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('validate_referer')) {
    function validate_referer($referer, $default = null, $require_admin = false) {
        if ($default === null) {
            $default = $require_admin ? BASE_PATH . '/admin/index.php' : home_url();
        }

        if (empty($referer)) return $default;

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $path = $referer;
        $p = parse_url($referer);
        if (isset($p['host'])) {
            if ($p['host'] !== $host) return $default;
            $path = $p['path'] ?? '/';
            if (!empty($p['query'])) $path .= '?' . $p['query'];
        }

        if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
        $allowed_base = rtrim(BASE_PATH, '/');

        if (strpos($path, $allowed_base) === 0) {
            if ($require_admin && strpos($path, '/admin') === false) return $default;
            return $path;
        }

        if ($require_admin) {
            if (strpos($path, '/admin') === 0) return $path;
            return $default;
        }

        if ($path[0] === '/') return $path;
        return $default;
    }
}

if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf() {
        $token = $_POST['csrf_token'] ?? '';
        if (verify_csrf_token($token)) {
            return;
        }
        http_response_code(403);
        if (!empty($_SESSION)) {
            $_SESSION['flash'] = 'Geçersiz veya süresi dolmuş istek (CSRF). Lütfen tekrar deneyin.';
        }
        $back = $_SERVER['HTTP_REFERER'] ?? (defined('BASE_PATH') ? BASE_PATH . '/index.php' : '/index.php');
        header('Location: ' . $back);
        exit;
    }
}