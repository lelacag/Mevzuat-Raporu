<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_whitelist');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_PATH . "/admin/approved_words.php");
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Geçersiz istek (CSRF)';
    header("Location: " . BASE_PATH . "/admin/approved_words.php");
    exit;
}

$word_id = (int)$_POST['word_id'];

if (!$word_id) {
    $_SESSION['error'] = 'Geçersiz kelime.';
    header("Location: " . BASE_PATH . "/admin/approved_words.php");
    exit;
}

delete_approved_word($word_id);
log_admin_action('delete_approved_word', 'word_id=' . $word_id, $current_user_id);
$_SESSION['flash'] = 'Kelime beyaz listeden çıkarıldı.';

header("Location: " . BASE_PATH . "/admin/approved_words.php");
exit;
