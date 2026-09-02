<?php /* EN + TR comments used. */
// Legacy URL — subscriptions UI is merged into premium_users.php
require_once __DIR__ . '/../includes/config.php';
$target = BASE_PATH . '/admin/premium_users.php#subscriptions';
header('Location: ' . $target, true, 301);
exit;
