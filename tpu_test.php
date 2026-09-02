<?php
require __DIR__ . '/includes/config.php';
function safe($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$action = $_GET['action'] ?? '';
if ($action === 'set' && isset($_GET['value'])) {
    tpu_limit_set_count(intval($_GET['value']));
}
if ($action === 'reset') {
    tpu_limit_reset_count();
    @unlink(__DIR__ . '/tmp/MAINTENANCE');
}
$file = tpu_limit_counter_file();
$count = tpu_limit_get_count();
$limit = TPU_REQUEST_LIMIT;
$host = $_SERVER['HTTP_HOST'] ?? '';
header('Content-Type: text/plain');
echo "FILE={$file}\nCOUNT={$count}\nLIMIT={$limit}\n";
if (!empty($host)) echo "HOST={$host}\n";
?>