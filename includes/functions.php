<?php /* EN + TR comments used. */

require_once __DIR__ . '/time_helper.php';
require_once __DIR__ . '/db.php';

if (!function_exists('is_request_https')) {
    /**
     * Detect whether the original client request was HTTPS, even when the app
     * sits behind a reverse proxy/CDN (e.g. Cloudflare) that terminates TLS
     * and forwards to the origin over plain HTTP. Relying on $_SERVER['HTTPS']
     * alone is unreliable in that setup and causes cookies to be set without
     * the Secure attribute even though the browser connection is HTTPS.
     */
    function is_request_https(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off');
    }
}

if (!function_exists('is_json_request')) {
    function is_json_request(): bool {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return false;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false || stripos($accept, 'text/json') !== false) {
            return true;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (in_array($contentType, ['application/json', 'text/json', 'application/vnd.api+json'], true)) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        return false;
    }
}

if (!function_exists('reject_json_requests')) {
    function reject_json_requests(): void {
        if (!is_json_request()) {
            return;
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'JSON requests are not permitted on this server.';
        exit;
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $forwarded_for = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded_for !== '') {
            foreach (explode(',', $forwarded_for) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $real_ip = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($real_ip !== '' && filter_var($real_ip, FILTER_VALIDATE_IP)) {
            return $real_ip;
        }

        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }
}

// Module loader — domain-specific modules are loaded first, then legacy fallback.
// Module functions use function_exists() guards so they take precedence over legacy.
$module_files = [
    __DIR__ . '/../modules/security.php',
    __DIR__ . '/../modules/polyfills.php',
    __DIR__ . '/../modules/url_helpers.php',
    __DIR__ . '/../modules/rbac.php',
    __DIR__ . '/../modules/bad_words.php',
    __DIR__ . '/../modules/admin_audit.php',
    __DIR__ . '/../modules/invitations.php',
    __DIR__ . '/../modules/user.php',
    __DIR__ . '/../modules/posts.php',
    __DIR__ . '/../modules/polls.php',
    __DIR__ . '/../modules/social.php',
    __DIR__ . '/../modules/notifications.php',
    __DIR__ . '/../modules/render.php',
    __DIR__ . '/../modules/tests.php',
    __DIR__ . '/../modules/schedule_post/schedule_post.php',
    __DIR__ . '/../modules/text.php',
    __DIR__ . '/../modules/tags.php',
    __DIR__ . '/../modules/badges.php',
    __DIR__ . '/../modules/drafts.php',
    __DIR__ . '/../modules/diff.php',
    __DIR__ . '/../modules/email.php',
    __DIR__ . '/../modules/bulk_optin.php',
    __DIR__ . '/../modules/event_codes.php',
    __DIR__ . '/../modules/geo.php',
    __DIR__ . '/../modules/admin.php',
    __DIR__ . '/../modules/groups.php',
];

foreach ($module_files as $mf) {
    if (file_exists($mf)) {
        require_once $mf;
    }
}

// Keep monolithic legacy definitions as fallback (declare guards in modules avoid conflicts)
$legacy = __DIR__ . '/functions_legacy.php';
if (file_exists($legacy)) {
    require_once $legacy;
}

// PSR-4 service layer — provides OOP interface on top of procedural modules
$bootstrap = __DIR__ . '/../src/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}