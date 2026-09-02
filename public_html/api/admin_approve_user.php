<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Admin: approve a pending user (form POST + redirect only)
if (!is_logged_in() || !admin_has_perm(null, 'manage_users')) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['user_id'])) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}

$user_id = intval($_POST['user_id']);
try {
    $user = query("SELECT username, is_approved FROM users WHERE id = ?", [$user_id])->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $_SESSION['flash_error'] = 'User not found';
        header('Location: ' . BASE_PATH . '/admin/pending_users.php');
        exit;
    }
    if ($user['is_approved'] == 1) {
        $_SESSION['flash_error'] = 'User already approved';
        header('Location: ' . BASE_PATH . '/admin/pending_users.php');
        exit;
    }

    query("UPDATE users SET is_approved = 1, role = 'member' WHERE id = ?", [$user_id]);
    $rookie_badge = query("SELECT id FROM badges WHERE slug = 'yeni-gelen' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rookie_badge) {
        query("DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?", [$user_id, $rookie_badge['id']]);
    }
    // Now that user is approved, grant any tier badges they have earned via likes
    sync_user_badges_by_likes($user_id);
    $notification_text = "Hesabınız onaylandı.";
    query("INSERT INTO notifications (user_id, type, text, created_at) VALUES (?, 'system', ?, NOW())", [$user_id, $notification_text]);

    log_admin_action('approve_user', 'approved user_id=' . $user_id, get_current_user_id());

    $_SESSION['flash'] = 'User ' . htmlspecialchars($user['username']) . ' approved successfully';
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}
