<?php
// Template: post comment area (form + replies)
// Expects in-scope: $user_id, $post_id, $replies, $highlight_id, $errors, $csrf_token
?>

<!-- Reply Form + Replies (extracted) -->
<?php if ($user_id): ?>
    <div class="post-form-container">
        <h3 class="section-subtitle">Yanıt Ver</h3>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="post-form">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="create_reply">
            <input type="hidden" name="parent_id" value="<?= (int)$post_id ?>">
            <?php if (!empty($_REQUEST['sid'])): ?><input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid']) ?>"><?php endif; ?>
            <textarea name="content" placeholder="Yanıtınızı yazın..." required></textarea>
            <div class="post-form-actions">
                <span class="char-count">500+ karakter (otomatik bölünür)</span>
                <button type="submit" class="action-btn reply-btn">Yanıtla</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="notice notice-info">
        <p class="muted">Yanıt vermek için <a href="<?= BASE_PATH ?>/giris" class="link-strong">giriş yapın</a>.</p>
    </div>
<?php endif; ?>

<div class="posts-feed">
    <h2 class="replies-header">Yanıtlar (<?= (int)($total_replies ?? count($replies)) ?>)</h2>

    <?php if (empty($replies)): ?>
        <div class="empty-state"><p>Henüz yanıt yok. İlk yanıtı sen ver!</p></div>
    <?php else: ?>
        <?php
        // reply-card.php mutates $post; preserve the main post for the rest of the page.
        $__main_post = $post ?? null;
        foreach ($replies as $reply):
            $post = $reply;
            require __DIR__ . '/reply-card.php';
        endforeach;
        if ($__main_post !== null) {
            $post = $__main_post;
        }
        unset($__main_post);
        ?>
    <?php endif; ?>
</div>
