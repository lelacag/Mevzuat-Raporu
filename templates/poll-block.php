<?php
// Expects $poll array with keys: id, title, options (each option has id, text, votes_count), user_vote

$total_votes = 0;
foreach ($poll['options'] as $o) { $total_votes += (int)$o['votes_count']; }
?>
<div class="post-card post-poll post-test">
    <?php
        $title = trim($poll['title'] ?? '');
        // raw post content may serve as description/question inside the box
        $post_raw = '';
        if (!empty($poll['group_post_id']) && !empty($gp) && isset($gp['id']) && (int)$gp['id'] === (int)$poll['group_post_id'] && !empty($gp['content'])) {
            $post_raw = $gp['content'];
        } elseif (!empty($poll['post_id']) && !empty($post) && isset($post['id']) && (int)$post['id'] === (int)$poll['post_id'] && !empty($post['content'])) {
            $post_raw = $post['content'];
        } elseif (!empty($post) && empty($poll['group_post_id']) && !empty($post['content'])) {
            $post_raw = $post['content'];
        } elseif (!empty($gp) && empty($poll['post_id']) && !empty($gp['content'])) {
            $post_raw = $gp['content'];
        }
        $post_text = trim(strip_tags($post_raw));
        // Determine whether to show title: if post content starts with title (we used Açıklama as title) hide it to avoid duplication
        $show_title = true;
        if ($title === '') $show_title = false;
        if ($show_title && $post_text) {
            $substr = mb_substr(trim($post_text), 0, mb_strlen($title));
            if (trim($substr) === trim($title) || trim($post_text) === trim($title)) {
                $show_title = false;
            }
        }
    ?>
    <?php $poll_slug = $poll['slug'] ?? (function_exists('generate_slug') ? (generate_slug($title) . '-' . $poll['id']) : ('anket-' . $poll['id'])); ?>
    <div class="block-header">
        <?php if ($show_title): ?>
            <div class="test-title"><strong><a href="<?= htmlspecialchars(BASE_PATH . '/anket/' . rawurlencode($poll_slug) . '/' . (int)$poll['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($title) ?></a></strong></div>
        <?php endif; ?>
        <?php if (!empty($post_raw) && trim(strip_tags($post_raw)) !== ''):
                $poll_link = BASE_PATH . '/anket/' . rawurlencode($poll['slug'] ?? (generate_slug($title) . '-' . $poll['id'])) . '/' . (int)$poll['id'];
            ?>
            <div class="poll-description">
                <a href="<?= htmlspecialchars($poll_link, ENT_QUOTES, 'UTF-8') ?>" class="poll-description-link">
                    <?= render_rich_text($post_raw) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <div class="block-buttons mt-8">
        <?php
            // show stats/edit buttons inline like tests (always visible regardless of title)
            $can_view_stats = false;
            if (!empty($current_user_id) && isset($poll['user_id']) && (int)$current_user_id === (int)$poll['user_id']) $can_view_stats = true;
            if (function_exists('is_admin') && is_admin()) $can_view_stats = true;
            if (!$can_view_stats && !empty($current_user_id)) {
                $group_id_for_stats = null;
                if (!empty($gp['group_id'])) {
                    $group_id_for_stats = $gp['group_id'];
                } elseif (!empty($post['group_id'])) {
                    $group_id_for_stats = $post['group_id'];
                }
                if ($group_id_for_stats) {
                    $pdo_local = function_exists('db_connect') ? db_connect() : null;
                    if ($pdo_local) {
                        $gm = $pdo_local->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
                        $gm->execute([$group_id_for_stats, $current_user_id]);
                        $gmr = $gm->fetch(PDO::FETCH_ASSOC);
                        if ($gmr && $gmr['role'] === 'admin') $can_view_stats = true;
                    }
                }
            }
        ?>
        <?php if ($can_view_stats): ?>
            <a href="<?= BASE_PATH ?>/anket/<?= rawurlencode($poll['slug'] ?? ('anket-' . $poll['id'])) ?>/<?= (int)$poll['id'] ?>?stats=1" class="btn-small">📊 İstatistikler</a>
        <?php endif; ?>
        <?php if (empty($suppress_poll_block_edit) && !empty($current_user_id) && isset($poll['user_id']) && (int)$current_user_id === (int)$poll['user_id']): ?>
            <a href="<?= BASE_PATH ?>/anket/duzenle/<?= (int)$poll['id'] ?>" class="btn-small">✏️ Düzenle</a>
        <?php endif; ?>
        <?php if (!empty($poll['user_vote']) && !empty($current_user_id)): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/poll_vote.php" class="action-form-inline">
                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="option_id" value="0">
                <input type="hidden" name="remove" value="1">
                <button type="submit" class="btn-small">Oyumu Geri Al</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($poll['options'])): ?>
        <?php error_log('Poll block: poll id ' . ((int)($poll['id'] ?? 0)) . ' has no options or options could not be loaded'); ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
            <div class="muted small">DEBUG: Poll id <?= (int)($poll['id'] ?? 0) ?> has no options.</div>
        <?php endif; ?>
    <?php else: ?>
        <?php $force_results = !empty($_GET['force_results']); ?>
        <?php $render_results = !empty($poll['user_vote']) || $force_results; ?>
        <?php if (!$render_results): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/poll_vote.php" class="test-form poll-form mt-8">
                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <fieldset>
                    <legend class="sr-only">Anket seçenekleri</legend>
                    <?php foreach ($poll['options'] as $opt): ?>
                        <div class="poll-option-card">
                            <label class="poll-option-label">
                                <input type="radio" name="option_id" value="<?= (int)$opt['id'] ?>"> <span><?= htmlspecialchars($opt['text']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <div class="mt-8">
                    <button type="submit" class="btn-post">Oy Ver</button>
                </div>
            </form>
        <?php else: ?>
            <div class="poll-results mt-8">
                <?php
                // Build per-option CSS classes using a nonce-protected <style> block
                // because CSP style-src blocks inline style="" attributes.
                $poll_uid = 'pb' . (int)$poll['id'];
                ?>
                <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($poll['options'] as $i => $opt):
                    $pct = $total_votes ? round(((int)$opt['votes_count']) / $total_votes * 100, 1) : 0;
                ?>
                .<?= $poll_uid ?>-fill-<?= $i ?> { width: <?= $pct ?>%; }
                <?php endforeach; ?>
                </style>
                <?php foreach ($poll['options'] as $i => $opt):
                    $pct = $total_votes ? round(((int)$opt['votes_count']) / $total_votes * 100, 1) : 0;
                    $is_voted = !empty($poll['user_vote']) && (int)$poll['user_vote'] === (int)$opt['id'];
                ?>
                    <div class="poll-result-row<?= $is_voted ? ' poll-result-row--voted' : '' ?>">
                        <div class="poll-result-header">
                            <span class="poll-result-text"><?= htmlspecialchars($opt['text']) ?><?= $is_voted ? ' <span class="poll-voted-check" aria-label="Seçiminiz">✓</span>' : '' ?></span>
                            <span class="poll-result-stats"><?= $pct ?>% · <?= (int)$opt['votes_count'] ?> oy</span>
                        </div>
                        <div class="poll-bar">
                            <div class="poll-bar-fill <?= $poll_uid ?>-fill-<?= $i ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="poll-total-votes muted small">Toplam: <?= $total_votes ?> oy</div>
                <?php if ($force_results): ?>
                    <div class="muted small">Debug: force results mode enabled.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>