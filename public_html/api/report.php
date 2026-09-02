<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_csrf();

$reporter_id = get_current_user_id(); // may be null
$target_type = $_POST['target_type'] ?? '';
$target_id = intval($_POST['target_id'] ?? 0);
$reason = sanitize_input($_POST['reason'] ?? null);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php');
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

if (!in_array($target_type, ['post', 'reply', 'group_post'])) {
    $_SESSION['flash_error'] = 'Geçersiz rapor türü.';
    header('Location: ' . $referer);
    exit;
}

if ($target_id <= 0) {
    $_SESSION['flash_error'] = 'Geçersiz rapor hedefi.';
    header('Location: ' . $referer);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
try {
    report_item($reporter_id, $target_type, $target_id, $reason, $ip);
    $_SESSION['flash'] = 'Raporunuz alındı. Yetkililere bildiriliyor.';
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Rapor gönderilirken bir hatayla karşılaşıldı. Lütfen tekrar deneyin.';
    header('Location: ' . $referer);
    exit;
}

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

$notified = false;
try {
    $notified = notify_platform_admins($subject, $body);
} catch (Exception $e) {
    // If notification itself fails, we still keep report action successful.
}

if (!$notified) {
    $_SESSION['flash'] = 'Raporunuz alındı, ancak yönetici bildiriminde geçici bir sorun oluştu.';
}

header('Location: ' . $referer);
exit;
?>