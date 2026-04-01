<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = db_connect();
$stmt = $pdo->query("SELECT id, username, email FROM users WHERE deleted_at IS NULL LIMIT 5");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Users:\n";
foreach ($users as $user) {
    echo $user['id'] . ': ' . $user['username'] . ' (' . $user['email'] . ")\n";
}
?>