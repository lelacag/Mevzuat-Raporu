<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Create role via server-side POST + redirect (no JSON for admin UI)
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

$key = preg_replace('/[^a-z0-9_\-]/i', '', trim($_POST['key']));
$name = trim($_POST['name']);
$description = trim($_POST['description'] ?? '');

try {
    query("INSERT IGNORE INTO roles (`key`, `name`, description) VALUES (?, ?, ?)", [$key, $name, $description]);
    log_admin_action('create_role', 'created role=' . $key, get_current_user_id());
    $_SESSION['flash'] = 'Role created';
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/roles.php');
    exit;
}
