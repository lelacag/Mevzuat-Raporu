<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow role management to users with the manage_roles permission
if (!is_logged_in() || !admin_has_perm(null, 'manage_roles')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

// Accept POST form only from admin UI — require CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);
$role_key = trim($_POST['role_key'] ?? '');

if (!$user_id) {
    $_SESSION['flash_error'] = 'user_id gerekli';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

// Prevent non-superadmins from assigning the `superadmin` role
$actor_id = get_current_user_id();
if ($role_key === 'superadmin' && !is_superadmin($actor_id)) {
    $_SESSION['flash_error'] = 'Yetkiniz yok';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

try {
    // Remove all role assignments for now (Phase‑1: single-role UX)
    query("DELETE FROM user_roles WHERE user_id = ?", [$user_id]);

    if ($role_key !== '') {
        $role = get_role_by_key($role_key);
        if (!$role) throw new Exception('Role not found');
        query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)", [$user_id, $role['id']]);
    }

    // For UI compatibility, do not overwrite legacy users.role except for member/rookie mapping
    if (in_array($role_key, ['member','rookie'])) {
        query("UPDATE users SET role = ? WHERE id = ?", [$role_key, $user_id]);
    }

    // Audit log
    log_admin_action('assign_role', 'assigned ' . ($role_key ?: '<none>') . ' to user_id=' . $user_id, $actor_id);

    // Redirect-friendly response
    $_SESSION['flash'] = 'Role assignment updated';
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}
