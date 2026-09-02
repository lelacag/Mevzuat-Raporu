<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';

// Include shared admin header for consistent design
include __DIR__ . '/_header.php';

// Simple dashboard for CAPTCHA stats
$failures_stmt = query("SELECT COUNT(*) AS cnt FROM captcha_failures WHERE last_failed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$failures_row = $failures_stmt->fetch(PDO::FETCH_ASSOC);
$failures_last_hour = $failures_row['cnt'] ?? 0;

$gens_stmt = query("SELECT COUNT(*) AS cnt FROM captcha_generations WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$gens_row = $gens_stmt->fetch(PDO::FETCH_ASSOC);
$gens_last_5 = $gens_row['cnt'] ?? 0;

$blocked_stmt = query("SELECT COUNT(*) AS cnt FROM blocked_ips");
$blocked_row = $blocked_stmt->fetch(PDO::FETCH_ASSOC);
$blocked_count = $blocked_row['cnt'] ?? 0;

?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page">
    <h1>CAPTCHA Dashboard</h1>
    <div class="stats-row">
        <div class="stat">
            <h3>CAPTCHA Failures (last 1h)</h3>
            <p style="font-size: 24px; font-weight: bold;"><?= intval($failures_last_hour) ?></p>
        </div>
        <div class="stat">
            <h3>Generations (last 5min)</h3>
            <p style="font-size: 24px; font-weight: bold;"><?= intval($gens_last_5) ?></p>
        </div>
        <div class="stat">
            <h3>Blocked IPs</h3>
            <p style="font-size: 24px; font-weight: bold;"><?= intval($blocked_count) ?></p>
        </div>
    </div>
    <p>Quick links: <a href="<?= BASE_PATH ?>/admin/captcha_offenders.php">View offenders</a> | <a href="<?= BASE_PATH ?>/admin/blocked_ips.php">Manage blocked IPs</a> | <a href="<?= BASE_PATH ?>/admin/audit_logs.php">Audit Logs</a></p>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
