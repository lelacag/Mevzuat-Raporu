<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();

if (!$user_id) {
    http_response_code(401);
    exit;
}

// CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token']))) {
    http_response_code(400);
    echo 'invalid_csrf';
    exit;
}

$notification_id = $_POST['notification_id'] ?? 0;

if ($notification_id) {
    try {
        $pdo = db_connect();
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
    } catch (Exception $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
    }
}

http_response_code(200);
exit;
