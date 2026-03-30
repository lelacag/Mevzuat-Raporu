<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$admin = get_user($admin_id);
if (!$admin || !admin_has_perm($admin_id, 'moderate_content')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/reports.php');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/reports.php');
    exit;
}

$report_id = intval($_POST['report_id'] ?? 0);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/admin/reports.php');
$referer = validate_referer($referer, BASE_PATH . '/admin/reports.php', true);

if ($report_id > 0) {
    admin_resolve_report($admin_id, $report_id);
    log_admin_action('resolve_report', 'resolved report_id=' . $report_id, $admin_id);
}

header('Location: ' . $referer);
exit;
?>