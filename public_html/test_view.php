<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Tahlil bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions_legacy.php';

$test = get_test_by_id($id);
if (!$test) {
    http_response_code(404);
    echo "<div class=\"main-container\"><main class=\"content-area\">Tahlil bulunamadı.</main></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$META_TITLE = ($test['title'] ?: 'Tahlil') . ' — ' . SITE_NAME;
$meta_source = strip_tags($test['title'] ?? '');
$META_DESCRIPTION = function_exists('mb_substr') ? mb_substr($meta_source, 0, 160) : substr($meta_source, 0, 160);

require_once __DIR__ . '/includes/header.php';

// Provide a preview-friendly SEO URL
$seo_slug = $test['slug'] ?? (function_exists('generate_slug') ? generate_slug($test['title']) . '-' . $test['id'] : 'tahlil-' . $test['id']);
$seo_url = BASE_PATH . '/tahlil/' . rawurlencode($seo_slug) . '/' . (int)$test['id'];

// Determine whether stats are viewable by current user
$can_view_stats = false;
if (!empty($current_user_id) && isset($test['user_id']) && (int)$current_user_id === (int)$test['user_id']) {
    $can_view_stats = true;
}
if (!$can_view_stats && function_exists('is_admin') && is_admin()) {
    $can_view_stats = true;
}

$stats = null;
$show_stats = !empty($_GET['stats']) && $can_view_stats;
if ($show_stats) {
    $stats = get_test_stats($test['id']);
}
?>
<div class="main-container">
    <main id="content" class="content-area narrow" role="main">
        <div class="card-box padded">
            <p class="muted small">SEO link: <a href="<?= htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($seo_url) ?></a></p>
            <?php if (!$show_stats): ?>
                <?php require __DIR__ . '/templates/test-block.php'; ?>
            <?php endif; ?>

            <?php if ($stats !== null): ?>
                <div class="tahlil-stats-container">
                    <div class="tahlil-stats-header">
                        <div>
                            <h3 class="tahlil-stats-title">Tahlil İstatistikleri</h3>
                            <div class="muted small">Bu sayfada yalnızca bu tahlile ait özet ve dağılım gösterilir.</div>
                        </div>
                        <div class="tahlil-stats-header-right">
                            <a href="<?= htmlspecialchars($seo_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-small">Tahlile Dön</a>
                        </div>
                    </div>

                    <div class="tahlil-stats-summary">
                        <div class="tahlil-stats-summary-grid">
                            <div class="tahlil-stats-card">
                                <div class="muted small">Toplam katılım</div>
                                <div class="tahlil-stats-card-value"><?= intval($stats['total_attempts']) ?></div>
                            </div>
                            <div class="tahlil-stats-card">
                                <div class="muted small">Soru sayısı</div>
                                <div class="tahlil-stats-card-value"><?= count($stats['questions']) ?></div>
                            </div>
                            <div class="tahlil-stats-card">
                                <div class="muted small">Ortalama puan</div>
                                <div class="tahlil-stats-card-value"><?= $stats['avg_score'] !== null ? htmlspecialchars(number_format($stats['avg_score'], 1)) : '—' ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="tahlil-questions-list">
                        <?php foreach ($stats['questions'] as $question): ?>
                            <div class="tahlil-question-card">
                                <div class="tahlil-question-title"><?= htmlspecialchars($question['question_text']) ?></div>
                                <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach ($question['options'] as $option): ?>
                                .tahlil-fill-q<?= (int)$question['id'] ?>-o<?= (int)$option['id'] ?> { width: <?= min(100, max(0, (float)$option['percent'])) ?>%; }
                                <?php endforeach; ?>
                                </style>
                                <?php foreach ($question['options'] as $option): ?>
                                    <div class="tahlil-option-row">
                                        <div class="tahlil-option-row-head">
                                            <div class="tahlil-option-label"><?= htmlspecialchars($option['label']) ?></div>
                                            <div class="muted small"><?= intval($option['count']) ?> cevap · <?= htmlspecialchars(number_format((float)$option['percent'], 1)) ?>%</div>
                                        </div>
                                        <div class="tahlil-option-progress">
                                            <div class="tahlil-option-progress-fill tahlil-fill-q<?= (int)$question['id'] ?>-o<?= (int)$option['id'] ?>"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($can_view_stats): ?>
                <div class="muted small tahlil-stats-cta">İstatistikleri görüntülemek için <a href="<?= htmlspecialchars($seo_url . '?stats=1', ENT_QUOTES, 'UTF-8') ?>">buraya tıklayın</a>.</div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
