<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_logged_in() || !admin_has_perm(null, 'manage_roles')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['key']) || empty($_POST['name'])) {
    $_SESSION['flash_error'] = 'Key ve name gerekli';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}

$key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', trim((string)$_POST['key'])));
$name = trim((string)$_POST['name']);
$description = trim((string)($_POST['description'] ?? ''));

$reserved = ['superadmin', 'member', 'rookie', 'admin'];

try {
    if ($key === '' || $name === '') {
        throw new Exception('Key ve name gerekli');
    }
    if (in_array($key, $reserved, true)) {
        throw new Exception('Bu rol anahtarı rezerve');
    }
    if (get_role_by_key($key)) {
        throw new Exception('Bu rol zaten var');
    }

    query(
        "INSERT INTO roles (`key`, `name`, description) VALUES (?, ?, ?)",
        [$key, $name, $description]
    );
    log_admin_action('create_role', 'created role=' . $key, get_current_user_id());
    $_SESSION['flash'] = t('role_created') !== 'role_created' ? t('role_created') : 'Rol oluşturuldu';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}
