<?php
// Render an event comment as a feed post-card (used on main/profile feed)
// Expects $item['data'] to contain the event comment row with event_title
if (empty($item) || empty($item['data'])) return;
$cm = $item['data'];
$direct_link = BASE_PATH . '/event_view.php?id=' . (int)$cm['event_id'] . '#comment-' . (int)$cm['id'];
?>
<article class="post-card event-comment-feed">
    <div class="post-card-header">
        <div class="post-card-meta-row">
            <a href="<?= profile_url($cm['username'] ?? '') ?>" class="post-card-username">@<?= htmlspecialchars($cm['username'] ?? '') ?></a>
            <div class="post-card-time">· <?= date('d.m.Y H:i', strtotime($cm['created_at'])) ?></div>
        </div>
        <div class="post-card-meta-row">
            <a href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$cm['event_id'] ?>" class="post-card-username">📅 <?= htmlspecialchars($cm['event_title'] ?? 'Etkinlik') ?></a>
        </div>
    </div>

    <div class="post-card-content">
        <?= nl2br(linkify_text(htmlspecialchars($cm['content'] ?? '')) ) ?>
        <div style="margin-top:8px;font-size:12px;"><a href="<?= $direct_link ?>">Etkinlikte gör</a></div>
    </div>

    <div class="post-card-actions" style="margin-top:8px;">
        <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_like.php" class="action-form" style="display:inline-block;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <input type="hidden" name="event_id" value="<?= (int)$cm['event_id'] ?>">
            <input type="hidden" name="referer" value="<?= $direct_link ?>">
            <button class="action-btn like-btn">♡ <?= (int)($cm['likes_count'] ?? 0) ?> Beğen</button>
        </form>

        <!-- Yorum counter (shows reply count) -->
        <a class="action-btn" href="<?= $direct_link ?>">💬 <?= (int)($cm['replies_count'] ?? 0) ?> Yorum</a>

        <!-- (removed inline reply — replaced by post-card style textbox below) -->
        <?php if (!get_current_user_id()): ?>
            <a class="action-btn" href="<?= BASE_PATH ?>/login.php">↩️ Yanıtla</a>
        <?php endif; ?>

        <!-- Report (bildir) - moved into actions to match post-card layout -->
        <?php if (get_current_user_id() && get_current_user_id() !== (int)($cm['user_id'] ?? 0)): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_report.php" class="action-form" style="display:inline-block;margin-left:6px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$cm['event_id'] ?>">
                <input type="hidden" name="reason" value="spam">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? BASE_PATH . '/index.php') ?>">
                <button class="action-btn report-btn" type="submit">⚠️ Bildir</button>
            </form>
        <?php elseif (!get_current_user_id()): ?>
            <a class="action-btn" href="<?= BASE_PATH ?>/login.php">⚠️ Bildir</a>
        <?php endif; ?>
    </div>

    <!-- Place reply textbox under the card like ordinary post cards -->
    <?php if (get_current_user_id()): ?>
    <div class="post-card-comment-form">
        <form method="POST" action="<?= BASE_PATH ?>/api/event_comment.php" class="form-no-padding">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= (int)$cm['event_id'] ?>">
            <input type="hidden" name="parent_id" value="<?= (int)$cm['id'] ?>">
            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? $direct_link) ?>">
            <?php if (!empty($_REQUEST['sid'])): ?>
                <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid']) ?>">
            <?php endif; ?>

            <!-- same attributes and classes as post-card comment input -->
            <input type="text" name="content" placeholder="Yorum yaz..." maxlength="500" required class="post-card-comment-input">
            <button type="submit" class="sr-only">Gönder</button>
        </form>
    </div>
    <?php endif; ?>
</article>
