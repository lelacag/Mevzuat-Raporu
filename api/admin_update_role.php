<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_logged_in() || !admin_has_perm(null, 'manage_roles')) {
    $_SESSION['flash_error'] = t('unauthorized') !== 'unauthorized' ? t('unauthorized') : 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['role_key'])) {
    $_SESSION['flash_error'] = 'role_key gerekli';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

$role_key = preg_replace('/[^a-z0-9_\-]/i', '', (string)$_POST['role_key']);
$perms = $_POST['perms'] ?? [];
if (!is_array($perms)) $perms = [];

// Only permissions that are actually gated in admin code.
$allowed_perm_keys = [
    'view_admin_dashboard',
    'manage_users',
    'approve_profiles',
    'view_reports',
    'moderate_content',
    'manage_bad_words',
    'manage_whitelist',
    'manage_roles',
    'manage_events',
    'manage_badges',
    'manage_billing',
    'view_billing_reports',
    'manage_notifications',
    'view_system_logs',
];

// Non-admin / system roles cannot receive admin permission matrix edits.
$locked_roles = ['superadmin', 'member', 'rookie'];

try {
    $role = get_role_by_key($role_key);
    if (!$role) {
        throw new Exception('Role not found');
    }

    if (in_array($role_key, $locked_roles, true)) {
        throw new Exception('Bu rolün izinleri düzenlenemez');
    }

    // Normalize + allow-list only
    $clean = [];
    foreach ($perms as $pkey) {
        $pkey = preg_replace('/[^a-z0-9_\-]/i', '', (string)$pkey);
        if ($pkey !== '' && in_array($pkey, $allowed_perm_keys, true)) {
            $clean[$pkey] = true;
        }
    }
    $perms = array_keys($clean);

    query("DELETE FROM role_permissions WHERE role_id = ?", [$role['id']]);

    foreach ($perms as $pkey) {
        $stmt = query("SELECT id FROM permissions WHERE `key` = ? LIMIT 1", [$pkey]);
        $prow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prow) {
            query(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)",
                [$role['id'], $prow['id']]
            );
        }
    }

    log_admin_action(
        'update_role',
        'updated role ' . $role_key . ' perms=' . implode(',', $perms),
        get_current_user_id()
    );

    $_SESSION['flash'] = t('role_updated') !== 'role_updated' ? t('role_updated') : 'Rol güncellendi';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}
