<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;
if (!$slug) {
    http_response_code(400);
    echo 'Missing group slug';
    exit;
}

$stmtG = query("SELECT id, slug, name FROM groups_table WHERE slug = ? LIMIT 1", [$slug]);
$group = $stmtG->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    http_response_code(404);
    echo 'Group not found';
    exit;
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_PATH, '/');

// compute last-modified + etag for group feed
try {
    $lmStmt = $pdo->prepare("SELECT MAX(created_at) AS lm, COUNT(*) AS c FROM group_posts WHERE group_id = ? AND deleted_at IS NULL");
    $lmStmt->execute([$group['id']]);
    $lmRow = $lmStmt->fetch(PDO::FETCH_ASSOC);
    $lastModifiedGroup = $lmRow && $lmRow['lm'] ? gmdate('D, d M Y H:i:s', strtotime($lmRow['lm'])) . ' GMT' : gmdate('D, d M Y H:i:s') . ' GMT';
    $etagBaseGroup = ($lmRow['lm'] ?? '') . '|' . ($lmRow['c'] ?? '0');
    $etagGroup = '"' . md5($etagBaseGroup) . '"';
} catch (Exception $e) {
    $lastModifiedGroup = gmdate('D, d M Y H:i:s') . ' GMT';
    $etagGroup = '"' . md5($lastModifiedGroup) . '"';
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Last-Modified: ' . $lastModifiedGroup);
header('ETag: ' . $etagGroup);
header('Content-Disposition: inline; filename="group-' . rawurlencode($group['slug']) . '-rss.xml"');
header('X-Content-Type-Options: nosniff');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= htmlspecialchars(SITE_NAME . ' - ' . $group['name'], ENT_QUOTES, 'UTF-8') ?></title>
  <atom:link href="<?= $baseUrl ?>/g/<?= htmlspecialchars($group['slug'], ENT_QUOTES, 'UTF-8') ?>/rss.xml" rel="self" type="application/rss+xml" />
  <link><?= $baseUrl . '/g/' . htmlspecialchars($group['slug'], ENT_QUOTES, 'UTF-8') ?></link>
  <description>Posts in group <?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?></description>
  <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
  <ttl>15</ttl>
<?php
try {
    $stmt = $pdo->prepare("SELECT gp.id, gp.content, gp.created_at, u.username FROM group_posts gp JOIN users u ON gp.user_id = u.id WHERE gp.group_id = ? AND gp.deleted_at IS NULL ORDER BY gp.created_at DESC LIMIT 50");
    $stmt->execute([$group['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rel = '/g/' . $group['slug'] . '/post/' . $row['id'];
        $schemeHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($rel, '/') === 0) {
            $link = $schemeHost . $rel;
        } else {
            $link = rtrim($baseUrl, '/') . '/' . ltrim($rel, '/');
        }
        $excerpt = trim(strip_tags(mb_substr($row['content'], 0, 80)));
        if ($excerpt === '') $excerpt = 'Group post';
        $title = $row['username'] . ': ' . $excerpt;
        $content_html = render_rich_text($row['content']);
        preg_match_all('/#([\p{L}0-9_\-]+)/u', $row['content'], $tagMatches);
        $tags = array_map(function($t){ return mb_strtolower($t, 'UTF-8'); }, $tagMatches[1] ?? []);
        ?>
  <item>
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <author><?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?></author>
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
    error_log('group_rss.php error: ' . $e->getMessage());
}
?>
</channel>
</rss>
