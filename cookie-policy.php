<?php /* EN + TR comments used. */
// Redirect legacy URL to clean URL (avoid duplicate content)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'cookie-policy.php') !== false) {
    header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/cerezler', true, 301);
    exit;
}

require_once __DIR__ . '/includes/header.php';

$page_title = $lang['cookie_policy_title'] ?? 'Çerez Politikası';
?>
