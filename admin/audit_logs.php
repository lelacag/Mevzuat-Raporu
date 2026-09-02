<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header('Location: ' . BASE_PATH . '/'); exit; }
require_once __DIR__ . '/../includes/functions.php';
include __DIR__ . '/_header.php';

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(20, min(200, intval($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $per_page;
$export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');

ensure_audit_table();
$stmt = query("SELECT COUNT(*) as cnt FROM audit_logs");
$total = (int)($stmt->fetch()['cnt'] ?? 0);
$stmt = query("SELECT al.*, u.username as admin_name FROM audit_logs al LEFT JOIN users u ON al.admin_id = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?", [$per_page, $offset]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['created_at','admin_id','admin_name','ip','action','details']);
    foreach ($rows as $r) fputcsv($out, [$r['created_at'],$r['admin_id'],$r['admin_name'],$r['ip'],$r['action'],$r['details']]);
    exit;
}

$csrf = generate_csrf_token();
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page">
    <h1>Admin Audit Logs</h1>
    <div class="section">
    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead><tr><th>Time</th><th>Admin</th><th>IP</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td><?= htmlspecialchars($r['admin_name'] ?? $r['admin_id']) ?></td>
                    <td><?= htmlspecialchars($r['ip']) ?></td>
                    <td><?= htmlspecialchars($r['action']) ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth($r['details'], 0, 200, '...')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>

    <div class="pager">
        <?php $pages = max(1, ceil($total / $per_page));
        for ($p = 1; $p <= $pages; $p++): ?>
            <a class="<?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>