<?php
/**
 * Simplified guest landing page using the site index layout.
 * Keeps the header/search intact and replaces index-specific logged-in widgets with landing CTAs.
 */
$extra_body_classes = 'page-landing-basic';
$extra_head = "<style>
.page-landing-basic .guest-inner { align-items: center !important; }
.page-landing-basic .join-actions { margin-top: 0 !important; align-items: center !important; justify-content: center !important; gap: 12px !important; }
.page-landing-basic .join-actions-register,
.page-landing-basic .join-actions-login {
    display: inline-flex !important;
    align-items: center !important;
}
.page-landing-basic .join-actions-register .btn-post,
.page-landing-basic .join-actions-login .btn-outline {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 7px 18px !important;
    font-size: 12px !important;
    line-height: 1.2 !important;
    width: 130px !important;
    min-width: 130px !important;
    max-width: 130px !important;
    box-sizing: border-box !important;
    height: 38px !important;
    white-space: nowrap !important;
    text-decoration: none !important;
}
.page-landing-basic .join-actions-register .btn-post {
    background: #5a9a3c !important;
    color: #fff !important;
}
.page-landing-basic .join-actions-login .btn-outline {
    border: 1px solid #ddd !important;
    background: #fff !important;
    color: #666 !important;
}
</style>";
require_once __DIR__ . '/includes/landing_header.php';

$user_id = get_current_user_id();
if ($user_id) {
    header('Location: ' . home_url());
    exit;
}

$register_url = BASE_PATH . (use_clean_urls() ? '/kayit' : '/register.php');
$login_url = BASE_PATH . (use_clean_urls() ? '/giris' : '/login.php');

$after = $_GET['after'] ?? null;
$before = $_GET['before'] ?? null;
$posts_limit = 15;
$pagination = get_landing_feed_paginated($posts_limit, $after, $before);
$posts = $pagination['posts'];
$has_next = $pagination['has_next'] ?? false;
$has_prev = $pagination['has_prev'] ?? false;
$first_id = $pagination['first_id'] ?? null;
$last_id = $pagination['last_id'] ?? null;

// Ensure image attachments are hydrated for guest feed posts.
if (!empty($posts) && function_exists('batch_get_images_for_posts')) {
    $post_ids = array_column(array_filter($posts, function($item) { return isset($item['type']) && $item['type'] === 'post'; }), 'id');
    $images_map = batch_get_images_for_posts($post_ids);
    foreach ($posts as &$post) {
        if (($post['type'] ?? 'post') === 'post' && empty($post['image']) && !empty($post['image_id'])) {
            $post['image'] = $images_map[$post['id']] ?? null;
        }
    }
    unset($post);
}

$trending_tags = [];
if (function_exists('get_trending_tags')) {
    $trending_tags = get_trending_tags(10);
}
?>

<div class="main-container">
    <aside class="sidebar sidebar-left sidebar-card">
        <div class="sidebar-section mb-12">
            <div class="sidebar-section-title">Gezinti</div>
            <ul class="side-menu no-margin">
                <li><a href="<?= home_url() ?>"><span class="menu-icon icon-home" aria-hidden="true"></span>Ana Sayfa</a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar"><span class="menu-icon icon-users" aria-hidden="true"></span>Topluluklar</a></li>
                <li><a href="<?= BASE_PATH ?><?= use_clean_urls() ? '/etkinlikler' : '/events.php' ?>"><span class="menu-icon icon-calendar" aria-hidden="true"></span>Etkinlikler</a></li>
                <li><a href="<?= $login_url ?>"><span class="menu-icon icon-star" aria-hidden="true"></span>Favoriler</a></li>
            </ul>
        </div>
        <div class="sidebar-section invite">
            <div class="sidebar-section-title">Davet Et</div>
            <div class="sidebar-note padded">Arkadaşlarını davet ederek mevzuat paylaşım ağını güçlendirebilirsin.</div>
            <a href="<?= BASE_PATH ?>/davet-et" class="invite-btn">📩 Davet Et</a>
        </div>
    </aside>
    <main class="content-area">
        <div class="guest-landing" style="margin-bottom: 22px;">
            <div class="guest-inner">
                <div class="guest-copy">
                    <h2 class="guest-title">Mevzuat Raporu'na hoş geldiniz</h2>
                    <p class="guest-text">Mevzuat Raporu kişisel verilerin gizliliğine özen gösteren, sizi izlemeyen , sadece içeriğe odaklı, rahat bir sosyal medya platformudur.</p>
                </div>
                <div class="join-actions">
                    <div class="join-actions-register">
                        <a href="<?= $register_url ?>" class="btn btn-post btn-post-landing">Kayıt Ol</a>
                    </div>
                    <div class="join-actions-login">
                        <a href="<?= $login_url ?>" class="btn btn-outline btn-outline-landing">Giriş Yap</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="timeline-tabs">
            <div class="tab-links" role="tablist" aria-label="Zaman akışı sekmeleri">
                <a href="<?= BASE_PATH ?>/" class="tab-link active">Akış</a>
                <span class="tab-link muted">Kuyruk</span>
            </div>
        </div>

        <div class="posts-feed">
            <?php if (empty($posts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p>Henüz gönderi yok. İlk paylaşımları görmek için kayıt ol.</p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $item): ?>
                    <?php if (($item['type'] ?? 'post') === 'group_post') {
                        $gp = $item;
                        require __DIR__ . '/templates/group-post-card.php';
                    } else {
                        $post = $item;
                        require __DIR__ . '/templates/post-card.php';
                    } ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($has_prev || $has_next): ?>
        <div class="footer-cta">
            <div class="footer-actions">
                <?php if ($has_prev && $first_id): ?>
                    <form method="GET" class="inline-form">
                        <input type="hidden" name="before" value="<?= htmlspecialchars($first_id) ?>">
                        <button type="submit" class="btn btn-join">‹ Önceki</button>
                    </form>
                <?php endif; ?>
                <?php if ($has_next && $last_id): ?>
                    <form method="GET" class="inline-form">
                        <input type="hidden" name="after" value="<?= htmlspecialchars($last_id) ?>">
                        <button type="submit" class="btn btn-join">Sonraki ›</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <aside class="sidebar sidebar-right sidebar-card">
        <div class="sidebar-section">
            <div class="sidebar-title">Mevzuatlar</div>
            <?php if (!empty($trending_tags)): ?>
                <div class="tag-navigation" style="flex-wrap: wrap; gap: 6px;">
                    <?php foreach ($trending_tags as $tag):
                        $tag_clean = ltrim($tag['tag'], '#');
                    ?>
                        <a href="<?= BASE_PATH ?>/ara?tag=<?= urlencode($tag_clean) ?>" class="tag-nav-item" style="display:inline-flex; margin-bottom:6px;"><?= htmlspecialchars($tag_clean) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="sidebar-note">Henüz popüler etiket yok. Kaydolup ilk #etiketini paylaş.</div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
