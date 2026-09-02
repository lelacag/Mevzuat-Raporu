<?php
// Disabled in production — was dumping $_SERVER (including secrets) to /tmp
// To re-enable for debugging, uncomment the line below:
// file_put_contents("/tmp/auto_prepend.log", date('Y-m-d H:i:s') . " " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);
?>
