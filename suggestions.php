<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// Show up to 50 suggestions (precomputed would be better at scale)
$suggestions = get_friend_suggestions($user_id, 50);

?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/users.css">

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">📋 Önerilen kullanıcılar</h1>
        <div class="section-sub">Senin için seçilen kullanıcılar. Takip etmek istediğine basitçe "kuyruk" deyin.</div>

        <?php if (empty($suggestions)): ?>
            <div class="card" style="padding:16px;color:#666;">Henüz öneri yok</div>
        <?php else: ?>
            <div class="active-users-grid suggestions-grid">
                <?php foreach ($suggestions as $s): ?>
                    <?php $su = get_user($s['id']); // small user fetch for bio/premium ?>
                    <div class="user-card">
                        <div class="user-info" style="width:100%;">
                            <?php if (!empty($s['is_online'])): ?>
                                <div class="user-online"><div class="online-dot"></div><div class="online-text">Çevrimiçi</div></div>
                            <?php endif; ?>

                            <a class="username" href="<?= profile_url($s['username']) ?>">@<?= htmlspecialchars($s['username']) ?></a>
                            <?php if (!empty($su['is_premium'])): ?><span class="premium-star">⭐</span><?php endif; ?>
                            <div class="bio"><?= htmlspecialchars(mb_strimwidth($su['bio'] ?? $s['reason'], 0, 120, '...')) ?></div>
                        </div>

                        <div class="actions">
                            <form method="POST" action="<?= BASE_PATH ?>/api/follow.php" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="following_id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                <button class="follow-btn-compact" type="submit">kuyruk</button>
                            </form>
                        </div>

                        <div class="last-activity">Son aktif: <?= $s['last_activity'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($s['last_activity']))) : 'bilinmiyor' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
