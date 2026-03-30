<?php /* EN + TR comments used. */

require_once __DIR__ . '/includes/header.php';

$current_user_id = get_current_user_id();
$profile_user = null;

// Get username from URL
$username = $_GET['username'] ?? '';

// If no username, use current user
if (empty($username)) {
    if ($current_user_id) {
        $profile_user = get_user($current_user_id);
    } else {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
} else {
    $profile_user = get_user_by_username($username);
}

if (!$profile_user) {
    echo '<h1>Kullanici Bulunamadi</h1>';
    echo '<p>Aradiginiz kullanici mevcut degil veya silinmis.</p>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$profile_user_id = $profile_user['id'];
$is_own_profile = ($current_user_id == $profile_user_id);

// Get followers list
$followers = get_followers($profile_user_id);
$followers_count = count($followers);
$following_count = get_following_count($profile_user_id);
$posts_count = count(get_user_posts($profile_user_id));

// Count polls for profile header stats
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM polls WHERE user_id = ?");
    $stmt->execute([$profile_user_id]);
    $polls_count = (int)($stmt->fetch()['c'] ?? 0);
} catch (Exception $e) {
    error_log('followers page polls count error: ' . $e->getMessage());
    $polls_count = 0;
}
?>

<div class="main-container">
    <aside class="sidebar">
        <!-- Left Sidebar for future use -->
    </aside>
    
    <main class="content-area small">
        <!-- Back to Profile -->
        <a href="<?= profile_url($profile_user['username']) ?>" class="back-link">&lt; @<?= htmlspecialchars($profile_user['username']) ?> profiline don</a>
        
        <!-- Profile Header -->
        <section class="profile-header">
            <div class="profile-info">
                <h1 class="profile-username">@<?= htmlspecialchars($profile_user['username']) ?></h1>
                
                <?php if (!empty($profile_user['bio'])): ?>
                    <p class="profile-bio"><?= nl2br(htmlspecialchars($profile_user['bio'])) ?></p>
                <?php endif; ?>
                
                <p class="profile-joined">
                    Katilim: <?= date('d.m.Y', strtotime($profile_user['created_at'])) ?>
                </p>
                
                <div class="profile-stats">
                    <a href="<?= BASE_PATH ?>/search.php?view=user_posts&username=<?= rawurlencode($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $posts_count ?></span>
                        <span class="stat-label">Gonderi</span>
                    </a>
                    <a href="<?= BASE_PATH ?>/search.php?view=user_polls&username=<?= rawurlencode($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $polls_count ?></span>
                        <span class="stat-label">Anket</span>
                    </a>
                    <a href="<?= followers_url($profile_user['username']) ?>" class="stat active">
                        <span class="stat-value"><?= $followers_count ?></span>
                        <span class="stat-label">Takipci</span>
                    </a>
                    <a href="<?= following_url($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $following_count ?></span>
                        <span class="stat-label">Takip</span>
                    </a>
                </div>
            </div>
        </section>
        
        <!-- Followers List -->
        <section class="users-list-section">
            <h2 class="section-title"><?= $is_own_profile ? 'Kimler Kuyrukta' : '@' . htmlspecialchars($profile_user['username']) . ' Kimler Kuyrukta' ?></h2>
            
            <?php if (empty($followers)): ?>
                <div class="empty-state">
                    <p><?= $is_own_profile ? 'Henuz hic takipcin yok.' : 'Bu kullanicinin henuz takipcisi yok.' ?></p>
                </div>
            <?php else: ?>
                <ul class="users-list">
                    <?php foreach ($followers as $follower): ?>
                        <li class="user-item">
                            <div class="user-info">
                                <a href="<?= profile_url($follower['username']) ?>" class="user-username">
                                    @<?= htmlspecialchars($follower['username']) ?>
                                </a>
                                <?php if (!empty($follower['bio'])): ?>
                                    <p class="user-bio"><?= nl2br(htmlspecialchars($follower['bio'])) ?></p>
                                <?php endif; ?>
                                <p class="user-joined">
                                    Katilim: <?= date('d.m.Y', strtotime($follower['created_at'])) ?>
                                </p>
                            </div>
                            <div class="user-actions">                                <?php if ($current_user_id && $current_user_id != $follower['id']): ?>
                                    <?php $follower_is_following = is_following($current_user_id, $follower['id']); ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/follow.php" class="form-inline">
                                        <input type="hidden" name="following_id" value="<?= $follower['id'] ?>">
                                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                        <button type="submit" class="follow-btn-compact <?= $follower_is_following ? 'following' : '' ?>">
                                            <?= $follower_is_following ? 'kuyruğu bırak' : 'kuyruk' ?>
                                        </button>
                                    </form>
                                <?php elseif (!$current_user_id): ?>
                                    <a href="<?= BASE_PATH ?>/login.php" class="follow-btn-compact">kuyruk</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
    
    <aside class="sidebar-right">
        <!-- Right Sidebar for future use -->
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

