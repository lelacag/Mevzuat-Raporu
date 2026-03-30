<?php
// invite-related helper functions moved out of includes/functions.php

function ensure_invitations_table() {
    try {
        // avoid foreign key errors on badly‑configured users table by omitting FKs
        query("CREATE TABLE IF NOT EXISTS user_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invited_by INT NOT NULL,
            invited_user INT DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            status ENUM('pending','accepted','expired') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME DEFAULT NULL,
            INDEX idx_invites_by (invited_by),
            INDEX idx_invites_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[INVITES] ensure table error: ' . $e->getMessage());
    }
}

function create_user_invitations($sender_id, array $emails) {
    ensure_invitations_table();
    $pdo = db_connect();
    $out = ['created'=>0,'skipped'=>[]];
    foreach ($emails as $e) {
        $e = mb_strtolower(trim($e));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out['skipped'][] = $e;
            continue;
        }
        // maximum 10 invitations per user
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM user_invitations WHERE invited_by = ?");
        $cnt->execute([$sender_id]);
        if ($cnt->fetchColumn() >= 10) break;

        // skip duplicates
        $dup = $pdo->prepare("SELECT 1 FROM user_invitations WHERE invited_by = ? AND email = ? LIMIT 1");
        $dup->execute([$sender_id, $e]);
        if ($dup->fetch()) {
            $out['skipped'][] = $e;
            continue;
        }

        $token = bin2hex(random_bytes(32));
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO user_invitations (invited_by,email,token) VALUES (?,?,?)"
            );
            $stmt->execute([$sender_id,$e,$token]);
            send_invite_email($e, $token);
            $out['created']++;
        } catch (Exception $x) {
            $out['skipped'][] = $e;
        }
    }
    return $out;
}

// helper to lazily ensure invite_settings table exists
function ensure_invite_settings_table() {
    try {
        query("CREATE TABLE IF NOT EXISTS invite_settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[INVITES] ensure settings table error: ' . $e->getMessage());
    }
}

function invite_get_setting($key, $default = null) {
    ensure_invite_settings_table();
    $stmt = query("SELECT setting_value FROM invite_settings WHERE setting_key = ? LIMIT 1", [$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return $row['setting_value'];
    }
    return $default;
}

function invite_set_setting($key, $value) {
    ensure_invite_settings_table();
    query("INSERT INTO invite_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$key, $value, $value]);
}

function send_invite_email($email, $token) {
    // load template values or fall back to hardcoded defaults
    // default Turkish subject/body if nothing set
    $subject = invite_get_setting('email_subject', 'Seni ' . SITE_NAME . ' sitesine davet ediyoruz');
    $body_template = invite_get_setting('email_body', _default_invite_body());

    // prepare variables
    $link = full_url(invite_url($token));
    $expiry = defined('INVITES_PREMIUM_DAYS') ? INVITES_PREMIUM_DAYS : 30;
    $vars = [
        '{site_name}' => SITE_NAME,
        '{invite_link}' => $link,
        '{expiry_days}' => $expiry,
    ];
    $body = strtr($body_template, $vars);

    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        // use shared helper which may use SMTP/PHPMailer
        send_email($email, $subject, $body);
    }
}

function _default_invite_body() {
    return "Merhaba,\n\n" . SITE_NAME . " sitesine davet edildiniz. Aşağıdaki bağlantı ile kayıt olabilirsiniz:\n\n{invite_link}\n\nBu bağlantı {expiry_days} gün geçerlidir.";
}

function get_premium_features() {
    return [
        '♾️ Sınırsız gönderi uzunluğu',
        '✅ Gönderi düzenleme ve gelişmiş araçlar',
        '⭐ Premium rozet ve özel rozet oluşturma',
        '🔔 Özel etkinlik güncellemelerine erişim',
    ];
}

function accept_invite_if_valid($user_id, $email) {
    ensure_invitations_table();
    if (empty($_GET['invite'])) return false;
    $stmt = query(
        "SELECT * FROM user_invitations WHERE token = ? AND email = ? AND status = 'pending' LIMIT 1",
        [$_GET['invite'], $email]
    );
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return false;

    query("UPDATE user_invitations SET status='accepted', invited_user=?, accepted_at=NOW() WHERE id=?", [$user_id, $inv['id']]);

    // grant 1 month premium to inviter
    $inviter = get_user($inv['invited_by']);
    if ($inviter) {
        $now = time();
        $existing = strtotime($inviter['premium_until'] ?: '0');
        $start = $existing > $now ? $existing : $now;
        $new_until = date('Y-m-d H:i:s', $start + INVITES_PREMIUM_DAYS*24*3600);
        query("UPDATE users SET is_premium=1, premium_until=? WHERE id=?", [$new_until, $inv['invited_by']]);
    }
    return true;
}

function invite_status_label($status) {
    switch ($status) {
        case 'pending': return 'beklemede';
        case 'accepted': return 'kabul edildi';
        case 'expired': return 'süresi doldu';
        default: return htmlspecialchars($status);
    }
}
