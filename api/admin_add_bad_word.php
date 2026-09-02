<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$admin = get_user($admin_id);
if (!$admin || !admin_has_perm($admin_id, 'manage_bad_words')) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badwords.php');
    exit;
}

$word = trim($_POST['word'] ?? '');
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/admin/badwords.php');
$referer = validate_referer($referer, BASE_PATH . '/admin/badwords.php', true);

if (!empty($word)) {
    // sanitize single word
    $word = preg_replace('/[\x00-\x1F\x7F]+/', '', $word);
    if (strlen($word) <= 80) {
        add_bad_word($word, $admin_id);
        log_admin_action('add_bad_word', 'added bad word=' . $word, $admin_id);
    }
}

header('Location: ' . $referer);
exit;
?>
