<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin()) {
    header('Location: ' . BASE_PATH . '/');
    exit;
}

$rows = query("SELECT ps.*, u.username, u.email FROM premium_subscriptions ps JOIN users u ON ps.user_id = u.id ORDER BY ps.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$csrf_token = generate_csrf_token();

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
<div class="admin-page">
    <h1 class="page-title">Premium Abonelikler</h1>
    <div class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Stripe Customer</th>
                    <th>Stripe Subscription</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="border-bottom:1px solid #fafafa;">
                        <td><a href="<?= BASE_PATH ?>/profile.php?u=<?= htmlspecialchars($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a><div class="muted" style="font-size:12px;"><?= htmlspecialchars($r['email']) ?></div></td>
                        <td><?= htmlspecialchars($r['plan_type']) ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                        <td><?php if(!empty($r['stripe_customer_id'])): ?><a href="https://dashboard.stripe.com/test/customers/<?= htmlspecialchars($r['stripe_customer_id']) ?>" target="_blank"><?= htmlspecialchars($r['stripe_customer_id']) ?></a><?php endif; ?></td>
                        <td><?php if(!empty($r['stripe_subscription_id'])): ?><a href="https://dashboard.stripe.com/test/subscriptions/<?= htmlspecialchars($r['stripe_subscription_id']) ?>" target="_blank"><?= htmlspecialchars($r['stripe_subscription_id']) ?></a><?php endif; ?></td>
                        <td><?= htmlspecialchars($r['start_date']) ?></td>
                        <td><?= htmlspecialchars($r['end_date']) ?></td>
                        <td>
                            <?php if ($r['status'] !== 'active'): ?>
                                <form method="POST" action="<?= BASE_PATH ?>/admin/premium_actions.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn-compact">Activate</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="<?= BASE_PATH ?>/admin/premium_actions.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn-warning-compact">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div> <!-- .admin-table-wrapper -->
</div> <!-- .admin-page -->

<?php include __DIR__ . '/_footer.php'; ?>
<?php include __DIR__ . '/_footer.php'; ?>