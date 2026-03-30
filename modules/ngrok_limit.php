<?php
/**
 * Ngrok request limiting module.
 *
 * Counts requests that arrive with an ngrok host header and enforces a
 * site-wide maintenance mode once a configured threshold is reached.
 *
 * To remove this behavior, delete or rename this file and remove any
 * references in includes/header.php and admin/index.php.
 */

if (!defined('NGROK_REQUEST_LIMIT')) {
    define('NGROK_REQUEST_LIMIT', intval(getenv('NGROK_REQUEST_LIMIT') ?: 100000));
}

if (!defined('NGROK_REQUEST_INITIAL_COUNT')) {
    define('NGROK_REQUEST_INITIAL_COUNT', intval(getenv('NGROK_REQUEST_INITIAL_COUNT') ?: 3200));
}

if (!defined('NGROK_REQUEST_COUNT_FILE')) {
    define('NGROK_REQUEST_COUNT_FILE', __DIR__ . '/../tmp/NGROK_REQUESTS');
}

if (!defined('NGROK_API_TOKEN')) {
    define('NGROK_API_TOKEN', getenv('NGROK_API_TOKEN') ?: '');
}

if (!defined('NGROK_HOSTS')) {
    // Comma-separated list of hostnames (or wildcard *.example.com) that should be
    // treated as coming through ngrok even if the Host header isn't *.ngrok.io.
    define('NGROK_HOSTS', getenv('NGROK_HOSTS') ?: '');
}

/**
 * Determine which file path to use for storing the ngrok request count.
 *
 * If the configured file path is not writable (or its directory is not),
 * fall back to the system temp directory so the limiter still works.
 */
function ngrok_limit_counter_file(): string {
    $configured = NGROK_REQUEST_COUNT_FILE;
    $configuredDir = dirname($configured);

    $canUseConfigured = false;
    if (file_exists($configured)) {
        $canUseConfigured = is_writable($configured);
    } else {
        $canUseConfigured = is_dir($configuredDir) && is_writable($configuredDir);
    }

    if ($canUseConfigured) {
        return $configured;
    }

    // Fallback to system temp directory (usually writable by PHP)
    return rtrim(sys_get_temp_dir(), '/\\') . '/mevzuatraporu_ngrok_requests';
}

function ngrok_limit_storage_status(): array {
    $path = ngrok_limit_counter_file();
    $dir = dirname($path);
    $exists = file_exists($path);
    $writable = ($exists && is_writable($path)) || (!$exists && is_dir($dir) && is_writable($dir));
    return [
        'configured' => NGROK_REQUEST_COUNT_FILE,
        'active' => $path,
        'exists' => $exists,
        'writable' => $writable,
    ];
}

function ngrok_limit_is_request(): bool {
    // If ngrok is proxying traffic (even for a custom domain), it adds headers.
    // Detect any of those headers so we count traffic coming via ngrok.
    foreach ($_SERVER as $k => $v) {
        if (stripos($k, 'HTTP_X_NGROK_') === 0 && $v !== '') {
            return true;
        }
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') return false;
    $host = preg_replace('/:\d+$/', '', $host);

    $suffixes = ['.ngrok.io', '.ngrok-free.app', '.ngrok.app'];
    foreach ($suffixes as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }

    if (!empty(NGROK_HOSTS)) {
        $hosts = array_filter(array_map('trim', explode(',', NGROK_HOSTS)));
        foreach ($hosts as $candidate) {
            if ($candidate === '') continue;
            if (strpos($candidate, '*') === false) {
                if ($host === strtolower($candidate)) {
                    return true;
                }
            } else {
                // Support wildcard prefix like '*.example.com'
                $pattern = str_replace(['*', '.'], ['.*', '\.'], $candidate);
                if (preg_match('/^' . $pattern . '$/i', $host)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function ngrok_limit_get_count(): int {
    $file = ngrok_limit_counter_file();

    if (!file_exists($file)) {
        // Initialize with a non-zero starting count so the cap is closer.
        ngrok_limit_set_count(NGROK_REQUEST_INITIAL_COUNT);
        return NGROK_REQUEST_INITIAL_COUNT;
    }

    $v = @file_get_contents($file);
    if ($v === false) return NGROK_REQUEST_INITIAL_COUNT;
    return intval(trim($v));
}

function ngrok_limit_set_count(int $count): void {
    if ($count < 0) $count = 0;
    $file = ngrok_limit_counter_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$count);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function ngrok_limit_increment_count(): int {
    $file = ngrok_limit_counter_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $newFile = !file_exists($file);
    $fp = @fopen($file, 'c+');
    if (!$fp) return ngrok_limit_get_count();

    flock($fp, LOCK_EX);

    if ($newFile) {
        $count = NGROK_REQUEST_INITIAL_COUNT;
    } else {
        $data = stream_get_contents($fp);
        $count = intval(trim($data));
    }

    $count++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$count);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $count;
}

function ngrok_limit_reset_count(): void {
    @unlink(NGROK_REQUEST_COUNT_FILE);
}

function ngrok_limit_is_reached(): bool {
    return ngrok_limit_get_count() >= NGROK_REQUEST_LIMIT;
}

function ngrok_api_session_count(): ?int {
    // Legacy: count session objects returned by the session list endpoint.
    // (May not be directly tied to a specific host.)
    return ngrok_api_tunnel_count('www.mevzuatraporu.com');
}

function ngrok_api_tunnel_count(string $host): ?int {
    if (NGROK_API_TOKEN === '') {
        return null;
    }

    $url = 'https://api.ngrok.com/tunnels';
    $headers = [
        'Authorization: Bearer ' . NGROK_API_TOKEN,
        'Ngrok-Version: 2',
        'Accept: application/json',
        'User-Agent: mevzuatraporu/1.0',
    ];

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 5,
        ],
        // Some PHP environments have missing CA bundles.
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $resp = @file_get_contents($url, false, stream_context_create($opts));
    if ($resp === false) {
        return null;
    }

    // Count tunnels where public_url contains the target host.
    $count = 0;
    if (preg_match_all('/"public_url"\s*:\s*"([^"]+)"/', $resp, $matches)) {
        foreach ($matches[1] as $url) {
            if (stripos($url, $host) !== false) {
                $count++;
            }
        }
        return $count;
    }

    return null;
}

function ngrok_limit_enforce(): void {
    if (php_sapi_name() === 'cli') return;
    if (!ngrok_limit_is_request()) return;
    $count = ngrok_limit_increment_count();
    if ($count === 1) error_log("ngrok_limit: first ngrok request seen");
    if ($count === NGROK_REQUEST_LIMIT) error_log("ngrok_limit: limit reached ({$count})");
    if ($count < NGROK_REQUEST_LIMIT) return;
    $flag = __DIR__ . '/../tmp/MAINTENANCE';
    if (!file_exists($flag)) {
        @file_put_contents($flag, "enabled by ngrok request limit (count={$count})\n");
    }
}

function ngrok_limit_admin_status(): array {
    $status = [
        'count' => ngrok_limit_get_count(),
        'limit' => NGROK_REQUEST_LIMIT,
        'reached' => ngrok_limit_is_reached(),
    ];

    $status['storage'] = ngrok_limit_storage_status();
    $status['sessions'] = ngrok_api_session_count();
    return $status;
}

function ngrok_limit_admin_handle_post(array $post): array {
    $result = ['msg' => '', 'status' => ngrok_limit_admin_status()];
    if (!empty($post['ngrok_action'])) {
        if ($post['ngrok_action'] === 'reset') {
            ngrok_limit_reset_count();
            @unlink(__DIR__ . '/../tmp/MAINTENANCE');
            $result['msg'] = 'Ngrok istek sayacı sıfırlandı.';
        }
        if ($post['ngrok_action'] === 'set' && isset($post['ngrok_value'])) {
            $value = intval($post['ngrok_value']);
            ngrok_limit_set_count($value);
            @unlink(__DIR__ . '/../tmp/MAINTENANCE');
            $result['msg'] = 'Ngrok istek sayacı ' . $value . ' olarak ayarlandı.';
        }
        $result['status'] = ngrok_limit_admin_status();
    }
    return $result;
}
