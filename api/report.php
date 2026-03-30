<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$reporter_id = get_current_user_id(); // may be null
$target_type = $_POST['target_type'] ?? '';
$target_id = intval($_POST['target_id'] ?? 0);
$reason = sanitize_input($_POST['reason'] ?? null);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php');
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

if (!in_array($target_type, ['post', 'reply', 'group_post'])) {
    header('Location: ' . $referer);
    exit;
}

if ($target_id <= 0) {
    header('Location: ' . $referer);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
report_item($reporter_id, $target_type, $target_id, $reason, $ip);

// Notify platform admins about incoming report
$reporter = $reporter_id ? get_user($reporter_id) : null;
$reporter_name = $reporter ? $reporter['username'] : 'Anonim';

$subject = sprintf('[%s] Yeni içerik raporu: %s #%d', SITE_NAME, $target_type, $target_id);
$body = "Merhaba Yönetici,\n\n";
$body .= "{$reporter_name} tarafından yeni bir rapor oluşturuldu:\n";
$body .= "Hedef türü: {$target_type}\n";
$body .= "Hedef ID: {$target_id}\n";
$body .= "Sebep: " . ($reason ?: 'Belirtilmedi') . "\n\n";
$body .= "Lütfen admin panelinden inceleyin: " . full_url(BASE_PATH . '/admin/districts.php') . "\n\n";
$body .= "Teşekkürler,\n" . SITE_NAME;

notify_platform_admins($subject, $body);

header('Location: ' . $referer);
exit;
?>