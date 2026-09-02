<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/profile_edit.php');
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    header('Location: ' . BASE_PATH . '/profile_edit.php');
    exit;
}

// Set is_active to 0 to disable account
query("UPDATE users SET is_active = 0 WHERE id = ?", [$user_id]);

// Logout user
session_destroy();

// Redirect to landing page after disabling account.
header('Location: ' . BASE_PATH . '/landing.php');
exit;
