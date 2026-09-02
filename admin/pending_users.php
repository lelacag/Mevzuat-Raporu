<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Get current user
$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

// Require permission to approve profiles
$current_user = get_user($current_user_id);
require_admin_perm('approve_profiles');

$csrf_token = generate_csrf_token();

$page_title = 'Onay Bekleyen Kullanıcılar';

// Get all unapproved users
$stmt = query(
    "SELECT u.id, u.username, u.email, u.created_at, u.email_verified,
            COUNT(DISTINCT p.id) as post_count,
            COUNT(DISTINCT f.follower_id) as follower_count
     FROM users u
     LEFT JOIN posts p ON p.user_id = u.id
     LEFT JOIN follows f ON f.following_id = u.id
     WHERE u.is_approved = 0 
       AND u.deleted_at IS NULL
     GROUP BY u.id
     ORDER BY u.created_at DESC"
);
$pending_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
    
    <h1 class="page-title">🌱 <?= htmlspecialchars($page_title) ?></h1>
    
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="card-box padded admin-note-success">
            <?= htmlspecialchars($_SESSION['flash']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="card-box padded admin-note-error">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
    
    <?php if (empty($pending_users)): ?>
        <div class="empty-state admin-empty-state">
            <div class="admin-empty-icon">✅</div>
            <h2 class="admin-empty-title">Tüm Kullanıcılar Onaylandı</h2>
            <p class="admin-empty-desc">Şu anda onay bekleyen kullanıcı bulunmamaktadır.</p>
        </div>
    <?php else: ?>
        <p class="admin-empty-desc">
            <strong><?= count($pending_users) ?></strong> kullanıcı onay bekliyor
        </p>
        
        <?php foreach ($pending_users as $user): ?>
            <div id="user-<?= $user['id'] ?>" class="admin-user-card">
                <div class="admin-user-row">
                    <div class="admin-user-left">
                        <h3 class="admin-section-title">
                            @<?= htmlspecialchars($user['username']) ?>
                        </h3>
                        <div class="admin-row-flex">
                            <?php if ($user['email_verified']): ?>
                                <span class="badge-verified">✓ Email Doğrulandı</span>
                            <?php else: ?>
                                <span class="badge-unverified">⚠ Email Doğrulanmadı</span>
                            <?php endif; ?>
                        </div>
                        <p class="admin-desc">
                            <strong>Email:</strong> <?= htmlspecialchars($user['email']) ?>
                        </p>
                        <p class="admin-desc">
                            <strong>Kayıt Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($user['created_at'])) ?>
                        </p>
                        <div class="admin-stats">
                            <div>
                                <strong><?= $user['post_count'] ?></strong> gönderi
                            </div>
                            <div>
                                <strong><?= $user['follower_count'] ?></strong> takipçi
                            </div>
                        </div>
                    </div>
                    <div>
                        <a href="<?= profile_url($user['username']) ?>" 
                           class="btn-compact btn-primary" target="_blank">
                            Profili Görüntüle
                        </a>
                    </div>
                </div>
                <div class="admin-actions">
                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_user.php" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/pending_users.php">
                        <button type="submit" class="btn-compact btn-success">
                            ✓ Kullanıcıyı Onayla
                        </button>
                    </form>
                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_user.php" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/pending_users.php">
                        <button type="submit" class="btn-compact btn-danger-compact">
                            ✗ Reddet ve Sil
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>


<?php include __DIR__ . '/_footer.php'; ?>
