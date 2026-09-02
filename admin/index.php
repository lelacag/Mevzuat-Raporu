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
// Require permission to view admin dashboard
require_admin_perm('view_admin_dashboard');

// Ensure audit log table exists (may be created lazily by log_admin_action)
if (function_exists('ensure_audit_table')) {
    ensure_audit_table();
}

// Counts
$stmt = query("SELECT COUNT(*) AS c FROM users WHERE role = 'member' AND deleted_at IS NULL");
$members_count = $stmt->fetch()['c'];

$stmt = query("SELECT COUNT(*) AS c FROM users WHERE is_approved = 0 AND deleted_at IS NULL");
$pending_approval_count = $stmt->fetch()['c'];

$first_of_month = date('Y-m-01 00:00:00');
$stmt = query("SELECT COUNT(*) AS c FROM users WHERE created_at >= ? AND deleted_at IS NULL", [$first_of_month]);
$new_this_month = $stmt->fetch()['c'];

// Total posts
$stmt = query("SELECT COUNT(*) AS c FROM posts WHERE deleted_at IS NULL");
$total_posts = $stmt->fetch()['c'];

// Total mentions (notifications with type = 'mention')
$stmt = query("SELECT COUNT(*) AS c FROM notifications WHERE type = 'mention'");
$total_mentions = $stmt->fetch()['c'];

// Total deleted posts
$stmt = query("SELECT COUNT(*) AS c FROM posts WHERE deleted_at IS NOT NULL");
$total_deleted = $stmt->fetch()['c'];

// Total censored posts
$stmt = query("SELECT COUNT(*) AS c FROM posts WHERE has_censored_words = 1 AND deleted_at IS NULL");
$total_censored = $stmt->fetch()['c'];

// Dashboard section selector
$section = $_GET['section'] ?? '';
$show_maintenance = ($section === 'maintenance');

// Maintenance mode toggle (admin dashboard control)
$maintenance_flag = __DIR__ . '/../tmp/MAINTENANCE';
$maintenance_mode = file_exists($maintenance_flag);
$maintenance_msg = '';
$tpu_msg = '';
$tpu_count = null;
$tpu_limit = null;

$csrf_token = generate_csrf_token();

// If module exists in modules/, require it (unless functions already defined).
if (function_exists('tpu_limit_admin_status')) {
    $tpu_status = tpu_limit_admin_status();
    $tpu_count = $tpu_status['count'];
    $tpu_limit = $tpu_status['limit'];
    $tpu_storage = $tpu_status['storage'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!empty($_POST['maintenance_action'])) {
        if ($_POST['maintenance_action'] === 'enable') {
            @file_put_contents($maintenance_flag, 'enabled by admin');
            $maintenance_msg = 'Bakım modu etkinleştirildi.';
        } elseif ($_POST['maintenance_action'] === 'disable') {
            @unlink($maintenance_flag);
            $maintenance_msg = 'Bakım modu devre dışı bırakıldı.';
        }
        $maintenance_mode = file_exists($maintenance_flag);
    }

    if (function_exists('tpu_limit_admin_status')) {
        $tpu_status = tpu_limit_admin_status();
        $tpu_count = $tpu_status['count'];
        $tpu_limit = $tpu_status['limit'];
        $tpu_storage = $tpu_status['storage'] ?? null;

        $result = tpu_limit_admin_handle_post($_POST);
        $tpu_msg = $result['msg'];
        $tpu_status = $result['status'];
        $tpu_count = $tpu_status['count'];
        $tpu_limit = $tpu_status['limit'];
        $tpu_storage = $tpu_status['storage'] ?? null;
        $maintenance_mode = file_exists($maintenance_flag);
    }
}


// Daily visitor counts
$today = date('Y-m-d');
$stmt = query("SELECT visits FROM daily_visitors WHERE visit_date = ?", [$today]);
$today_visits = $stmt->fetch()['visits'] ?? 0;

// Dashboard time series (last 30 days)
$start_date = date('Y-m-d', strtotime('-29 days'));
$labels = [];
$days_map = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d M', strtotime($d));
    $days_map[$d] = 0;
}

// Users per day
$stmt = query("SELECT DATE(created_at) as d, COUNT(*) as c FROM users WHERE created_at >= ? AND deleted_at IS NULL GROUP BY d", [$start_date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($days_map[$r['d']])) $days_map[$r['d']] = (int)$r['c'];
}
$users_series = array_values($days_map);

// Posts per day
$days_map = array_fill_keys(array_keys($days_map), 0);
$stmt = query("SELECT DATE(created_at) as d, COUNT(*) as c FROM posts WHERE created_at >= ? AND deleted_at IS NULL GROUP BY d", [$start_date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($days_map[$r['d']])) $days_map[$r['d']] = (int)$r['c'];
}
$posts_series = array_values($days_map);

// Daily visitors per day
$days_map = array_fill_keys(array_keys($days_map), 0);
$stmt = query("SELECT visit_date AS d, visits AS c FROM daily_visitors WHERE visit_date >= ? ORDER BY visit_date ASC", [$start_date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($days_map[$r['d']])) $days_map[$r['d']] = (int)$r['c'];
}
$visits_series = array_values($days_map);

// Censored posts per day
$days_map = array_fill_keys(array_keys($days_map), 0);
$stmt = query("SELECT DATE(created_at) as d, COUNT(*) as c FROM posts WHERE created_at >= ? AND has_censored_words = 1 AND deleted_at IS NULL GROUP BY d", [$start_date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($days_map[$r['d']])) $days_map[$r['d']] = (int)$r['c'];
}
$censor_series = array_values($days_map);

// Security counts
$stmt = query("SELECT COUNT(*) as cnt FROM blocked_ips");
$blocked_ips_count = $stmt->fetch()['cnt'] ?? 0;

$stmt = query("SELECT COUNT(*) as cnt FROM captcha_failures WHERE last_failed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$recent_captcha_failures = $stmt->fetch()['cnt'] ?? 0;

// Recent audit logs
$stmt = query("SELECT al.*, u.username as admin_name FROM audit_logs al LEFT JOIN users u ON al.admin_id = u.id ORDER BY created_at DESC LIMIT 6");
$recent_audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
include __DIR__ . '/_nav.php';
?>
    <h1 class="page-title"><?= t('admin_panel_title') ?></h1>

    <?php if (!empty($maintenance_msg) && $show_maintenance): ?>
        <div class="form-alert form-alert-success"><?= htmlspecialchars($maintenance_msg) ?></div>
    <?php endif; ?>

    <?php if ($show_maintenance): ?>
        <div class="card">
            <h3>Bakım Modu</h3>
            <p>Siteyi bakım moduna alıp çıkarmak için aşağıdaki butonu kullanabilirsiniz.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button class="btn" type="submit" name="maintenance_action" value="<?= $maintenance_mode ? 'disable' : 'enable' ?>">
                    <?= $maintenance_mode ? 'Bakımı Kapat' : 'Bakımı Aç' ?>
                </button>
            </form>

            <?php if ($tpu_limit !== null): ?>
                <hr>
                <h4>TPU İstek Sınırı</h4>
                <p>TPU üzerinden gelen sayfa istek sayısı: <strong><?= htmlspecialchars(intval($tpu_count)) ?></strong> / <strong><?= htmlspecialchars(intval($tpu_limit)) ?></strong></p>
                <p class="muted">Cap'a ulaşıldığında site genel bakım moduna alınır.</p>

                <?php if ($tpu_limit > 0 && $tpu_count >= intval($tpu_limit * 0.9)): ?>
                    <div class="form-alert form-alert-warning">TPU istek sayısı sınırın %90'ına ulaştı. Kullanımı sınırlandırın.</div>
                <?php endif; ?>

                <?php if (!empty($tpu_storage)): ?>
                    <p class="muted small">TPU sayaç dosyası: <code><?= htmlspecialchars($tpu_storage['active']) ?></code> (yazılabilir: <?= $tpu_storage['writable'] ? 'evet' : 'hayır' ?>)</p>
                <?php endif; ?>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 12px; align-items: end;">
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button class="btn" type="submit" name="tpu_action" value="reset">TPU Sayaç Sıfırla</button>
                    </form>

                    <form method="POST" style="margin:0; display:flex; gap:8px; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <label for="tpu_value" class="muted" style="white-space:nowrap;">Sayaç değeri:</label>
                        <input id="tpu_value" name="tpu_value" type="number" min="0" value="<?= htmlspecialchars(intval($tpu_count)) ?>" style="width:120px;" />
                        <button class="btn" type="submit" name="tpu_action" value="set">Ayarla</button>
                    </form>
                </div>

                <?php if (!empty($tpu_msg)): ?>
                    <div class="form-alert form-alert-success"><?= htmlspecialchars($tpu_msg) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="admin-grid">
        <!-- Compact KPI row -->
        <div class="kpi-row">
            <div class="kpi">
                <div class="kpi-label"><?= t('admin_total_members') ?></div>
                <div class="kpi-value"><?= htmlspecialchars($members_count) ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">🌱 Onay Bekleyen</div>
                <div class="kpi-value"><?= htmlspecialchars($pending_approval_count) ?></div>
                <?php if ($pending_approval_count > 0): ?><div class="kpi-link"><a href="<?= BASE_PATH ?>/admin/pending_users.php">Onayla →</a></div><?php endif; ?>
            </div>
            <div class="kpi">
                <div class="kpi-label"><?= t('admin_new_this_month') ?></div>
                <div class="kpi-value"><?= htmlspecialchars($new_this_month) ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label"><?= t('admin_total_posts') ?></div>
                <div class="kpi-value"><?= htmlspecialchars($total_posts) ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label"><?= t('admin_daily_visitors') ?></div>
                <div class="kpi-value"><?= htmlspecialchars($today_visits) ?></div>
            </div>
        </div>
        <div class="card">
            <h3><?= t('admin_total_deleted') ?></h3>
            <p class="big"><?= htmlspecialchars($total_deleted) ?></p>
        </div>

        <div class="card">
            <h3><?= t('admin_total_censored') ?></h3>
            <p class="big"><?= htmlspecialchars($total_censored) ?></p>
        </div>
    </div> 

        <div class="charts-grid">
            <div class="chart-card">
                <h4>Yeni Kayıtlar (Son 30 Gün)</h4>
                <?php echo render_sparkline_svg($users_series, 420, 80, '#5a9a3c'); ?>
            </div>
            <div class="chart-card">
                <h4>Gönderiler (Son 30 Gün)</h4>
                <?php echo render_sparkline_svg($posts_series, 420, 80, '#3498db'); ?>
            </div>
            <div class="chart-card">
                <h4><?= t('admin_daily_visitors') ?> (Son 30 Gün)</h4>
                <?php echo render_sparkline_svg($visits_series, 420, 80, '#8e44ad'); ?>
            </div>
            <div class="chart-card wide">
                <h4>Sansürlü Gönderiler(Son 30 Gün)</h4>
                <?php echo render_sparkline_svg($censor_series, 840, 120, '#e74c3c'); ?>
            </div>


            <div class="chart-card small">
                <h4>Güvenlik</h4>
                <p class="muted mb-6"><strong><?= intval($blocked_ips_count) ?></strong> Engellenen IP</p>
                <p class="muted small mb-6"><strong><?= intval($recent_captcha_failures) ?></strong> CAPTCHA (1 saat)</p>
                <a href="<?= BASE_PATH ?>/admin/captcha_offenders.php" class="btn-link">CAPTCHA Paneli →</a>
            </div>
        </div>

        <div class="section full-width">
            <h2>📝 Son Yönetici İşlemleri</h2>
            <?php if (empty($recent_audit_logs)): ?>
                <div class="muted padded">Henüz işlem yok</div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Tarih</th><th>Admin</th><th>Aksiyon</th><th>Detay</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_audit_logs as $r): ?>
                                <tr>
                                    <td><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($r['admin_name'] ?? $r['admin_id']) ?></td>
                                    <td><?= htmlspecialchars($r['action']) ?></td>
                                    <td><?= htmlspecialchars($r['details']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="text-right mt-5"><a href="<?= BASE_PATH ?>/admin/audit_logs.php" class="btn">Tümü →</a></div>
        </div>

        <nav class="admin-links">
            <a href="<?= BASE_PATH ?>/admin/users.php" class="btn"><?= t('admin_users_btn') ?></a>
            <a href="<?= BASE_PATH ?>/admin/reports.php" class="btn"><?= t('admin_reports_btn') ?></a>
            <a href="<?= BASE_PATH ?>/admin/badges.php" class="btn"><?= t('admin_badges_btn') ?></a>
        </nav>
    </div>

<?php include __DIR__ . '/_footer.php'; ?>