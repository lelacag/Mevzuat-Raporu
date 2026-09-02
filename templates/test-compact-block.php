<?php
// Compact test card for post listing: shows title as link and small result (no full questions)
if (empty($test)) return;
$test_slug = $test['slug'] ?? (function_exists('generate_slug') ? (generate_slug($test['title']) . '-' . $test['id']) : ('tahlil-' . $test['id']));
$test_url = BASE_PATH . '/test_view.php?id=' . (int)$test['id'] . '&slug=' . rawurlencode($test_slug);
$seo_url = BASE_PATH . '/tahlil/' . rawurlencode($test_slug) . '/' . (int)$test['id'];
?>
<div class="post-test-compact">
    <div class="test-compact-title"><strong><a href="<?= htmlspecialchars($test_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($test['title']) ?></a></strong></div>
    <?php if (!empty($test['author_name'])): ?>
        <div class="muted small">Yazar: <?= htmlspecialchars($test['author_name']) ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['test_results'][$test['id']])): ?>
        <div class="test-result"><?= htmlspecialchars($_SESSION['test_results'][$test['id']]['out']) ?> — <?= (int)$_SESSION['test_results'][$test['id']]['sum'] ?> Puan</div>
    <?php endif; ?>
</div>