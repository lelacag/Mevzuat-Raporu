<?php /* EN + TR comments used. */
// Non-JS wrapper: join a district via form POST and redirect back with flash
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Invalid CSRF token';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}

if (!is_logged_in()) {
    $_SESSION['flash_error'] = 'Authentication required';
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$district_id = isset($_POST['district_id']) ? intval($_POST['district_id']) : 0;
if (!$district_id) {
    $_SESSION['flash_error'] = 'District ID required';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT district_id, district_name, district_code, max_members FROM districts WHERE district_id = ? AND is_active = 1");
    $stmt->execute([$district_id]);
    $district = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$district) {
        $_SESSION['flash_error'] = 'District not found';
        header('Location: ' . BASE_PATH . '/district_dashboard.php');
        exit;
    }

    $check = $pdo->prepare("SELECT id FROM user_districts WHERE user_id = ? AND district_id = ? AND is_active = 1");
    $check->execute([get_current_user_id(), $district_id]);
    if ($check->fetch()) {
        $_SESSION['flash_error'] = 'Already a member of this district';
        header('Location: ' . BASE_PATH . '/district_dashboard.php');
        exit;
    }

    $count = $pdo->prepare("SELECT COUNT(*) as count FROM user_districts WHERE district_id = ? AND is_active = 1");
    $count->execute([$district_id]);
    $current = $count->fetch(PDO::FETCH_ASSOC)['count'];
    if ($current >= $district['max_members']) {
        $_SESSION['flash_error'] = 'District has reached maximum member capacity';
        header('Location: ' . BASE_PATH . '/district_dashboard.php');
        exit;
    }

    $insert = $pdo->prepare("INSERT INTO user_districts (user_id, district_id, role, is_active) VALUES (?, ?, 'member', 1)");
    $insert->execute([get_current_user_id(), $district_id]);

    $_SESSION['flash'] = 'Successfully joined district ' . htmlspecialchars($district['district_name']);
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error joining district';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}
