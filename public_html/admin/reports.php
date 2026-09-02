<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
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

// Pre-fetch images for all reported posts
$_report_post_ids = array_filter(array_map(fn($r) => (int)$r['target_id'], $reports));
$_report_images   = (!empty($_report_post_ids) && function_exists('batch_get_images_for_posts'))
    ? batch_get_images_for_posts($_report_post_ids)
    : [];

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
        <h1 class="page-title">Raporlar</h1>

        <nav class="admin-tabs">
            <a href="<?= BASE_PATH ?>/admin/reports.php?tab=open"
               class="admin-tab <?= $tab === 'open' ? 'active' : '' ?>">
               Açık Raporlar
               <?php if ($tab === 'open'): ?>
                   <span class="admin-tab-count"><?= count($reports) ?></span>
               <?php endif; ?>
            </a>
            <a href="<?= BASE_PATH ?>/admin/reports.php?tab=deleted"
               class="admin-tab <?= $tab === 'deleted' ? 'active' : '' ?>">
               Silinen Gönderiler
            </a>
        </nav>

        <?php if ($tab === 'deleted'): ?>
            <div class="deleted-navigation">
                <strong>Arşiv:</strong>
                <?php if (empty($deleted_months)): ?>
                    <span>Henüz silinmiş gönderi yok.</span>
                <?php else: ?>
                    <?php foreach ($deleted_months as $m): ?>
                        <a href="<?= BASE_PATH ?>/admin/reports.php?tab=deleted&year=<?= $m['y'] ?>&month=<?= $m['m'] ?>"
                           class="<?= ($year == $m['y'] && $month == $m['m']) ? 'active' : '' ?>">
                            <?= $m['y'] ?>/<?= str_pad($m['m'], 2, '0', STR_PAD_LEFT) ?>
                            <span>(<?= $m['c'] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reports)): ?>
            <div class="empty-state">Henüz rapor yok.</div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table reports-table">
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Önizleme</th>
                            <th>Tip</th>
                            <th>Gönderi</th>
                            <th>Hedef Kullanıcı</th>
                            <th>Raporlayan</th>
                            <th>IP</th>
                            <th>Açıklama</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reports as $r):
                        $r_img = $_report_images[$r['target_id']] ?? null;
                    ?>
                        <tr>
                            <td class="report-time">
                                <?php if ($tab === 'deleted'): ?>
                                    <?= date('d.m.Y', strtotime($r['target_deleted_at'])) ?>
                                    <span class="report-time-sub"><?= date('H:i', strtotime($r['target_deleted_at'])) ?></span>
                                <?php else: ?>
                                    <?= date('d.m.Y', strtotime($r['created_at'])) ?>
                                    <span class="report-time-sub"><?= date('H:i', strtotime($r['created_at'])) ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="report-preview-cell">
                                <?php if ($r_img): ?>
                                    <a href="<?= BASE_PATH ?>/foto/<?= (int)$r_img['id'] ?>" target="_blank">
                                        <img src="<?= BASE_PATH ?>/photo_img.php?id=<?= (int)$r_img['id'] ?>"
                                             class="report-thumb" alt="Fotoğraf"
                                             width="52" height="52">
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($r['target_content'])): ?>
                                    <span class="report-content-excerpt"><?= htmlspecialchars(mb_strimwidth($r['target_content'], 0, 80, '…')) ?></span>
                                <?php endif; ?>
                            </td>

                            <td><span class="report-type-badge"><?= htmlspecialchars($r['target_type']) ?></span></td>

                            <td>
                                <a href="<?= BASE_PATH ?>/gonderi/<?= $r['target_id'] ?>" target="_blank">#<?= $r['target_id'] ?></a>
                            </td>

                            <td>
                                <?php if (!empty($r['target_user_id'])): $tu = get_user($r['target_user_id']); ?>
                                    <a href="<?= profile_url($tu['username']) ?>">@<?= htmlspecialchars($tu['username']) ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>

                            <td><?= htmlspecialchars($r['reporter_username'] ?? 'Anonim') ?></td>

                            <td class="report-ip"><?= htmlspecialchars($r['ip_address']) ?></td>

                            <td class="report-reason"><?= nl2br(htmlspecialchars($r['reason'] ?? '')) ?></td>

                            <td class="admin-actions">
                                <div class="flex-row">
                                <?php if ($tab !== 'deleted'): ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_post.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="post_id" value="<?= $r['target_id'] ?>">
                                        <button type="submit" class="btn btn-revoke">Gönderiyi Sil</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($r['target_user_id'])):
                                    $target_user = get_user($r['target_user_id']);
                                    if ($target_user && !empty($target_user['suspended_until']) && strtotime($target_user['suspended_until']) > time()): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_unsuspend_user.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['target_user_id'] ?>">
                                            <button type="submit" class="btn btn-warning">Askıyı Kaldır</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_suspend_user.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['target_user_id'] ?>">
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="btn btn-warning">Yazar Askı (30g)</button>
                                        </form>
                                    <?php endif;
                                endif; ?>

                                <?php if (!empty($r['reporter_id'])):
                                    $rep_user = get_user($r['reporter_id']);
                                    if ($rep_user && !empty($rep_user['suspended_until']) && strtotime($rep_user['suspended_until']) > time()): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_unsuspend_user.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['reporter_id'] ?>">
                                            <button type="submit" class="btn btn-warning">Askı Kaldır (Rplayan)</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_suspend_user.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="user_id" value="<?= $r['reporter_id'] ?>">
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="btn btn-warning">Rplayan Askı (30g)</button>
                                        </form>
                                    <?php endif;
                                endif; ?>

                                <?php if ($tab !== 'deleted'): ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_resolve_report.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-approve">Raporu Kapat</button>
                                    </form>
                                <?php endif; ?>
                                </div>
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