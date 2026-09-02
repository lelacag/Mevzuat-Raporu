<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/giris");
    exit;
}

$post_id = $_GET['id'] ?? null;
if (!$post_id) {
    header("Location: " . home_url());
    exit;
}

// Get post
$stmt = query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ? AND p.deleted_at IS NULL", [$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header("Location: " . home_url());
    exit;
}

// Check if user can delete (owner or admin)
$current_user = get_user($user_id);
$can_delete = ($post['user_id'] == $user_id) || ($current_user && admin_has_perm(null, 'moderate_content'));

// If current user is a rookie, NOT premium, and rookie self-delete is disabled, intercept and show the Premium CTA then return to the post
if ($current_user && isset($current_user['role']) && $current_user['role'] === 'rookie' && !is_user_premium($user_id) && !ROOKIE_ALLOW_SELF_DELETE) {
    error_log("delete blocked: rookie user={$user_id} post={$post_id} is_premium=" . (is_user_premium($user_id) ? '1' : '0'));
    $_SESSION['flash'] = sprintf(t('rookie_restricted_delete'), BASE_PATH . '/premium.php');
    header('Location: ' . post_url($post_id));
    exit;
}

if (!$can_delete) {
    error_log("delete blocked: user={$user_id} cannot delete post={$post_id} owner={$post['user_id']} current_role=" . ($current_user['role'] ?? ''));
    $_SESSION['flash_error'] = 'Bu gönderiyi silme yetkiniz yok';
    header('Location: ' . post_url($post_id));
    exit;
}

$is_reply = !empty($post['parent_id']);
$has_child_replies = function_exists('post_has_active_child_replies')
    ? post_has_active_child_replies($post_id)
    : ((int)query("SELECT COUNT(*) FROM posts WHERE parent_id = ? AND deleted_at IS NULL", [$post_id])->fetchColumn() > 0);
$return_id = isset($_GET['return_id']) ? (int)$_GET['return_id'] : (int)($post['parent_id'] ?? $post_id);
?>

<style>
body { background: #f5f5f5; }
.header-container + * { max-width: 100%; }
</style>

<div class="confirm-box">
    <div class="confirm-panel">
        <h1 class="danger-title">🗑️ Gönderiyi Sil</h1>
            
            <div class="alert alert-error">
                <p class="text-danger note">
                    ⚠️ Dikkat: Bu işlem geri alınamaz!
                </p>
            </div>
            
            <div class="panel-muted">
                <div class="panel-meta">
                    <strong class="meta-strong"><?= $is_reply ? 'Yanıt' : 'Gönderi' ?> #<?= $post['id'] ?></strong>
                    <span class="meta-muted">
                        @<?= htmlspecialchars($post['username']) ?> - <?= format_time($post['created_at']) ?>
                    </span>
                </div>
                <div class="panel-content">
                    <?= nl2br(htmlspecialchars(substr($post['content'], 0, 200))) ?>
                    <?php if (strlen($post['content']) > 200): ?>
                        <span class="muted">...</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_reply && $has_child_replies): ?>
                <p class="muted mb-20">
                    Bu yanıtın altında başka yanıtlar var. İçerik silinmiş olarak işaretlenecek; alt yanıtlar görünmeye devam edecek.
                </p>
            <?php elseif ($is_reply): ?>
                <p class="muted mb-20">
                    Bu yanıtı silmek istediğinizden emin misiniz? Yanıt silinmiş olarak işaretlenecektir.
                </p>
            <?php else: ?>
                <p class="muted mb-20">
                    Bu gönderiyi silmek istediğinizden emin misiniz? Gönderi silinmiş olarak işaretlenecektir.
                </p>
            <?php endif; ?>
            
            <?php
                $fallback = $return_id ? post_url($return_id) : (BASE_PATH . '/index.php');
                $back = validate_referer($_SERVER['HTTP_REFERER'] ?? $fallback, $fallback, false);
            ?>
            <div class="flex-row justify-end">
                <a href="<?= htmlspecialchars($back) ?>" class="btn btn-cancel">İptal</a>
                <form method="POST" action="<?= BASE_PATH ?>/api/delete_post.php" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="post_id" value="<?= $post_id ?>">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($back) ?>">
                    <button type="submit" class="btn btn-danger">🗑️ Evet, Sil</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
