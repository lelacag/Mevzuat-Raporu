<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin()) {
    header('Location: ' . BASE_PATH . '/');
    exit;
}

$rows = query("SELECT ps.*, u.username, u.email FROM premium_subscriptions ps JOIN users u ON ps.user_id = u.id ORDER BY ps.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$csrf = generate_csrf_token();
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="admin-page" style="padding-top:12px;">
    <h1 class="page-title">Premium Abonelik Reconciliation</h1>

    <div style="margin-bottom:12px;">
        <form method="POST" action="<?= BASE_PATH ?>/admin/reverify_all.php" style="display:inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button class="btn btn-save" type="submit">Re-verify Pending IAPs</button>
        </form>
        <a href="<?= BASE_PATH ?>/admin/premium_subscriptions.php" class="btn">Back to Subscriptions</a>
    </div>

    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Vendor</th>
                    <th>Vendor Tx</th>
                    <th>Vendor Payload</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= intval($r['id']) ?></td>
                        <td><a href="<?= BASE_PATH ?>/profile.php?u=<?= htmlspecialchars($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a></td>
                        <td><?= htmlspecialchars($r['plan_type']) ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                        <td><?= htmlspecialchars($r['platform'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['vendor_transaction_id'] ?? $r['vendor_purchase_token'] ?? '') ?></td>
                        <td style="max-width:420px;overflow:auto;font-size:12px;white-space:pre-wrap;background:#fff;padding:6px;border-radius:4px;"><?= htmlspecialchars((string)$r['vendor_payload']) ?></td>
                        <td>
                            <form method="POST" action="<?= BASE_PATH ?>/admin/admin_reverify.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                                <button type="submit" class="btn-compact">Reverify</button>
                            </form>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_revoke_premium.php" style="display:inline;margin-top:6px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="user_id" value="<?= intval($r['user_id']) ?>">
                                <button type="submit" class="btn-warning-compact">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>