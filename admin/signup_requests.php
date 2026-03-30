<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';

// Ensure signup-related tables exist before running queries
ensure_signup_requests_table();

include __DIR__ . '/_header.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['admin_error'] = 'Invalid CSRF'; header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit; }
    $admin_id = get_current_user_id();
    if (isset($_POST['open_country'])) {
        $country = strtoupper(substr($_POST['country'] ?? '', 0, 2));
        if (open_country($country, $admin_id, false)) $_SESSION['admin_msg'] = 'Opened country ' . $country;
        else $_SESSION['admin_error'] = 'Failed to open country';
        header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit;
    }
    if (isset($_POST['close_country'])) {
        $country = strtoupper(substr($_POST['country'] ?? '', 0, 2));
        if (close_country($country, $admin_id)) $_SESSION['admin_msg'] = 'Closed country ' . $country;
        else $_SESSION['admin_error'] = 'Failed to close country';
        header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit;
    }
    if (isset($_POST['verify_request'])) {
        $id = intval($_POST['id'] ?? 0);
        query("UPDATE signup_requests SET status = 'verified', verified_at = NOW() WHERE id = ?", [$id]);
        log_admin_action('verify_request', 'Verified signup request id=' . $id, $admin_id);
        $_SESSION['admin_msg'] = 'Request verified.';
        header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit;
    }
    if (isset($_POST['dismiss_request'])) {
        $id = intval($_POST['id'] ?? 0);
        query("UPDATE signup_requests SET status = 'dismissed' WHERE id = ?", [$id]);
        log_admin_action('dismiss_request', 'Dismissed signup request id=' . $id, $admin_id);
        $_SESSION['admin_msg'] = 'Request dismissed.';
        header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit;
    }
    if (isset($_POST['delete_request'])) {
        $id = intval($_POST['id'] ?? 0);
        query("DELETE FROM signup_requests WHERE id = ?", [$id]);
        log_admin_action('delete_request', 'Deleted signup request id=' . $id, $admin_id);
        $_SESSION['admin_msg'] = 'Request deleted.';
        header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit;
    }
}

// Filters & pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(200, intval($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $per_page;
$filter_country = strtoupper(substr(trim($_GET['country'] ?? ''),0,2));
$filter_status = $_GET['status'] ?? '';
$search_q = trim($_GET['q'] ?? '');
$export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');

// Build WHERE
$where = [];
$params = [];
if ($filter_country !== '') { $where[] = 'country_code = ?'; $params[] = $filter_country; }
if ($filter_status !== '') { $where[] = 'status = ?'; $params[] = $filter_status; }
if ($search_q !== '') { $where[] = '(email LIKE ? OR ip LIKE ?)'; $params[] = "%$search_q%"; $params[] = "%$search_q%"; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Totals
$stmt = query("SELECT COUNT(*) as cnt FROM signup_requests " . $where_sql, $params);
$total = (int)($stmt->fetch()['cnt'] ?? 0);

// Fetch rows
$params_with_limit = array_merge($params, [$per_page, $offset]);
$stmt = query("SELECT * FROM signup_requests " . $where_sql . " ORDER BY created_at DESC LIMIT ? OFFSET ?", $params_with_limit);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV export
if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="signup_requests.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','email','country','ip','status','created_at','verified_at']);
    foreach ($rows as $r) fputcsv($out, [$r['id'],$r['email'],$r['country_code'],$r['ip'],$r['status'],$r['created_at'],$r['verified_at']]);
    exit;
}

$counts = get_request_counts_by_country(200);
$csrf = generate_csrf_token();

// Confirmation flow for deletion (no-JS friendly)
if (!empty($_GET['confirm_delete'])) {
    $confirm_id = intval($_GET['confirm_delete']);
    $req = query("SELECT * FROM signup_requests WHERE id = ?", [$confirm_id])->fetch(PDO::FETCH_ASSOC);
    if (!$req) { $_SESSION['admin_error'] = 'Request not found.'; header('Location: ' . BASE_PATH . '/admin/signup_requests.php'); exit; }
    include __DIR__ . '/_nav.php';
    ?>
    <div class="admin-page">
        <h1>Onayla Silme</h1>
        <div class="form-alert form-alert-warning">Emin misiniz? Bu islem geri alinamaz. Silinecek: <strong><?= htmlspecialchars($req['email']) ?></strong></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="id" value="<?= intval($confirm_id) ?>">
            <button class="btn btn-cancel" name="delete_request">Evet, Sil</button>
            <a class="btn" href="<?= BASE_PATH ?>/admin/signup_requests.php">Iptal</a>
        </form>
    </div>
    <?php
    include __DIR__ . '/_footer.php';
    exit;
}
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page">
    <h1>Signup Requests</h1>

    <?php if (!empty($_SESSION['admin_msg'])): ?><div class="form-alert form-alert-success"><?= htmlspecialchars($_SESSION['admin_msg']) ?></div><?php unset($_SESSION['admin_msg']); endif; ?>
    <?php if (!empty($_SESSION['admin_error'])): ?><div class="form-alert form-alert-error"><?= htmlspecialchars($_SESSION['admin_error']) ?></div><?php unset($_SESSION['admin_error']); endif; ?>

    <div class="section">
        <h2>Request counts by country</h2>
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead><tr><th>Country</th><th>Verified</th><th>Pending</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($counts as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['country_code'] ?? '') ?></td>
                        <td><?= intval($c['verified_count'] ?? 0) ?></td>
                        <td><?= intval($c['pending_count'] ?? 0) ?></td>
                        <td>
                            <form method="POST" class="form-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="country" value="<?= htmlspecialchars($c['country_code']) ?>">
                                <?php if (!is_country_open($c['country_code'])): ?><button class="btn btn-save" name="open_country">Open</button><?php else: ?><button class="btn btn-clear" name="close_country">Close</button><?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2>Requests</h2>
        <form method="GET" class="search-form">
            <input type="text" name="q" placeholder="email or ip" value="<?= htmlspecialchars($search_q) ?>">
            <input type="text" name="country" placeholder="Country (TR)" value="<?= htmlspecialchars($filter_country) ?>">
            <select name="status">
                <option value="">All</option>
                <option value="pending" <?= $filter_status==='pending' ? 'selected' : '' ?>>Pending</option>
                <option value="verified" <?= $filter_status==='verified' ? 'selected' : '' ?>>Verified</option>
                <option value="dismissed" <?= $filter_status==='dismissed' ? 'selected' : '' ?>>Dismissed</option>
            </select>
            <button class="btn btn-search" type="submit">Filter</button>
            <a class="btn" href="?export=csv">Export CSV</a>
        </form>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead><tr><th>ID</th><th>Email</th><th>Country</th><th>IP</th><th>Status</th><th>Created</th><th>Verified</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= intval($r['id']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= htmlspecialchars($r['country_code']) ?></td>
                        <td><?= htmlspecialchars($r['ip']) ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['verified_at']) ?></td>
                        <td class="admin-actions">
                            <form method="POST" class="form-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                                <?php if ($r['status']!=='verified'): ?><button class="btn btn-approve" name="verify_request">Verify</button><?php endif; ?>
                                <?php if ($r['status']!=='dismissed'): ?><button class="btn btn-revoke" name="dismiss_request">Dismiss</button><?php endif; ?>
                                <a class="btn btn-cancel" href="?confirm_delete=<?= intval($r['id']) ?>">Delete</a>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pager">
            <?php $pages = max(1, ceil($total / $per_page)); for ($p=1;$p<=$pages;$p++): ?>
                <a class="<?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>&per_page=<?= $per_page ?>&country=<?= htmlspecialchars($filter_country) ?>&status=<?= htmlspecialchars($filter_status) ?>&q=<?= urlencode($search_q) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>

    </div>

</div>

<?php include __DIR__ . '/_footer.php'; ?>
