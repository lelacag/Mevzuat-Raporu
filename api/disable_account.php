<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// If this is an AJAX/JSON request, return JSON. Otherwise, redirect after completing.
$isJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

$user_id = get_current_user_id();
if (!$user_id) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    header('Location: ' . BASE_PATH . '/profile_edit.php');
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    header('Location: ' . BASE_PATH . '/profile_edit.php');
    exit;
}

// Set is_active to 0 to disable account
query("UPDATE users SET is_active = 0 WHERE id = ?", [$user_id]);

// Logout user
session_destroy();

if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Redirect to landing page after disabling account.
header('Location: ' . BASE_PATH . '/landing.php');
exit;
