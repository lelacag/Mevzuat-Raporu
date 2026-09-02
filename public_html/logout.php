<?php /* EN + TR comments used. */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mark user as offline
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/db.php';
    try {
        $pdo = db_connect();
        $stmt = $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
        // Error updating offline status, but continue with logout
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to landing page (root) to match clean-URL setup
$landing = BASE_PATH ? rtrim(BASE_PATH, '/') . '/' : '/';
header('Location: ' . $landing);
exit;
?>

