<?php
/**
 * Module: event_codes.php — Event code generation for premium users
 */

if (!function_exists('generate_event_code_string')) {
function generate_event_code_string($length = 6) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
}

if (!function_exists('get_or_create_event_code')) {
function get_or_create_event_code($user_id) {
    try {
        $col_check = query("SHOW COLUMNS FROM users LIKE 'event_code'")->fetch();
        if (!$col_check) return '';
    } catch (Exception $e) { return ''; }

    $stmt = query("SELECT event_code FROM users WHERE id = ? LIMIT 1", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['event_code'])) return $row['event_code'];

    $tries = 0;
    do {
        $code = generate_event_code_string(6);
        $exists = query("SELECT id FROM users WHERE event_code = ? LIMIT 1", [$code])->fetch();
        $tries++;
        if ($tries > 20) throw new Exception('Unable to generate unique event code');
    } while ($exists);

    query("UPDATE users SET event_code = ? WHERE id = ?", [$code, $user_id]);
    return $code;
}
}

if (!function_exists('regenerate_event_code')) {
function regenerate_event_code($user_id) {
    try {
        $col_check = query("SHOW COLUMNS FROM users LIKE 'event_code'")->fetch();
        if (!$col_check) return '';
    } catch (Exception $e) { return ''; }

    $tries = 0;
    do {
        $code = generate_event_code_string(6);
        $exists = query("SELECT id FROM users WHERE event_code = ? LIMIT 1", [$code])->fetch();
        $tries++;
        if ($tries > 20) throw new Exception('Unable to generate unique event code');
    } while ($exists);

    query("UPDATE users SET event_code = ? WHERE id = ?", [$code, $user_id]);
    return $code;
}
}
