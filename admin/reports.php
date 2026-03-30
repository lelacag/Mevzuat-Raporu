<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
// Require permission to view/resolve reports
require_admin_perm('view_reports');
$csrf_token = generate_csrf_token();

$tab = $_GET['tab'] ?? 'open';
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$month = isset($_GET['month']) ? intval($_GET['month']) : null;

if ($tab === 'deleted') {
    $deleted_months = get_deleted_post_months();
    $reports = get_deleted_post_reports($year, $month, 500);
} else {
    // default: show open reports
    $reports = get_reports('open', 500);
}

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
        <h1 class="page-title">Admin - Raporlar</h1>

        <nav class="admin-tabs">
            <a href="<?= BASE_PATH ?>/admin/reports.php?tab=open" class="tab <?= $tab === 'open' ? 'active' : '' ?>">Açık Raporlar</a>
            <a href="<?= BASE_PATH ?>/admin/reports.php?tab=deleted" class="tab <?= $tab === 'deleted' ? 'active' : '' ?>">Silinen Gönderiler</a>
        </nav>

        <?php if ($tab === 'deleted'): ?>
            <div class="deleted-navigation">
                <strong>Arşiv:</strong>
                <?php if (empty($deleted_months)): ?>
                    <span>Henüz silinmiş gönderi yok.</span>
                <?php else: ?>
                    <?php foreach ($deleted_months as $m): ?>
                        <a href="<?= BASE_PATH ?>/admin/reports.php?tab=deleted&year=<?= $m['y'] ?>&month=<?= $m['m'] ?>"><?= $m['y'] ?>/<?= str_pad($m['m'], 2, '0', STR_PAD_LEFT) ?> (<?= $m['c'] ?>)</a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reports)): ?>
            <div class="empty-state">Henüz rapor yok.</div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Tip</th>
                            <th>Hedef</th>
                            <th>Hedef Kullanıcı</th>
                            <th>Raporlayan</th>
                            <th>IP</th>
                            <th>Ayrinti</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <?php if ($tab === 'deleted'): ?>
                                <td><?= date('d.m.Y H:i', strtotime($r['target_deleted_at'])) ?></td>
                            <?php else: ?>
                                <td><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                            <?php endif; ?>

                            <td><?= htmlspecialchars($r['target_type']) ?></td>
                            <td><a href="<?= BASE_PATH ?>/post.php?id=<?= $r['target_id'] ?>">#<?= $r['target_id'] ?></a></td>
                            <td>
                                <?php if (!empty($r['target_user_id'])): $tu = get_user($r['target_user_id']); ?>
                                    <a href="<?= profile_url($tu['username']) ?>">@<?= htmlspecialchars($tu['username']) ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['reporter_username'] ?? 'Anonim') ?></td>
                            <td><?= htmlspecialchars($r['ip_address']) ?></td>
                            <td><?= nl2br(htmlspecialchars($r['reason'] ?? '')) ?></td>
                            <td class="admin-actions">
                                <?php if ($tab !== 'deleted'): ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_post.php" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="post_id" value="<?= $r['target_id'] ?>">
                                        <button type="submit" class="btn">Gonderiyi Sil</button>
                                    </form>
                                <?php endif; ?>

                                <?php
                                // Suspend / Unsuspend target author (if available)
                                if (!empty($r['target_user_id'])):
                                    $target_user = get_user($r['target_user_id']);
                                    if ($target_user && !empty($target_user['suspended_until']) && strtotime($target_user['suspended_until']) > time()): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_unsuspend_user.php" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['target_user_id'] ?>">
                                            <button type="submit" class="btn btn-warning">Askıyı Kaldır</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_suspend_user.php" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['target_user_id'] ?>">
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="btn btn-warning">Yazarı Askıya Al (30g)</button>
                                        </form>
                                    <?php endif;
                                endif;

                                // Suspend / Unsuspend reporter (if exists)
                                if (!empty($r['reporter_id'])):
                                    $rep_user = get_user($r['reporter_id']);
                                    if ($rep_user && !empty($rep_user['suspended_until']) && strtotime($rep_user['suspended_until']) > time()): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_unsuspend_user.php" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['reporter_id'] ?>">
                                            <button type="submit" class="btn btn-warning">Askıyı Kaldır (Raporlayan)</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_suspend_user.php" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['reporter_id'] ?>">
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="btn btn-warning">Raporlayanı Askıya Al (30g)</button>
                                        </form>
                                    <?php endif;
                                endif;
                                ?>

                                <?php if ($tab !== 'deleted'): ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_resolve_report.php" style="display:inline; margin-left:6px;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-secondary">Raporu Kapat</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>