<?php
// security module
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(mt_rand());
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], (string)$token);
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
