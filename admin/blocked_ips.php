<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';

// shared header
include __DIR__ . '/_header.php';

// Read flash messages (from redirects)
if (!empty($_SESSION['admin_msg'])) { $msg = $_SESSION['admin_msg']; unset($_SESSION['admin_msg']); }
if (!empty($_SESSION['admin_error'])) { $error = $_SESSION['admin_error']; unset($_SESSION['admin_error']); }

// Handle unblock / block actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error = 'Invalid CSRF'; }
    else {
        $admin_id = get_current_user_id();
        if (isset($_POST['unblock_ip'])) {
            $ip = $_POST['ip'] ?? '';
            if (unblock_ip($ip, $admin_id)) $msg = 'Unblocked ' . htmlspecialchars($ip);
            else $error = 'Failed to unblock.';
        } elseif (isset($_POST['block_ip'])) {
            $ip = $_POST['ip'] ?? '';
            $reason = $_POST['reason'] ?? 'admin_block';
            $duration = intval($_POST['duration'] ?? 3600);
            if (block_ip($ip, $reason, $duration, $admin_id)) $msg = 'Blocked ' . htmlspecialchars($ip);
            else $error = 'Failed to block.';
        }
    }
}

// pagination and export
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(200, intval($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $per_page;
$export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');

// total count
$stmt = query("SELECT COUNT(*) as cnt FROM blocked_ips");
$total = (int)($stmt->fetch()['cnt'] ?? 0);

$ips = get_blocked_ips($per_page, $offset);
$csrf = generate_csrf_token();

if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="blocked_ips.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ip','reason','blocked_until','created_by','created_at']);
    foreach ($ips as $r) fputcsv($out, [$r['ip'],$r['reason'],$r['blocked_until'],$r['created_by'],$r['created_at']]);
    exit;
}
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page">
    <h1>Blocked IPs</h1>
    <p class="small-muted">Manually blocked IPs and their expiry. Unblocking will be recorded in audit logs.</p>
    <?php if (!empty($msg)): ?><div class="form-alert form-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="form-alert form-alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="search-form" style="margin-bottom:12px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label>IP to block: <input name="ip" required></label>
        <label>Duration (seconds): <input name="duration" value="3600"></label>
        <label>Reason: <input name="reason" value="admin_block"></label>
        <button class="btn btn-save" name="block_ip">Block IP</button>
        <a class="btn" href="?export=csv">Export CSV</a>
    </form>

    <table class="admin-table">
        <thead><tr><th>IP</th><th>Reason</th><th>Blocked Until</th><th>Created By</th><th>Created At</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($ips as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['ip']) ?></td>
                <td><?= htmlspecialchars($r['reason']) ?></td>
                <td><?= htmlspecialchars($r['blocked_until']) ?></td>
                <td><?= htmlspecialchars($r['created_by']) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($r['ip']) ?>">
                        <button class="btn btn-clear" name="unblock_ip">Unblock</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pager">
        <?php $pages = max(1, ceil($total / $per_page));
        for ($p = 1; $p <= $pages; $p++): ?>
            <a class="<?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
