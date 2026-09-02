<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('moderate_content');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_PATH . "/admin/pending_review.php");
    exit;
}

// CSRF protection
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header("Location: " . BASE_PATH . "/admin/pending_review.php");
    exit;
}

$post_id = (int)$_POST['post_id'];
$action = $_POST['action'] ?? '';

if (!$post_id) {
    $_SESSION['error'] = 'Geçersiz gönderi.';
    header("Location: " . BASE_PATH . "/admin/pending_review.php");
    exit;
}

$admin_id = get_current_user_id();

if ($action === 'approve') {
    // Approve post and add words to whitelist
    $words = $_POST['words'] ?? [];
    approve_post_review($post_id, $admin_id, $words);
    $_SESSION['flash'] = 'Gönderi onaylandı ve kelimeler beyaz listeye eklendi.';
    
} elseif ($action === 'approve_only') {
    // Approve post without adding to whitelist
    query("UPDATE posts SET review_status = 'approved' WHERE id = ?", [$post_id]);
    $_SESSION['flash'] = 'Gönderi onaylandı.';
    
} else {
    $_SESSION['error'] = 'Geçersiz işlem.';
}

header("Location: " . BASE_PATH . "/admin/pending_review.php");
exit;
