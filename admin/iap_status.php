<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// Admin-only page
$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_billing');

$title = 'IAP Status';
include __DIR__ . '/_header.php';
include __DIR__ . '/_nav.php';

// Fetch server-side status (use absolute URL to avoid ambiguous curl behavior)
$status = null;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$api_url = $scheme . '://' . $host . BASE_PATH . '/api/iap_status.php';
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$resp = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp !== false && $code === 200) {
    $status = json_decode($resp, true) ?: ['raw' => $resp];
} else {
    $status = ['ok' => false, 'messages' => ['failed to fetch iap_status: ' . ($err ?: 'HTTP ' . $code)]];
}
?>

<div class="admin-panel">
    <h1>IAP Readiness Status</h1>
    <?php if (!empty($status['ok'])): ?>
        <p class="success">Test mode: <?= $status['test_mode'] ? 'enabled' : 'disabled' ?></p>
        <h3>Environment</h3>
        <ul>
            <?php foreach ($status['env'] as $k => $v): ?>
                <li><strong><?= htmlspecialchars($k) ?></strong>: <?= htmlspecialchars($v) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (!empty($status['messages'])): ?>
            <h3>Messages</h3>
            <ul>
                <?php foreach ($status['messages'] as $m): ?>
                    <li><?= htmlspecialchars($m) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php else: ?>
        <p class="error">IAP status reporting indicates problems:</p>
        <ul>
            <?php foreach ($status['messages'] as $m): ?>
                <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a href="<?= BASE_PATH ?>/admin/premium_reconcile.php" class="btn">Back to Premium</a></p>
</div>
