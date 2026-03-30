<?php
/**
 * Leave Group Handler
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    die('Invalid CSRF token');
}

$group_id = intval($_POST['group_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'groups.php';

if (!$group_id) {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

try {
    // Remove user from group
    $stmt = $pdo->prepare("
        DELETE FROM group_members 
        WHERE group_id = ? AND user_id = ?
    ");
    $stmt->execute([$group_id, $user_id]);
    $_SESSION['flash'] = 'Gruptan ayrıldınız';
    
} catch (PDOException $e) {
    error_log('Group leave error: ' . $e->getMessage());
}

header('Location: ' . BASE_PATH . '/' . $redirect);
exit;
