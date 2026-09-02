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

// Check if the current user has voted (must run after header.php sets $current_user_id)
$poll['user_vote'] = null;
if (!empty($current_user_id)) {
    $vStmt = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
    $vStmt->execute([$poll['id'], $current_user_id]);
    $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($vRow) $poll['user_vote'] = (int)$vRow['option_id'];
}

$seo_url = BASE_PATH . '/anket/' . rawurlencode($poll['slug'] ?? (generate_slug($poll['title']) . '-' . $poll['id'])) . '/' . (int)$poll['id'];
$show_stats = !empty($_GET['stats']);
?>
<div class="main-container">
    <main id="content" class="content-area narrow" role="main">
        <div class="card-box padded">
            <?php if (!$show_stats): ?>
                <p class="muted small">SEO link: <a href="<?= htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($seo_url) ?></a></p>
                <?php require __DIR__ . '/templates/poll-block.php'; ?>
            <?php endif; ?>

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
                    $winner = null;
                    $runner_up = null;
                    $decision_strength = 'Henüz oy yok';
                    if (!empty($stats['options'])) {
                        $sorted = $stats['options'];
                        usort($sorted, function($a, $b) {
                            if ($a['votes_count'] !== $b['votes_count']) {
                                return $b['votes_count'] <=> $a['votes_count'];
                            }
                            return $b['percent'] <=> $a['percent'];
                        });
                        $winner = $sorted[0];
                        $runner_up = $sorted[1] ?? null;
                        if ($stats['total_votes'] > 0) {
                            if ($winner['percent'] >= 70) {
                                $decision_strength = 'Kesin';
                            } elseif ($winner['percent'] >= 55) {
                                $decision_strength = 'Orta';
                            } elseif ($winner['percent'] >= 45) {
                                $decision_strength = 'Yakın';
                            } else {
                                $decision_strength = 'Çok yakın';
                            }
                            if ($winner['percent'] < 50) {
                                $decision_strength = 'Dağıtılmış';
                            }
                        }
                    }
                    ?>
                    <div class="poll-stats-panel card-box padded">
                        <div class="poll-stats-header">
                            <div>
                                <h3 class="poll-stats-title">Anket İstatistikleri</h3>
                                <div class="muted small">Toplam oy: <strong><?= intval($stats['total_votes']) ?></strong></div>
                                <div class="muted small">Cevap oranı: <strong>
                                    <?= !is_null($stats['response_rate']) ? htmlspecialchars(number_format((float)$stats['response_rate'], 1)) . '%</strong> (' . intval($stats['total_votes']) . ' / ' . intval($stats['group_member_count']) . ')' : 'Hesaplanamıyor</strong>' ?>
                                </div>
                                <div class="muted small">Karar gücü: <strong><?= htmlspecialchars($decision_strength) ?></strong></div>
                                <?php if ($winner): ?>
                                    <div class="muted small poll-stats-leader">Önde gidiyor: <strong><?= htmlspecialchars($winner['text']) ?></strong> (%<?= htmlspecialchars(number_format((float)$winner['percent'], 1)) ?>)</div>
                                <?php endif; ?>
                            </div>
                            <div class="poll-stats-header-right">
                                <a href="<?= htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-small">Ankete Dön</a>
                            </div>
                        </div>
                        <div class="poll-stats-summary-card">
                                <div class="poll-stats-summary-title">Özet</div>
                                <div class="muted small">Toplam seçenek: <strong><?= count($stats['options']) ?></strong></div>
                                <?php if (!empty($stats['group_member_count'])): ?>
                                    <div class="muted small">Grup üyesi: <strong><?= intval($stats['group_member_count']) ?></strong></div>
                                <?php else: ?>
                                    <div class="muted small">Yetkili üyeler: <strong>bilinmiyor</strong></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="poll-stats-distribution">
                            <div class="poll-stats-row poll-stats-row-title">Oy dağılımı</div>
                            <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
                            <?php foreach ($stats['options'] as $i => $opt): ?>
                            .poll-stats-fill-<?= (int)$i ?> { width: <?= min(100, max(0, (float)$opt['percent'])) ?>%; }
                            <?php endforeach; ?>
                            </style>
                            <?php foreach ($stats['options'] as $i => $opt): ?>
                                <div class="poll-stats-row">
                                    <div class="poll-stats-row-head">
                                        <div class="poll-stats-text"><?= htmlspecialchars($opt['text']) ?></div>
                                        <div class="muted small poll-stats-row-meta"><?= intval($opt['votes_count']) ?> oy · <?= htmlspecialchars(number_format((float)$opt['percent'], 1)) ?>%</div>
                                    </div>
                                    <div class="poll-stats-progress">
                                        <div class="poll-stats-progress-fill poll-stats-fill-<?= (int)$i ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="poll-stats-participation">
                            <div class="poll-stats-block-title">Katılım</div>
                            <div class="muted small">Oy veren: <strong><?= intval($stats['total_votes']) ?></strong></div>
                            <?php if (!is_null($stats['group_member_count'])): ?>
                                <div class="muted small">Grup üyesi: <strong><?= intval($stats['group_member_count']) ?></strong></div>
                            <?php endif; ?>
                            <?php if (!is_null($stats['response_rate'])): ?>
                                <div class="muted small">Cevap oranı: <strong><?= htmlspecialchars(number_format((float)$stats['response_rate'], 1)) ?>%</strong></div>
                            <?php endif; ?>
                        </div>

                        <?php if ($winner): ?>
                            <div class="poll-stats-insight">
                                <div class="poll-stats-block-title">Öğrenim</div>
                                <div class="muted small">En yüksek oyu alan seçenek: <strong><?= htmlspecialchars($winner['text']) ?></strong>. <?= $winner['percent'] >= 50 ? 'Bu sonuç, anketin şu anda net bir favoriye sahip olduğunu gösteriyor.' : 'Bu sonuç, oyların dağıldığını gösteriyor.' ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                } else {
                    echo '<div class="poll-stats-no-access">';
                    echo '<div class="muted small">Bu anketin istatistiklerini görüntüleme yetkiniz yok.</div>';
                    echo '<div class="mt-8"><a href="' . htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') . '" class="btn-small">Ankete Dön</a></div>';
                    echo '</div>';
                }
            }
            ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
