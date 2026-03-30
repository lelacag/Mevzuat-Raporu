<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Admin: approve a pending user (form POST + redirect only)
if (!is_logged_in() || !admin_has_perm(null, 'manage_users')) {
    header('Location: ' . BASE_PATH . '/login.php');
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

    // Ensure a persistent system user exists and use it as the sender for approval/greeting
    $system = query("SELECT id FROM users WHERE username = ? LIMIT 1", ['Sistem'])->fetch(PDO::FETCH_ASSOC);
    if ($system && !empty($system['id'])) {
        $system_id = $system['id'];
    } else {
        // Create a non-admin system account (idempotent fallback). Password is a random placeholder — change if required.
        try {
            $pw_hash = password_hash('12541254', PASSWORD_DEFAULT);
            query("INSERT INTO users (username, email, password_hash, role, is_approved, notify_by_email, bio, created_at) VALUES (?, ?, ?, 'member', 1, 0, ?, NOW())", ['Sistem', 'no-reply@mevzuatraporu.com', $pw_hash, '@Mevzuat']);
            $system_id = insert_id();
        } catch (Exception $_ex) {
            // If creation fails for any reason, fall back to null (notifications will still be created without from_user_id)
            error_log('Failed to create Sistem user: ' . $_ex->getMessage());
            $system_id = null;
        }
    }

    // Load optional triggers module and run auto-follow if available
    $trig = __DIR__ . '/../modules/mevzuat_triggers.php';
    if (file_exists($trig)) {
        require_once $trig;
        if (!empty($system_id) && function_exists('mevzuat_auto_follow_on_approve')) {
            try {
                mevzuat_auto_follow_on_approve($system_id, $user_id);
            } catch (Throwable $_t) {
                error_log('mevzuat_auto_follow_on_approve error: ' . $_t->getMessage());
            }
        }
    }

    // Send the standard approval notification with the system user as sender (if available)
    if (!empty($system_id)) {
        create_notification($user_id, 'account_approved', $system_id, null);
    } else {
        // Legacy fallback: insert a system text notification without from_user_id
        $notification_text = "Hesabınız onaylandı.";
        query("INSERT INTO notifications (user_id, type, text, created_at) VALUES (?, 'system', ?, NOW())", [$user_id, $notification_text]);
    }

    // Also insert a friendly greeting from the system account
    $greeting_text = "Hoş Geldiniz! Hesabınız onaylandı ve kafa ayarı yapıldı.";
    if (!empty($system_id)) {
        query("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())", [$user_id, $greeting_text, $system_id]);
    } else {
        query("INSERT INTO notifications (user_id, type, text, created_at) VALUES (?, 'system', ?, NOW())", [$user_id, $greeting_text]);
    }

    log_admin_action('approve_user', 'approved user_id=' . $user_id, get_current_user_id());

    $_SESSION['flash'] = 'User ' . htmlspecialchars($user['username']) . ' approved successfully';
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/pending_users.php');
    exit;
}
