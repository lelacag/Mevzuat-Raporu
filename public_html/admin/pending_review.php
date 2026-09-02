<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
// Require content moderation permission
require_admin_perm('moderate_content');

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';

$pending_posts = get_pending_posts();
$threshold = get_similarity_threshold();
?>

    <div class="admin-page">
    <h1 class="page-title">🔍 Şüpheli İçerik İncelemesi</h1>
    
    <div class="alert alert-warning">
        <strong>ℹ️ Benzerlik Eşiği:</strong> <?= $threshold ?>%<br>
        <small class="muted">Sistem, yasaklı kelimelere %<?= $threshold ?> veya daha fazla benzeyen kelimeleri otomatik olarak işaretler.</small>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div style="background:#d4edda;border:1px solid #c3e6cb;padding:12px;margin-bottom:20px;border-radius:3px;color:#155724;font-size:13px;">
            <?= htmlspecialchars($_SESSION['flash']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if (empty($pending_posts)): ?>
        <div class="empty-state boxed-empty">
            <div class="empty-icon">✅</div>
            <h2 class="empty-title text-success">Harika!</h2>
            <p class="muted">İncelenmesi gereken şüpheli içerik yok.</p>
        </div>
    <?php else: ?> 
        <p style="margin-bottom:20px;color:#666;font-size:13px;">
            <strong><?= count($pending_posts) ?></strong> gönderi incelemenizi bekliyor.
        </p>

        <?php foreach ($pending_posts as $post): ?>
            <?php 
            // Re-check suspicious content to show details
            $suspicious = check_suspicious_content($post['content']);
            ?>
            <div class="review-panel">
                <div class="review-header">
                    <div>
                        <strong class="review-author">@<?= htmlspecialchars($post['username']) ?></strong>
                        <span class="review-time muted"><?= date('d M Y H:i', strtotime($post['created_at'])) ?></span>
                    </div>
                    <span class="status-pill">İNCELEME BEKLİYOR</span>
                </div>

                <div class="panel-muted"> 
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>

                <?php if (!empty($suspicious['matched_words'])): ?>
                    <div style="margin-bottom:15px;">
                        <strong style="color:#dc3545;margin-bottom:8px;display:block;font-size:12px;">⚠️ Tespit Edilen Şüpheli Kelimeler:</strong>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <?php foreach ($suspicious['matched_words'] as $match): ?>
                                <div class="matched-tag">
                                    <strong><?= htmlspecialchars($match['found_word']) ?></strong>
                                    <?php if (!empty($match['variant_used'])): ?>
                                        <span class="muted">→ <?= htmlspecialchars($match['variant_used']) ?></span>
                                    <?php endif; ?>
                                    <span class="muted">→</span>
                                    <span class="bad-word"><?= htmlspecialchars($match['bad_word']) ?></span>
                                    <span class="tag-meta">%<?= $match['similarity'] ?></span>
                                    <?php if ($match['match_type'] === 'contains'): ?>
                                        <span class="tag-meta note">İÇERİYOR</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="action-row">
                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_review.php" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <?php foreach ($suspicious['matched_words'] as $match): ?>
                            <input type="hidden" name="words[]" value="<?= htmlspecialchars($match['found_word']) ?>">
                        <?php endforeach; ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success">
                            ✅ Onayla & Beyaz Listeye Ekle
                        </button>
                    </form>

                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_review.php" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <button type="submit" name="action" value="approve_only" class="btn btn-info">
                            ✓ Sadece Onayla
                        </button>
                    </form>

                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_post.php" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <button type="submit" class="btn btn-danger-compact">
                            🗑️ Sil
                        </button>
                    </form>

                    <a href="<?= BASE_PATH ?>/post.php?id=<?= $post['id'] ?>" target="_blank" class="btn btn-secondary">
                        👁️ Görüntüle
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
