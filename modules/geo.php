<?php
/**
 * Module: geo.php — Country/geo, signup requests, auto-open countries
 */

if (!function_exists('get_country_by_ip')) {
function get_country_by_ip($ip) {
    if (in_array($ip, ['127.0.0.1', '::1'])) return 'TR';
    if (!empty($_SERVER['GEOIP_COUNTRY_CODE'])) return $_SERVER['GEOIP_COUNTRY_CODE'];
    if (function_exists('geoip_country_code_by_name')) {
        return geoip_country_code_by_name($ip) ?: 'ZZ';
    }
    return 'ZZ';
}
}

if (!function_exists('create_signup_request')) {
function create_signup_request($email, $ip, $country_code, $user_agent = '') {
    ensure_signup_requests_table();
    $email = mb_strtolower(trim($email));
    $ip = filter_var($ip, FILTER_SANITIZE_STRING);
    $country_code = strtoupper(substr($country_code, 0, 2));

    $stmt = query("SELECT COUNT(*) as c FROM signup_requests WHERE ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", [$ip]);
    $c = (int)($stmt->fetch()['c'] ?? 0);
    if ($c >= REQUESTS_MAX_PER_IP_PER_DAY) return ['success' => false, 'error' => 'rate_limit_ip'];

    $stmt = query("SELECT COUNT(*) as c FROM signup_requests WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$email, REQUESTS_MAX_PER_EMAIL_WINDOW_DAYS]);
    $e = (int)($stmt->fetch()['c'] ?? 0);
    if ($e > 0) return ['success' => false, 'error' => 'already_requested'];

    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + (REQUEST_TOKEN_EXPIRY_HOURS * 3600));
    try {
        query("INSERT INTO signup_requests (email, ip, country_code, user_agent, token, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?)",
            [$email, $ip, $country_code, substr($user_agent,0,255), $token, $expires_at]);
    } catch (Exception $e) {
        error_log('[SIGNUP_REQUESTS] insert error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'db_error'];
    }

    $verify_url = BASE_PATH . '/signup_request_verify.php?token=' . urlencode($token);
    $template_file = __DIR__ . '/../templates/email/signup_request_verification.txt';
    if (is_file($template_file)) {
        $tpl = file_get_contents($template_file);
        $body = str_replace(['{{site_name}}','{{verify_url}}','{{expires_hours}}'], [SITE_NAME, full_url($verify_url), REQUEST_TOKEN_EXPIRY_HOURS], $tpl);
        $subject = SITE_NAME . ' - E-posta Doğrulama';
    } else {
        $subject = 'E-posta Doğrulama - ' . SITE_NAME;
        $body = "Merhaba,\n\nLütfen e-posta adresinizi doğrulamak için aşağıdaki bağlantıya tıklayın:\n\n" . full_url($verify_url) . "\n\nBu bağlantı " . REQUEST_TOKEN_EXPIRY_HOURS . " saat sonra geçersiz olacaktır.\n\n- " . SITE_NAME;
    }
    send_email($email, $subject, $body);
    return ['success' => true, 'token' => $token];
}
}

if (!function_exists('verify_signup_request')) {
function verify_signup_request($token) {
    ensure_signup_requests_table();
    $stmt = query("SELECT id, email, country_code, expires_at, status FROM signup_requests WHERE token = ? LIMIT 1", [$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'error' => 'not_found'];
    if ($row['status'] !== 'pending') return ['success' => false, 'error' => 'invalid_status'];
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return ['success' => false, 'error' => 'expired'];
    try {
        query("UPDATE signup_requests SET status = 'verified', verified_at = NOW() WHERE id = ?", [$row['id']]);
        return ['success' => true, 'email' => $row['email'], 'country' => $row['country_code']];
    } catch (Exception $e) {
        error_log('[SIGNUP_REQUESTS] verify update error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'db_error'];
    }
}
}

if (!function_exists('is_country_open')) {
function is_country_open($country_code) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    $stmt = query("SELECT opened FROM open_countries WHERE country_code = ? LIMIT 1", [$country_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (bool)$row['opened'] : false;
}
}

if (!function_exists('open_country')) {
function open_country($country_code, $admin_id = null, $auto = false) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    try {
        query("REPLACE INTO open_countries (country_code, opened, opened_at, opened_by, auto_opened) VALUES (?, 1, NOW(), ?, ?)", [$country_code, $admin_id, $auto ? 1 : 0]);
        log_admin_action('open_country', 'Opened country: ' . $country_code . ' auto=' . ($auto?1:0), $admin_id);
        return true;
    } catch (Exception $e) { error_log('[OPEN_COUNTRY] error: ' . $e->getMessage()); return false; }
}
}

if (!function_exists('close_country')) {
function close_country($country_code, $admin_id = null) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    try {
        query("REPLACE INTO open_countries (country_code, opened, opened_at, opened_by, auto_opened) VALUES (?, 0, NULL, ?, 0)", [$country_code, $admin_id]);
        log_admin_action('close_country', 'Closed country: ' . $country_code, $admin_id);
        return true;
    } catch (Exception $e) { error_log('[OPEN_COUNTRY] close error: ' . $e->getMessage()); return false; }
}
}

if (!function_exists('notify_country_opened')) {
function notify_country_opened($country_code) {
    ensure_signup_requests_table();
    $country = strtoupper(substr($country_code,0,2));
    $stmt = query("SELECT DISTINCT email FROM signup_requests WHERE country_code = ? AND status = 'verified' AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$country, REQUESTS_COUNT_WINDOW_DAYS]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return;

    $template_file = __DIR__ . '/../templates/email/country_opened.txt';
    if (is_file($template_file)) {
        $tpl = file_get_contents($template_file);
        foreach ($rows as $r) {
            $body = str_replace(['{{site_name}}','{{country}}','{{register_url}}'], [SITE_NAME, $country, full_url(invite_url())], $tpl);
            send_email($r['email'], sprintf('%s — %s için kayıt açıldı', SITE_NAME, $country), $body);
        }
    } else {
        foreach ($rows as $r) {
            $body = "Merhaba,\n\nTalebiniz değerlendirilmiş ve şu anda " . $country . " ülkesinden kayıtlar açılmıştır.\n\nKayıt olmak için: " . full_url(invite_url()) . "\n\nTeşekkürler,\n" . SITE_NAME;
            send_email($r['email'], sprintf('%s — %s için kayıt açıldı', SITE_NAME, $country), $body);
        }
    }
}
}

if (!function_exists('notify_platform_admins')) {
function notify_platform_admins($subject, $body) {
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        error_log('[NOTIFY] MAIL_ENABLED false, skipping: ' . $subject);
        return false;
    }
    $stmt = query("SELECT DISTINCT u.id, u.username, u.email FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email != ''
          AND (u.role = 'admin' OR r.`key` = 'superadmin')");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($admins)) { error_log('[NOTIFY] No platform admins found'); return false; }

    $sent = 0;
    foreach ($admins as $admin) {
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) continue;
        if (send_email($admin['email'], $subject, $body)) $sent++;
        else error_log('[NOTIFY] Failed to send admin email to: ' . $admin['email']);
    }
    log_admin_action('notify_platform_admins', 'Subject: ' . substr($subject, 0, 200) . ' | sent=' . $sent, null);
    return $sent > 0;
}
}

if (!function_exists('notify_platform_admins_about_district_online_request')) {
function notify_platform_admins_about_district_online_request($district_id, $district_name, $requester_username, $requester_id = null, $reason = '', $request_id = null) {
    $subject = sprintf('[%s] %s için çevrimiçi erişim talebi', SITE_NAME, $district_name ?: 'Bölge');
    $link = full_url(BASE_PATH . '/admin/districts.php');
    $body = "Merhaba Yönetici,\n\nBölge: " . ($district_name ?: "#$district_id") . "\n";
    if ($request_id) $body .= "Talep ID: " . $request_id . "\n";
    $body .= "Talep Eden: " . $requester_username . " (ID: " . ($requester_id ?: 'N/A') . ")\n";
    $body .= "Sebep: " . ($reason ?: 'Belirtilmedi') . "\n\n";
    $body .= "Lütfen aşağıdaki bağlantıyı ziyaret ederek isteği onaylayın/reddedin:\n" . $link . "\n\nTeşekkürler,\n" . SITE_NAME;
    return notify_platform_admins($subject, $body);
}
}

if (!function_exists('auto_open_countries_check')) {
function auto_open_countries_check() {
    ensure_signup_requests_table();
    $stmt = query("SELECT country_code, COUNT(*) as cnt FROM signup_requests WHERE status = 'verified' AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY country_code", [REQUESTS_COUNT_WINDOW_DAYS]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $country = $r['country_code'];
        $cnt = (int)$r['cnt'];
        if ($cnt >= REQUESTS_AUTO_OPEN_THRESHOLD && !is_country_open($country)) {
            if (REQUESTS_AUTO_OPEN) {
                if (open_country($country, null, true)) notify_country_opened($country);
            } else {
                log_admin_action('country_threshold', 'Country ' . $country . ' reached threshold: ' . $cnt, null);
            }
        }
    }
}
}

if (!function_exists('get_request_counts_by_country')) {
function get_request_counts_by_country($limit = 100) {
    ensure_signup_requests_table();
    $stmt = query("SELECT country_code, SUM(status='verified') as verified_count, SUM(status='pending') as pending_count FROM signup_requests GROUP BY country_code ORDER BY verified_count DESC LIMIT ?", [$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}

if (!function_exists('get_approved_words')) {
function get_approved_words() {
    $stmt = query("SELECT aw.*, u.username as approved_by_name FROM approved_words aw LEFT JOIN users u ON aw.approved_by = u.id ORDER BY aw.approved_at DESC");
    return $stmt->fetchAll();
}
}

if (!function_exists('delete_approved_word')) {
function delete_approved_word($id) { query("DELETE FROM approved_words WHERE id = ?", [$id]); }
}

if (!function_exists('get_pending_posts')) {
function get_pending_posts($limit = 50) {
    $stmt = query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.review_status = 'pending' AND p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT ?", [$limit]);
    return $stmt->fetchAll();
}
}

if (!function_exists('approve_post_review')) {
function approve_post_review($post_id, $admin_id, $words_to_approve = []) {
    query("UPDATE posts SET review_status = 'approved' WHERE id = ?", [$post_id]);
    foreach ($words_to_approve as $word) { approve_word($word, $admin_id); }
    return true;
}
}
