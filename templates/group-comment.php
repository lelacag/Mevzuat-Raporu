<?php
// Template: group post comment area (form + comment list)
// Expects: $user_id, $is_member, $post_id, $comments, $total_comments, $csrf_token, $slug
?>

<div class="posts-feed">
    <?php if ($user_id && $is_member): ?>
        <div class="post-form-container">
            <h3 class="section-subtitle">Yorum Yap</h3>
            <form method="POST" class="post-form">
                <input type="hidden" name="action" value="create_comment">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <textarea name="content" placeholder="Yorumunuzu yazın..." required></textarea>
                <div class="post-form-actions">
                    <span class="char-count">500+ karakter</span>
                    <button type="submit" class="btn-post">Yorum Yap</button>
                </div>
            </form>
        </div>
    <?php elseif (!$user_id): ?>
        <div class="notice notice-info text-center"><p class="muted">Yorum yapmak için <a href="<?= BASE_PATH ?>/login.php" class="link-strong">giriş yapın</a>.</p></div>
    <?php elseif (!$is_member): ?>
        <div class="notice notice-info text-center"><p class="muted">Yorum yapmak için <a href="<?= BASE_PATH ?>/groups_join.php?slug=<?= urlencode($slug) ?>" class="link-strong">gruba katılın</a>.</p></div>
    <?php endif; ?>

    <h2 class="section-subtitle">Yorumlar (<?= (int)$total_comments ?>)</h2>
    <?php if (empty($comments)): ?>
        <div class="empty-state"><p>Henüz yorum yok. İlk yorumu sen yap!</p></div>
    <?php else: ?>
        <div class="padded">
            <?php foreach ($comments as $comment): $cm = $comment; require __DIR__ . '/group-comment-card.php'; endforeach; ?>
        </div>
    <?php endif; ?>
</div>