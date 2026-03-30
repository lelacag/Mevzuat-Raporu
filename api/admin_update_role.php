<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Require proper permission
if (!is_logged_in() || !admin_has_perm(null, 'manage_roles')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['role_key'])) {
    $_SESSION['flash_error'] = 'role_key gerekli';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

$role_key = $_POST['role_key'];
$perms = $_POST['perms'] ?? [];
if (!is_array($perms)) $perms = [];

try {
    $role = get_role_by_key($role_key);
    if (!$role) throw new Exception('Role not found');

    // Prevent non-superadmins from modifying the superadmin role
    if ($role_key === 'superadmin' && !is_superadmin(get_current_user_id())) {
        throw new Exception('Not allowed');
    }

    // Delete existing mappings
    query("DELETE FROM role_permissions WHERE role_id = ?", [$role['id']]);

    // Insert new mappings (ignore unknown permissions)
    foreach ($perms as $pkey) {
        $stmt = query("SELECT id FROM permissions WHERE `key` = ? LIMIT 1", [$pkey]);
        $prow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prow) {
            query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)", [$role['id'], $prow['id']]);
        }
    }

    // Audit log
    log_admin_action('update_role', 'updated role ' . $role_key . ' perms=' . implode(',', $perms), get_current_user_id());

    // Redirect back for form POST
    $_SESSION['flash'] = 'Role updated';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}
