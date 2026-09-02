<?php
/**
 * Announcement detail page — SEO-friendly with slug-date URLs and meta tags
 * URL format: /duyuru/title-slug-YYYY-MM-DD
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_GET['serve'], $_GET['name']) && $_GET['serve'] === 'image') {
    $name = basename($_GET['name']);
    if ($name === '' || preg_match('/[^A-Za-z0-9._-]/', $name)) {
        http_response_code(404);
        exit('Not found');
    }

    $root = realpath(__DIR__ . '/tmp/announcements');
    if ($root === false) {
        http_response_code(404);
        exit('Not found');
    }

    $path = $root . '/' . $name;
    $path = preg_replace('#/+#', '/', $path);
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
        }
    }
    if ($mime === null && function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
    }
    if ($mime === null || $mime === false || $mime === '') {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $mime = 'image/jpeg';
                break;
            case 'png':
                $mime = 'image/png';
                break;
            case 'gif':
                $mime = 'image/gif';
                break;
            case 'webp':
                $mime = 'image/webp';
                break;
            default:
                $mime = 'application/octet-stream';
                break;
        }
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(404);
        exit('Not found');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

$db = db_connect();
$announcement = null;

$raw_slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$announcement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($raw_slug !== '') {
    // Try to extract date suffix: slug-YYYY-MM-DD
    if (preg_match('/^(.+)-(\d{4}-\d{2}-\d{2})$/', $raw_slug, $m)) {
        $base_slug = $m[1];
        $date_part = $m[2];
        $stmt = $db->prepare("SELECT a.*, u.username FROM announcements a JOIN users u ON a.created_by = u.id WHERE a.slug = ? AND DATE(a.created_at) = ? AND a.is_active = 1 LIMIT 1");
        $stmt->execute([$base_slug, $date_part]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Fallback: try raw_slug as plain slug (backward compat)
    if (!$announcement) {
        $stmt = $db->prepare("SELECT a.*, u.username FROM announcements a JOIN users u ON a.created_by = u.id WHERE a.slug = ? AND a.is_active = 1 LIMIT 1");
        $stmt->execute([$raw_slug]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        // If found but URL doesn't match canonical slug-date, redirect
        if ($announcement && USE_CLEAN_URLS) {
            $canonical = announcement_url($announcement['slug'], $announcement['id'], $announcement['created_at']);
            $current_path = rtrim($_SERVER['REQUEST_URI'], '/');
            $canonical_path = rtrim(parse_url($canonical, PHP_URL_PATH), '/');
            if ($current_path !== $canonical_path) {
                header('Location: ' . $canonical, true, 301);
                exit;
            }
        }
    }
} elseif ($announcement_id > 0) {
    $stmt = $db->prepare("SELECT a.*, u.username FROM announcements a JOIN users u ON a.created_by = u.id WHERE a.id = ? AND a.is_active = 1");
    $stmt->execute([$announcement_id]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    // Redirect legacy ?id= URLs to canonical slug-date
    if ($announcement && !empty($announcement['slug']) && USE_CLEAN_URLS) {
        header('Location: ' . announcement_url($announcement['slug'], $announcement['id'], $announcement['created_at']), true, 301);
        exit;
    }
}

// Set meta tags BEFORE including header
if ($announcement) {
    $META_TITLE = $announcement['title'] . ' — ' . SITE_NAME;
    $META_DESCRIPTION = strip_tags($announcement['summary']);
    $META_DESCRIPTION = preg_replace('/\s+/', ' ', trim($META_DESCRIPTION));
    $CANONICAL_URL = announcement_url($announcement['slug'] ?? '', $announcement['id'], $announcement['created_at']);
}

// Fetch archive data for sidebar
$recent_5 = $db->query("SELECT id, title, slug, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$months_raw = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt FROM announcements WHERE is_active = 1 GROUP BY ym ORDER BY ym DESC")->fetchAll(PDO::FETCH_ASSOC);
$years_raw = $db->query("SELECT YEAR(created_at) AS y, COUNT(*) AS cnt FROM announcements WHERE is_active = 1 GROUP BY y ORDER BY y DESC")->fetchAll(PDO::FETCH_ASSOC);

// For month/year archive detail views
$archive_month = isset($_GET['ay']) ? trim($_GET['ay']) : '';   // e.g. 2026-03
$archive_year  = isset($_GET['yil']) ? (int)$_GET['yil'] : 0;   // e.g. 2026
$archive_items = [];
if ($archive_month && preg_match('/^\d{4}-\d{2}$/', $archive_month)) {
    $stmt = $db->prepare("SELECT id, title, slug, created_at FROM announcements WHERE is_active = 1 AND DATE_FORMAT(created_at, '%Y-%m') = ? ORDER BY created_at DESC");
    $stmt->execute([$archive_month]);
    $archive_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($archive_year > 0) {
    $stmt = $db->prepare("SELECT id, title, slug, created_at FROM announcements WHERE is_active = 1 AND YEAR(created_at) = ? ORDER BY created_at DESC");
    $stmt->execute([$archive_year]);
    $archive_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';

if (!$announcement && !$archive_month && !$archive_year) {
    ?>
    <div class="main-container">
        <main class="content-area">
            <h1 class="section-title">Duyuru Bulunamadı</h1>
            <div class="empty-state">
                <p>Aradiginiz duyuru mevcut degil.</p>
                <a href="<?= home_url() ?>" class="back-link">← Ana Sayfaya Dön</a>
            </div>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Turkish month names for display
$tr_months = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= home_url() ?>">← Ana Sayfa</a></li>
            </ul>
        </div>

        <?php if ($announcement): ?>
        <div class="sidebar-section">
            <div class="sidebar-title">Bilgi</div>
            <div class="sidebar-meta">
                <p class="meta-row"><strong>Tarih:</strong> <?= date('d.m.Y H:i', strtotime($announcement['created_at'])) ?></p>
                <p class="meta-row"><strong>Yazar:</strong> <a href="<?= profile_url($announcement['username']) ?>" class="meta-author">@<?= htmlspecialchars($announcement['username']) ?></a></p>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="content-area">
        <a href="<?= home_url() ?>" class="back-link">← Ana Sayfaya Dön</a>

        <?php if ($archive_month || $archive_year): ?>
            <!-- Archive listing view -->
            <h1 class="section-title">📢 Duyuru Arşivi — <?php
                if ($archive_month) {
                    $parts = explode('-', $archive_month);
                    echo $tr_months[(int)$parts[1]] . ' ' . $parts[0];
                } else {
                    echo $archive_year;
                }
            ?></h1>
            <?php if (empty($archive_items)): ?>
                <div class="empty-state"><p>Bu dönemde duyuru bulunamadı.</p></div>
            <?php else: ?>
                <div class="announcement-archive-list">
                    <?php foreach ($archive_items as $ai): ?>
                        <a href="<?= announcement_url($ai['slug'] ?? '', $ai['id'], $ai['created_at']) ?>" class="announcement-link">
                            <div class="announcement-box">
                                <div class="announcement-title"><?= htmlspecialchars($ai['title']) ?></div>
                                <div class="announcement-date muted"><?= date('d.m.Y', strtotime($ai['created_at'])) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($announcement): ?>
            <!-- Single announcement view -->
            <article class="announcement-article">
                <h1 class="announcement-title">📢 <?= htmlspecialchars($announcement['title']) ?></h1>

                <div class="announcement-meta">
                    <span class="announcement-published">Yayınlanan: <?= date('d.m.Y H:i', strtotime($announcement['created_at'])) ?></span>
                    <span class="announcement-author">Yazar: <a href="<?= profile_url($announcement['username']) ?>" class="meta-author">@<?= htmlspecialchars($announcement['username']) ?></a></span>
                </div>

                <div class="announcement-summary">
                    <strong>Özet:</strong>
                    <?= nl2br(linkify_mentions($announcement['summary'])) ?>
                </div>

                <div class="announcement-body">
                    <?= render_rich_text($announcement['content']) ?>
                </div>

                <?php if (!empty(trim($announcement['sources'] ?? ''))): ?>
                    <div class="announcement-sources">
                        <strong>Kaynaklar:</strong>
                        <div class="sources-body">
                            <?= render_rich_text($announcement['sources']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </main>

    <!-- Right Sidebar: Announcement Archive -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title"><span class="menu-icon icon-announce" aria-hidden="true"></span>Duyurular</div>

            <!-- 5 Recent -->
            <div class="archive-group">
                <div class="archive-group-title">Son 5 Duyuru</div>
                <?php if (empty($recent_5)): ?>
                    <p class="muted small">Henüz duyuru yok.</p>
                <?php else: ?>
                    <ul class="archive-list archive-list--stacked">
                        <?php foreach ($recent_5 as $r): ?>
                            <li<?= ($announcement && $announcement['id'] == $r['id']) ? ' class="active"' : '' ?>>
                                <a href="<?= announcement_url($r['slug'] ?? '', $r['id'], $r['created_at']) ?>">
                                    <?= htmlspecialchars($r['title']) ?>
                                </a>
                                <span class="muted small"><?= date('d.m.Y', strtotime($r['created_at'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Months -->
            <?php if (!empty($months_raw)): ?>
            <div class="archive-group">
                <div class="archive-group-title">Aylara Göre</div>
                <ul class="archive-list">
                    <?php foreach ($months_raw as $mr):
                        $parts = explode('-', $mr['ym']);
                        $label = $tr_months[(int)$parts[1]] . ' ' . $parts[0];
                    ?>
                        <li<?= ($archive_month === $mr['ym']) ? ' class="active"' : '' ?>>
                            <a href="<?= BASE_PATH ?>/announcement.php?ay=<?= $mr['ym'] ?>"><?= $label ?></a>
                            <span class="muted small">(<?= $mr['cnt'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Years -->
            <?php if (!empty($years_raw)): ?>
            <div class="archive-group">
                <div class="archive-group-title">Yıllara Göre</div>
                <ul class="archive-list">
                    <?php foreach ($years_raw as $yr): ?>
                        <li<?= ($archive_year === (int)$yr['y']) ? ' class="active"' : '' ?>>
                            <a href="<?= BASE_PATH ?>/announcement.php?yil=<?= $yr['y'] ?>"><?= $yr['y'] ?></a>
                            <span class="muted small">(<?= $yr['cnt'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>