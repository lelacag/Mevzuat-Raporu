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
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/badwords.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badwords.php');
    exit;
}

$word_id = intval($_POST['word_id'] ?? 0);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/admin/badwords.php');
$referer = validate_referer($referer, BASE_PATH . '/admin/badwords.php', true);

if ($word_id) {
    delete_bad_word($word_id);
    log_admin_action('delete_bad_word', 'deleted bad_word_id=' . $word_id, $admin_id);
}

header('Location: ' . $referer);
exit;
?>
