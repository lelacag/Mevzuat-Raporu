<?php
require_once __DIR__ . '/includes/auth.php';

// Restrict access to administrators and local requests only
// This page is intended for internal debugging, not public access.
$current_user_id = get_current_user_id();
$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$is_local && (!$current_user_id || !is_admin())) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

echo "whoami=" . get_current_user() . " uid=" . getmyuid() . " gid=" . getmygid() . "\n";
phpinfo();
