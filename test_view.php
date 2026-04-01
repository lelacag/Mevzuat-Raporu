<?php /* EN + TR comments used. */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Test bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Ensure helper functions are available (get_test_by_id is defined in includes/functions.php)
require_once __DIR__ . '/includes/functions.php';
$test = get_test_by_id($id);
// Robust logging to central php_error_log: ensure directory exists and append a marker so we can trace requests
$log_dir = __DIR__ . '/scripts/tmp-smoketest-logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_line = date('[Y-m-d H:i:s] ') . "test_view request id={$id} fetched=" . ($test ? 'yes' : 'no') . "\n";
// Try to append to debug file without emitting warnings; fall back to error_log if not writable
$res = @file_put_contents($log_dir . '/php_error_log', $log_line, FILE_APPEND);
if ($res === false) {
    error_log('test_view.php: debug log not writable: ' . $log_dir . '/php_error_log');
}
error_log('test_view.php: requested id=' . intval($id) . ' fetched_test=' . ($test ? 'yes' : 'no'));
if (!$test) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Test bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Basic meta
$META_TITLE = ($test['title'] ?: 'Tahlil') . ' — ' . SITE_NAME;
$META_DESCRIPTION = '';
if (!empty($test['questions'][0]['question_text'])) {
    $META_DESCRIPTION = mb_substr(strip_tags($test['questions'][0]['question_text']), 0, 160);
}

try {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="main-container">
        <main class="content-area narrow">
            <div class="card-box padded">
                <article class="post-card">
                    <div class="post-card-header">
                        <div class="post-card-meta">
                            <div class="post-card-meta-row">
                                <a href="<?= profile_url($test['author_name'] ?? '') ?>" class="post-card-username"><?= htmlspecialchars($test['author_name'] ?? '') ?></a>
                                <?php if (!empty($test['author_id']) && $test['author_id'] === $current_user_id): ?>
                                    <a href="<?= BASE_PATH ?>/tahlil/duzenle/<?= (int)$test['id'] ?>" class="btn-small" style="margin-left:8px;">✏️ Düzenle</a>
                                <?php endif; ?>
                            </div>
                            <div class="post-card-time">
                                <?= format_time($test['created_at']) ?>
                                <?php if (!empty($test['updated_at']) && $test['updated_at'] !== $test['created_at']): ?>
                                    <span class="muted small" title="Düzenlendi: <?= htmlspecialchars($test['updated_at']) ?>"> · Düzenlendi: <?= format_time($test['updated_at']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="post-card-content">
                        <?php $suppress_test_block_edit = true; $test_block = $test; require __DIR__ . '/templates/test-block.php'; ?>

                        <?php
                        // Show server-rendered stats when requested (MVP). Visible to author, group admins, site admins.
                        if (!empty($_GET['stats'])) {
                            $can_view_stats = false;
                            if (!empty($current_user_id) && isset($test['author_id']) && (int)$current_user_id === (int)$test['author_id']) $can_view_stats = true;
                            if (function_exists('is_admin') && is_admin()) $can_view_stats = true;
                            // check group attachment via post_tests
                            if (!$can_view_stats) {
                                $pdo_local = function_exists('db_connect') ? db_connect() : null;
                                if ($pdo_local) {
                                    $gp = $pdo_local->prepare("SELECT group_post_id FROM post_tests WHERE test_id = ? LIMIT 1");
                                    $gp->execute([(int)$test['id']]);
                                    $gprow = $gp->fetch(PDO::FETCH_ASSOC);
                                    if ($gprow && !empty($gprow['group_post_id']) && !empty($current_user_id)) {
                                        $g = $pdo_local->prepare("SELECT group_id FROM group_posts WHERE id = ? LIMIT 1");
                                        $g->execute([$gprow['group_post_id']]);
                                        $g2 = $g->fetch(PDO::FETCH_ASSOC);
                                        if ($g2) {
                                            $gm = $pdo_local->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
                                            $gm->execute([$g2['group_id'], $current_user_id]);
                                            $gmr = $gm->fetch(PDO::FETCH_ASSOC);
                                            if ($gmr && $gmr['role'] === 'admin') $can_view_stats = true;
                                        }
                                    }
                                }
                            }

                            if ($can_view_stats) {
                                $stats = get_test_stats((int)$test['id']);
                                ?>
                                <div class="card-box padded" style="margin-top:12px;">
                                    <h3>İstatistikler</h3>
                                    <div class="muted small">Toplam katılım: <strong><?= intval($stats['total_attempts']) ?></strong>
                                        <?php if (!is_null($stats['avg_score'])): ?> · Ortalama puan: <strong><?= htmlspecialchars(number_format((float)$stats['avg_score'], 1)) ?></strong><?php endif; ?></div>

                                    <?php foreach ($stats['questions'] as $q): ?>
                                        <div style="margin-top:12px;"><strong><?= htmlspecialchars($q['question_text']) ?></strong>
                                            <?php foreach ($q['options'] as $opt): ?>
                                                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:6px;">
                                                    <div style="flex:1;max-width:70%;"><?= htmlspecialchars($opt['label']) ?></div>
                                                    <div class="muted small"><?= intval($opt['count']) ?> · <?= htmlspecialchars(number_format((float)$opt['percent'], 1)) ?>%</div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php
                            } else {
                                echo '<div class="muted small" style="margin-top:12px;">Bu tahlilin istatistiklerini görüntüleme yetkiniz yok.</div>';
                            }
                        }
                        ?>
                    </div>
                </article>
            </div>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
} catch (Throwable $e) {
    // Log full exception and show friendly message to user; show details in non-production
    error_log('test_view render error: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
    http_response_code(500);
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="main-container"><main class="content-area"><div class="card-box padded"><h1>Sunucu Hatası</h1><p>Bu Tahlil görüntülenirken beklenmedik bir hata oluştu. Lütfen daha sonra tekrar deneyin.</p>
    <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
        <pre style="white-space:pre-wrap;color:#900;">Hata: <?= htmlspecialchars($e->getMessage()) ?>

<?= htmlspecialchars($e->getTraceAsString()) ?></pre>
    <?php endif; ?></div></main></div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
