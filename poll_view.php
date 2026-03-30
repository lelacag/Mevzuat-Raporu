<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Anket bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Ensure DB helper is available before calling it (previously caused a fatal when header.php wasn't included yet)
require_once __DIR__ . '/includes/db.php';
if (!function_exists('db_connect')) {
    error_log('poll_view.php: db_connect() not available');
    http_response_code(500);
    echo "<div class=\"main-container\"><main class=\"content-area\">Sunucu hatası (DB bağlanılamadı).</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pdo = db_connect();
try {
    $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('poll_view.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo "<div class=\"main-container\"><main class=\"content-area\">Sunucu hatası; lütfen daha sonra tekrar deneyin.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
// Robust central logging
$log_dir = __DIR__ . '/scripts/tmp-smoketest-logs';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
$log_line = date('[Y-m-d H:i:s] ') . "poll_view request id={$id} fetched=" . ($poll ? 'yes' : 'no') . "\n";
$res = @file_put_contents($log_dir . '/php_error_log', $log_line, FILE_APPEND);
if ($res === false) {
    error_log('poll_view.php: debug log not writable: ' . $log_dir . '/php_error_log');
}
if (!$poll) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Anket bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Load options
$optStmt = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
$optStmt->execute([$poll['id']]);
$poll['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);

$META_TITLE = ($poll['title'] ?: 'Anket') . ' — ' . SITE_NAME;
$meta_source = strip_tags($poll['title'] ?? '');
$META_DESCRIPTION = function_exists('mb_substr') ? mb_substr($meta_source, 0, 160) : substr($meta_source, 0, 160);

require_once __DIR__ . '/includes/header.php';
$seo_url = BASE_PATH . '/anket/' . rawurlencode($poll['slug'] ?? (generate_slug($poll['title']) . '-' . $poll['id'])) . '/' . (int)$poll['id'];
?>
<div class="main-container">
    <main class="content-area narrow">
        <div class="card-box padded">
            <p class="muted small">SEO link: <a href="<?= htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($seo_url) ?></a></p>
            <?php require __DIR__ . '/templates/poll-block.php'; ?>

            <?php
            // Render server-side MVP statistics when requested and authorized
            if (!empty($_GET['stats'])) {
                $can_view_stats = false;
                if (!empty($current_user_id) && isset($poll['user_id']) && (int)$current_user_id === (int)$poll['user_id']) $can_view_stats = true;
                if (function_exists('is_admin') && is_admin()) $can_view_stats = true;
                // group admin check (poll attached to a group post)
                if (!$can_view_stats && !empty($poll['group_post_id']) && !empty($current_user_id)) {
                    $gp = $pdo->prepare("SELECT group_id FROM group_posts WHERE id = ? LIMIT 1");
                    $gp->execute([$poll['group_post_id']]);
                    $gprow = $gp->fetch(PDO::FETCH_ASSOC);
                    if ($gprow) {
                        $gm = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
                        $gm->execute([$gprow['group_id'], $current_user_id]);
                        $gmrow = $gm->fetch(PDO::FETCH_ASSOC);
                        if ($gmrow && $gmrow['role'] === 'admin') $can_view_stats = true;
                    }
                }

                if ($can_view_stats) {
                    $stats = get_poll_stats($poll['id']);
                    ?>
                    <div class="card-box padded" style="margin-top:12px;">
                        <h3>İstatistikler</h3>
                        <div class="muted small">Toplam oy: <strong><?= intval($stats['total_votes']) ?></strong></div>
                        <?php foreach ($stats['options'] as $opt): ?>
                            <div style="margin-top:8px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                                    <div style="flex:1;max-width:70%;"><?= htmlspecialchars($opt['text']) ?></div>
                                    <div class="muted small"><?= intval($opt['votes_count']) ?> oy · <?= htmlspecialchars(number_format((float)$opt['percent'], 1)) ?>%</div>
                                </div>
                                <div style="height:10px;background:#eee;border-radius:6px;margin-top:6px;overflow:hidden;">
                                    <div style="height:10px;background:#4f46e5;width:<?= $opt['percent'] ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!is_null($stats['response_rate'])): ?>
                            <div style="margin-top:10px;" class="muted small">Cevap oranı: <strong><?= htmlspecialchars(number_format((float)$stats['response_rate'], 1)) ?>%</strong> (<?= intval($stats['total_votes']) ?> / <?= intval($stats['group_member_count']) ?>)</div>
                        <?php endif; ?>
                    </div>
                    <?php
                } else {
                    echo '<div class="muted small" style="margin-top:12px;">Bu anketin istatistiklerini görüntüleme yetkiniz yok.</div>';
                }
            }
            ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
