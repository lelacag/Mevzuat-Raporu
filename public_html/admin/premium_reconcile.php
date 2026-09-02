<?php /* EN + TR comments used. */
// Legacy URL — reconcile UI is merged into premium_users.php
require_once __DIR__ . '/../includes/config.php';
$target = BASE_PATH . '/admin/premium_users.php#reconcile';
header('Location: ' . $target, true, 301);
exit;
