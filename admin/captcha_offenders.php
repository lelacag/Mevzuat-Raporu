<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';

// Include shared admin header for consistent design
include __DIR__ . '/_header.php';

// Read flash messages (from redirects)
if (!empty($_SESSION['admin_msg'])) { $msg = $_SESSION['admin_msg']; unset($_SESSION['admin_msg']); }
if (!empty($_SESSION['admin_error'])) { $error = $_SESSION['admin_error']; unset($_SESSION['admin_error']); }

// Handle unblock action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unblock_ip'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error = 'Invalid CSRF'; }
    else {
        $ip = $_POST['ip'] ?? '';
        $admin_id = get_current_user_id();
        if (unblock_ip($ip, $admin_id)) { $msg = 'IP unblocked: ' . htmlspecialchars($ip); }
        else { $error = 'Failed to unblock.'; }
    }
}

// Filters and pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(200, intval($_GET['per_page'] ?? 25)));
$min_attempts = max(1, intval($_GET['min_attempts'] ?? 1));
$search_ip = trim($_GET['ip'] ?? '');
$offset = ($page - 1) * $per_page;

// CSV export
$export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');

// Build where clause
$where = "WHERE attempts >= ?";
$params = [$min_attempts];
if ($search_ip !== '') {
    $where .= " AND ip LIKE ?";
    $params[] = '%' . str_replace('%','', $search_ip) . '%';
}

// Count total
$stmt = query("SELECT COUNT(*) as cnt FROM captcha_failures {$where}", $params);
$total = (int)($stmt->fetch()['cnt'] ?? 0);

// Fetch offenders with filters and pagination
$params[] = $per_page;
$params[] = $offset;
$stmt = query("SELECT ip, attempts, first_failed_at, last_failed_at FROM captcha_failures {$where} ORDER BY attempts DESC, last_failed_at DESC LIMIT ? OFFSET ?", $params);
$offenders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrf = generate_csrf_token();

// If requested, export CSV
if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="captcha_offenders.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ip','attempts','first_failed_at','last_failed_at']);
    foreach ($offenders as $r) fputcsv($out, [$r['ip'],$r['attempts'],$r['first_failed_at'],$r['last_failed_at']]);
    exit;
}
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page">
    <h1>CAPTCHA Offenders</h1>
    <p class="small-muted">List of IP addresses with repeated CAPTCHA failures. Use filters, export CSV, or take action to block/unblock IPs. Actions are audited.</p>

    <?php if (!empty($msg)): ?>
        <div class="form-alert form-alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="form-alert form-alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="search-section">
        <form method="GET" class="search-form">
            <label class="small-muted">IP contains</label>
            <input type="text" name="ip" value="<?= htmlspecialchars($search_ip) ?>" />
            <label class="small-muted">Min attempts</label>
            <input type="text" name="min_attempts" value="<?= htmlspecialchars($min_attempts) ?>" />
            <label class="small-muted">Per page</label>
            <input type="text" name="per_page" value="<?= htmlspecialchars($per_page) ?>" />
            <button class="btn btn-search" type="submit">Filter</button>
            <a class="btn btn-clear" href="<?= BASE_PATH ?>/admin/captcha_offenders.php">Clear</a>
            <a class="btn" href="<?= BASE_PATH ?>/admin/captcha_offenders.php?min_attempts=<?= intval($min_attempts) ?>&per_page=<?= intval($per_page) ?>&ip=<?= urlencode($search_ip) ?>&export=csv">Export CSV</a>
        </form>
    </div>

    <div class="section">
    <div class="admin-table-wrapper">
    <table class="admin-table">
        <thead><tr><th>IP</th><th>Attempts</th><th>First Failed</th><th>Last Failed</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($offenders as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['ip']) ?></td>
                <td><?= intval($row['attempts']) ?></td>
                <td><?= htmlspecialchars($row['first_failed_at']) ?></td>
                <td><?= htmlspecialchars($row['last_failed_at']) ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($row['ip']) ?>">
                        <button class="btn btn-clear" type="submit" name="unblock_ip">Unblock</button>
                    </form>
                    <form method="POST" action="<?= BASE_PATH ?>/admin/block_ip.php" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($row['ip']) ?>">
                        <input type="hidden" name="duration" value="3600">
                        <button class="btn" type="submit" name="block_ip">Block 1h</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <div class="pager">
        <?php $pages = max(1, ceil($total / $per_page));
        for ($p = 1; $p <= $pages; $p++): ?>
            <a class="<?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>&min_attempts=<?= $min_attempts ?>&per_page=<?= $per_page ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
