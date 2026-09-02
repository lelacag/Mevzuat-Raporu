<?php /* EN + TR comments used. */
// Simple health check endpoint
require_once __DIR__ . '/includes/config.php';
// Quick DB check
$ok = true;
$err = '';
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = db_connect();
    $pdo->query('SELECT 1');
} catch (Exception $e) {
    $ok = false;
    $err = $e->getMessage();
}
header('Content-Type: text/plain; charset=utf-8');
if ($ok) {
    echo "GOOD\n";
} else {
    http_response_code(500);
    echo "FAIL\n";
}
