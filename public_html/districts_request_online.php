<?php
// Non-JS wrapper: request district online connection via form GET/POST
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    $_SESSION['flash_error'] = 'Authentication required';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Render a simple form for entering reason
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="content-wrapper">
        <h1>Request Online Access</h1>
        <form method="POST" action="<?= BASE_PATH ?>/districts_request_online.php">
            <input type="hidden" name="district_id" value="<?= isset($_GET['district_id']) ? intval($_GET['district_id']) : 0 ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <div style="margin-bottom:12px;">
                <label for="reason">Reason for connecting to main network:</label><br>
                <textarea name="reason" id="reason" rows="4" style="width:100%;"></textarea>
            </div>
            <button type="submit">Submit Request</button>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// POST: process the request
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Invalid CSRF token';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}

$district_id = isset($_POST['district_id']) ? intval($_POST['district_id']) : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if (!$district_id) {
    $_SESSION['flash_error'] = 'District ID required';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}

try {
    // Verify user is admin of district
    $stmt = $pdo->prepare("SELECT role FROM user_districts WHERE user_id = ? AND district_id = ? AND is_active = 1");
    $stmt->execute([get_current_user_id(), $district_id]);
    $membership = $stmt->fetch();
    if (!$membership || $membership['role'] !== 'admin') {
        $_SESSION['flash_error'] = 'Only district admins can request online access';
        header('Location: ' . BASE_PATH . '/district_dashboard.php');
        exit;
    }

    $check = $pdo->prepare("SELECT id, status FROM district_online_requests WHERE district_id = ? AND status IN ('pending', 'approved', 'active') ORDER BY created_at DESC LIMIT 1");
    $check->execute([$district_id]);
    $existing = $check->fetch();
    if ($existing) {
        $_SESSION['flash_error'] = 'District already has a ' . $existing['status'] . ' online request';
        header('Location: ' . BASE_PATH . '/district_dashboard.php');
        exit;
    }

    $insert = $pdo->prepare("INSERT INTO district_online_requests (district_id, requested_by, status, request_reason) VALUES (?, ?, 'pending', ?)");
    $insert->execute([$district_id, get_current_user_id(), $reason]);
    $request_id = $pdo->lastInsertId();

    // Notify platform admins about the new request.
    $district_stmt = $pdo->prepare("SELECT district_name FROM districts WHERE district_id = ? LIMIT 1");
    $district_stmt->execute([$district_id]);
    $district = $district_stmt->fetch();
    $district_name = $district['district_name'] ?? 'Bilinmeyen bölge';

    $user = get_user(get_current_user_id());
    $requester_username = $user['username'] ?? 'Bilinmeyen kullanıcı';

    notify_platform_admins_about_district_online_request($district_id, $district_name, $requester_username, get_current_user_id(), $reason, $request_id);

    $_SESSION['flash'] = 'Online access request submitted';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error submitting request';
    header('Location: ' . BASE_PATH . '/district_dashboard.php');
    exit;
}
