<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$username = isset($_GET['username']) ? trim($_GET['username']) : null;
if (!$username) {
    http_response_code(400);
    echo 'Missing username';
    exit;
}

// ensure user exists and is not deleted
// Allow per-user RSS even for users who are not yet 'approved' (public timeline excludes them).
$stmtU = query("SELECT id, username FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1", [$username]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    echo 'User not found';
    exit;
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_PATH, '/');

// compute last-modified + etag for this user's feed
try {
    $lmStmt = $pdo->prepare("SELECT MAX(created_at) AS lm, COUNT(*) AS c FROM posts WHERE parent_id IS NULL AND deleted_at IS NULL AND user_id = ?");
    $lmStmt->execute([$user['id']]);
    $lmRow = $lmStmt->fetch(PDO::FETCH_ASSOC);
    $lastModifiedUser = $lmRow && $lmRow['lm'] ? gmdate('D, d M Y H:i:s', strtotime($lmRow['lm'])) . ' GMT' : gmdate('D, d M Y H:i:s') . ' GMT';
    $etagBaseUser = ($lmRow['lm'] ?? '') . '|' . ($lmRow['c'] ?? '0');
    $etagUser = '"' . md5($etagBaseUser) . '"';
} catch (Exception $e) {
    $lastModifiedUser = gmdate('D, d M Y H:i:s') . ' GMT';
    $etagUser = '"' . md5($lastModifiedUser) . '"';
}

header('Content-Type: application/rss+xml; charset=utf-8');
// shorter cache so user feeds update quickly
header('Cache-Control: public, max-age=60');
header('Last-Modified: ' . $lastModifiedUser);
header('ETag: ' . $etagUser);
header('Content-Disposition: inline; filename="user-' . rawurlencode($user['username']) . '-rss.xml"');
// X-Content-Type-Options is set globally in .htaccess.

// Honour conditional GET requests (RFC 7232)
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etagUser) {
    http_response_code(304);
    exit;
}
if (!isset($_SERVER['HTTP_IF_NONE_MATCH']) && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
    && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= strtotime($lastModifiedUser)) {
    http_response_code(304);
    exit;
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= htmlspecialchars(SITE_NAME . ' - ' . $user['username'], ENT_QUOTES, 'UTF-8') ?></title>
  <atom:link href="<?= $baseUrl ?>/user/<?= rawurlencode($user['username']) ?>/rss.xml" rel="self" type="application/rss+xml" />
  <link><?= $baseUrl . '/profile.php?username=' . rawurlencode($user['username']) ?></link>
  <description>Posts by <?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></description>
  <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
  <ttl>1</ttl>
<?php
try {
    $stmt = $pdo->prepare("SELECT p.id, p.content, p.created_at FROM posts p WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND p.user_id = ? ORDER BY p.created_at DESC LIMIT 50");
    $stmt->execute([$user['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rel = get_post_url($row['id'], $user['username']);
        $schemeHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($rel, '/') === 0) {
            $link = $schemeHost . $rel;
        } else {
            $link = rtrim($baseUrl, '/') . '/' . ltrim($rel, '/');
        }
        // build title prefixed with username
        $excerpt = trim(strip_tags(mb_substr($row['content'], 0, 80)));
        if ($excerpt === '') $excerpt = 'Post';
        $title = $user['username'] . ': ' . $excerpt;
        $content_html = render_rich_text($row['content']);
        // extract hashtags for <category> elements
        preg_match_all('/#([\p{L}0-9_\-]+)/u', $row['content'], $tagMatches);
        $tags = array_map(function($t){ return mb_strtolower($t, 'UTF-8'); }, $tagMatches[1] ?? []);
        ?>
  <item>
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <author><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></author>
    <?php foreach (array_unique($tags) as $tg): ?>
    <category><?= htmlspecialchars($tg, ENT_QUOTES, 'UTF-8') ?></category>
    <?php endforeach; ?>
    <link><?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?></link>
    <guid isPermaLink="true"><?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?></guid>
    <pubDate><?= date(DATE_RSS, strtotime($row['created_at'])) ?></pubDate>
    <description><![CDATA[<?= $content_html ?>]]></description>
  </item>
<?php
    }
} catch (Exception $e) {
    error_log('user_rss.php error: ' . $e->getMessage());
}
?>
</channel>
</rss>
