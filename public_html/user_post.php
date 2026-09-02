<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();

// Accept either: ?username=foo&p=123 OR ?id=123 (legacy)
$username = isset($_GET['username']) ? trim(rawurldecode($_GET['username'])) : null;
$post_id = isset($_GET['p']) ? intval($_GET['p']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

if (!$post_id) {
    header('HTTP/1.1 404 Not Found');
    echo '<div class="main-container"><div class="content-wrapper"><h1 class="section-title">Gönderi Bulunamadi</h1><div class="empty-state"><p>Aradiginiz gonderi mevcut degil veya silinmis.</p></div></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$post = get_post($post_id);
if (!$post) {
    header('HTTP/1.1 404 Not Found');
    echo '<div class="main-container"><div class="content-wrapper"><h1 class="section-title">Gönderi Bulunamadi</h1><div class="empty-state"><p>Aradiginiz gonderi mevcut degil veya silinmis.</p></div></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// If a username was provided, ensure it matches the post author
if ($username) {
    $provided = mb_strtolower(trim($username), 'UTF-8');
    $postOwner = mb_strtolower(trim($post['username']), 'UTF-8');
    if ($provided !== $postOwner) {
        // If the provided parameter is a slug, resolve it to a username
        $slug_user = get_user_by_slug($username);
        if ($slug_user && mb_strtolower(trim($slug_user['username']), 'UTF-8') === $postOwner) {
            $username = $post['username'];
        } else {
            header('HTTP/1.1 404 Not Found');
            echo '<div class="main-container"><div class="content-wrapper"><h1 class="section-title">Gönderi Bulunamadi</h1><div class="empty-state"><p>Aradiginiz gonderi mevcut degil veya silinmis.</p></div></div></div>';
            require __DIR__ . '/includes/footer.php';
            exit;
        }
    }
}

// Normalize to canonical path so legacy user_post query usage redirects (friendly URL)
$slugForUser = get_user_slug($post['username']);
if (!empty($slugForUser)) {
    $canonical = BASE_PATH . '/' . rawurlencode($slugForUser) . '/' . intval($post_id);
} else {
    $canonical = get_post_url($post_id, $post['username']);
}
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($currentPath !== parse_url($canonical, PHP_URL_PATH)) {
    header('Location: ' . $canonical, true, 301);
    exit;
}

// Reuse post.php's view rendering by including the same template
$replies = get_replies($post_id, $user_id);
$total_replies = function_exists('count_replies_recursive') ? count_replies_recursive($replies) : count($replies);
$highlight_id = 0;
$hide_comment_form = false;
$errors = [];

// Count polls for this author so we can display in the sidebar (consistent with profile)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM polls WHERE user_id = ?");
    $stmt->execute([$post['user_id'] ?? $post['user_id']]);
    $polls_count = (int)($stmt->fetch()['c'] ?? 0);
} catch (Exception $e) {
    error_log('user_post polls count error: ' . $e->getMessage());
    $polls_count = 0;
}

?>
<div class="main-container">
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= home_url() ?>">← Ana Sayfa</a></li>
                <li><a href="<?= profile_url($post['username']) ?>">@<?= htmlspecialchars($post['username']) ?> Profili</a></li>
            </ul>
        </div>
    </aside>

    <main class="content-area">
        <h1 class="section-title">Gönderi</h1>

        <section class="main-post">
            <a href="<?= home_url() ?>" class="back-link">< Ana Sayfaya Dön</a>
            <?php $post_id = $post['id']; $username = $post['username']; $hide_comment_form = true; require __DIR__ . '/templates/post-card.php'; ?>
        </section>

        <?php require __DIR__ . '/templates/post-comment.php'; ?>
    </main>

    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Gönderi Bilgisi</div>
            <div style="padding:10px;font-size:11px;color:#666;">
                <p style="margin-bottom:8px;"><strong>Yazar:</strong> @<?= htmlspecialchars($post['username']) ?></p>
                <p style="margin-bottom:8px;"><strong>Tarih:</strong> <?= format_time($post['created_at']) ?></p>
                <p style="margin-bottom:8px;"><strong>Beğeni:</strong> <?= $post['likes_count'] ?? 0 ?></p>
                <p style="margin-bottom:8px;"><strong>Anket:</strong> <a href="<?= BASE_PATH ?>/search.php?view=user_polls&username=<?= rawurlencode($post['username']) ?>"><?= $polls_count ?></a></p>
                <p><strong>Yanıt:</strong> <?= (int)($total_replies ?? count($replies)) ?></p>
            </div>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/includes/footer.php';
