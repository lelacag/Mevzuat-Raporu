<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Determine last post time for caching
try {
    $lmStmt = $pdo->query("SELECT MAX(created_at) AS lm, COUNT(*) as c FROM posts WHERE parent_id IS NULL AND deleted_at IS NULL");
    $lmRow = $lmStmt->fetch(PDO::FETCH_ASSOC);
    $lastModified = $lmRow && $lmRow['lm'] ? gmdate('D, d M Y H:i:s', strtotime($lmRow['lm'])) . ' GMT' : gmdate('D, d M Y H:i:s') . ' GMT';
    $etagBase = ($lmRow['lm'] ?? '') . '|' . ($lmRow['c'] ?? '0');
    $etag = '"' . md5($etagBase) . '"';
} catch (Exception $e) {
    $lastModified = gmdate('D, d M Y H:i:s') . ' GMT';
    $etag = '"' . md5($lastModified) . '"';
}

// Send cache headers (allow intermediate caches and encourage inline rendering)
header('Content-Type: application/rss+xml; charset=utf-8');
// reduce cache TTL so readers refresh more often
header('Cache-Control: public, max-age=60');
header('Last-Modified: ' . $lastModified);
header('ETag: ' . $etag);
header('Content-Disposition: inline; filename="rss.xml"');
// X-Content-Type-Options is set globally in .htaccess.

// Honour conditional GET requests (RFC 7232)
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}
if (!isset($_SERVER['HTTP_IF_NONE_MATCH']) && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
    && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= strtotime($lastModified)) {
    http_response_code(304);
    exit;
}

$siteTitle = htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_PATH, '/');
$siteLink = $baseUrl;
$siteDesc = htmlspecialchars(SITE_NAME . ' - latest public posts', ENT_QUOTES, 'UTF-8');
$now = date(DATE_RSS);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= $siteTitle ?></title>
  <link><?= $siteLink ?></link>
  <atom:link href="<?= $siteLink ?>/rss.xml" rel="self" type="application/rss+xml" />
  <description><?= $siteDesc ?></description>
  <lastBuildDate><?= $now ?></lastBuildDate>
  <ttl>1</ttl>
<?php
try {
    $stmt = $pdo->prepare("SELECT p.id, p.content, p.created_at, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
              AND (u.is_approved = 1
              OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                     ) <= 10))
              ORDER BY p.created_at DESC LIMIT 20");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // username-prefixed title and categories for hashtags
        $excerpt = trim(strip_tags(mb_substr($row['content'], 0, 80)));
        if ($excerpt === '') $excerpt = 'Post';
        $title = $row['username'] . ': ' . $excerpt;
        $rel = get_post_url($row['id'], $row['username']);
        $schemeHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($rel, '/') === 0) {
            $link = $schemeHost . $rel;
        } else {
            $link = rtrim($baseUrl, '/') . '/' . ltrim($rel, '/');
        }
        $link = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $guid = $link;
        $pubDate = date(DATE_RSS, strtotime($row['created_at']));
        $content_html = render_rich_text($row['content']);
        preg_match_all('/#([\p{L}0-9_\-]+)/u', $row['content'], $tagMatches);
        $tags = array_map(function($t){ return mb_strtolower($t, 'UTF-8'); }, $tagMatches[1] ?? []);
        echo "  <item>\n";
        echo "    <title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n";
        echo "    <author>" . htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') . "</author>\n";
        foreach (array_unique($tags) as $tg) { echo "    <category>" . htmlspecialchars($tg, ENT_QUOTES, 'UTF-8') . "</category>\n"; }
        echo "    <link>$link</link>\n";
        echo "    <guid isPermaLink=\"true\">$guid</guid>\n";
        echo "    <pubDate>$pubDate</pubDate>\n";
        echo "    <description><![CDATA[" . $content_html . "]]></description>\n";
        echo "  </item>\n";
    }
} catch (Exception $e) {
    // Fail quietly in feed consumers — log for debugging
    error_log('rss.php error: ' . $e->getMessage());
}
?>
</channel>
</rss>
