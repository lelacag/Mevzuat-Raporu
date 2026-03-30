<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_PATH . '/admin/captcha_offenders.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['admin_error'] = 'Invalid CSRF'; header('Location: ' . BASE_PATH . '/admin/captcha_offenders.php'); exit; }

$ip = $_POST['ip'] ?? '';
$duration = intval($_POST['duration'] ?? 3600);
$reason = $_POST['reason'] ?? 'admin_block';
$admin_id = get_current_user_id();
if (block_ip($ip, $reason, $duration, $admin_id)) {
    $_SESSION['admin_msg'] = 'Blocked ' . $ip;
} else {
    $_SESSION['admin_error'] = 'Failed to block ' . $ip;
}
header('Location: ' . BASE_PATH . '/admin/captcha_offenders.php');
exit;
