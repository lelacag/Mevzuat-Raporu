<?php
/**
 * Test Block Template
 * Expects $test array from get_test_by_id() with keys: id, title, questions (with options), thresholds
 */
if (empty($test)) return;
?>
<div class="post-test">
    <?php error_log('templates/test-block: rendering test id=' . intval($test['id']) . ' title=' . substr($test['title'],0,50)); ?>
    <?php
        // Build SEO-friendly URL if possible
        $test_slug = $test['slug'] ?? (function_exists('generate_slug') ? (generate_slug($test['title']) . '-' . $test['id']) : ('tahlil-' . $test['id']));
        $test_url = BASE_PATH . '/tahlil/' . rawurlencode($test_slug) . '/' . (int)$test['id'];
    ?>
    <div class="block-header">
        <div class="test-title"><strong><a href="<?= htmlspecialchars($test_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($test['title']) ?></a></strong></div>
        <div class="block-buttons">
        <?php
            $can_view_test_stats = false;
            if (!empty($current_user_id) && isset($test['user_id']) && (int)$current_user_id === (int)$test['user_id']) $can_view_test_stats = true;
            if (function_exists('is_admin') && is_admin()) $can_view_test_stats = true;
            if (!$can_view_test_stats && !empty($post['group_id']) && !empty($current_user_id)) {
                $pdo_local = function_exists('db_connect') ? db_connect() : null;
                if ($pdo_local) {
                    $gm = $pdo_local->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
                    $gm->execute([$post['group_id'], $current_user_id]);
                    $gmr = $gm->fetch(PDO::FETCH_ASSOC);
                    if ($gmr && $gmr['role'] === 'admin') $can_view_test_stats = true;
                }
            }
        ?>
        <?php if ($can_view_test_stats): ?>
            <a href="<?= $test_url ?>?stats=1" class="btn-small">📊 İstatistikler</a>
        <?php endif; ?>
        <?php if (empty($suppress_test_block_edit) && !empty($current_user_id) && isset($test['user_id']) && (int)$current_user_id === (int)$test['user_id']): ?>
            <a href="<?= BASE_PATH ?>/tahlil/duzenle/<?= (int)$test['id'] ?>" class="btn-small">✏️ Düzenle</a>
        <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($_SESSION['test_results'][$test['id']])): ?>
        <div class="test-result">
            <?= htmlspecialchars($_SESSION['test_results'][$test['id']]['out']) ?> — Toplam Puan: <?= (int)$_SESSION['test_results'][$test['id']]['sum'] ?>
        </div>
    <?php endif; ?>
    <?php
        $created_raw = $test['created_at'] ?? $test['created'] ?? null;
        $updated_raw = $test['updated_at'] ?? $test['modified_at'] ?? null;
        if ($created_raw) {
            $created_display = function_exists('format_time') ? format_time($created_raw) : date('Y-m-d H:i', is_numeric($created_raw) ? (int)$created_raw : strtotime($created_raw));
        } else {
            $created_display = '';
        }
        if ($updated_raw) {
            $updated_display = function_exists('format_time') ? format_time($updated_raw) : date('Y-m-d H:i', is_numeric($updated_raw) ? (int)$updated_raw : strtotime($updated_raw));
        } else {
            $updated_display = '';
        }
    ?>
    <div class="muted small">
        <span class="test-sent">Gönderildi: <?= htmlspecialchars($created_display) ?></span>
        <?php
            $created_ts = $created_raw ? @strtotime($created_raw) : 0;
            $updated_ts = $updated_raw ? @strtotime($updated_raw) : 0;
            if ($updated_display && $created_raw && $updated_ts && $created_ts && ($updated_ts - $created_ts) > 2): ?> · <span class="test-edited">Düzenlendi: <?= htmlspecialchars($updated_display) ?></span><?php endif; ?>
    </div>

    <form method="POST" action="<?= BASE_PATH ?>/api/test_take.php" class="test-form">
        <input type="hidden" name="test_id" value="<?= (int)$test['id'] ?>">
        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
        <?php foreach ($test['questions'] as $idx => $q): ?>
            <div class="test-question">
                <?php if (count($test['questions']) > 1): ?>
                    <div class="test-question-number" style="font-weight:bold;margin-bottom:4px;"><?= ($idx + 1) ?>.</div>
                <?php endif; ?>
                <div class="test-question-text"><?= htmlspecialchars($q['question_text']) ?></div>
                <?php foreach ($q['options'] as $opt): ?>
                    <div class="test-option">
                        <label>
                            <input type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= (int)$opt['id'] ?>"> <?= htmlspecialchars($opt['label']) ?> <span class="muted">(<?= (int)$opt['points'] ?> Puan)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div style="margin-top:8px;">
            <button type="submit" name="take_test" class="btn-post">Tahlil Et</button>
        </div>
    </form>
</div>
