<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Atom 1.0 feed (latest 20 posts)
try {
    $lmStmt = $pdo->query("SELECT MAX(created_at) AS lm FROM posts WHERE parent_id IS NULL AND deleted_at IS NULL");
    $lmRow = $lmStmt->fetch(PDO::FETCH_ASSOC);
    $updated = $lmRow['lm'] ?? date('c');
} catch (Exception $e) {
    $updated = date('c');
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_PATH, '/');
header('Content-Type: application/atom+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');

echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <link href="<?= $baseUrl ?>" />
  <link href="<?= $baseUrl ?>/atom.xml" rel="self" />
  <updated><?= htmlspecialchars(date(DATE_ATOM, strtotime($updated)), ENT_QUOTES, 'UTF-8') ?></updated>
  <id><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></id>
<?php
try {
    $stmt = $pdo->prepare("SELECT p.id, p.content, p.created_at, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
              AND (u.is_approved = 1
              OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                     ) <= 10)))
              ORDER BY p.created_at DESC LIMIT 20");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rel = get_post_url($row['id'], $row['username']);
        $schemeHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($rel, '/') === 0) {
            $link = $schemeHost . $rel;
        } else {
            $link = rtrim($baseUrl, '/') . '/' . ltrim($rel, '/');
        }
        $id = $link;
        $updated_item = date(DATE_ATOM, strtotime($row['created_at']));
        $excerpt = trim(strip_tags(mb_substr($row['content'], 0, 80)));
        if ($excerpt === '') $excerpt = 'Post';
        $title = $row['username'] . ': ' . $excerpt;
        $content_html = render_rich_text($row['content']);
        preg_match_all('/#([\p{L}0-9_\-]+)/u', $row['content'], $m);
        $cats = array_unique(array_map(function($t){ return mb_strtolower($t,'UTF-8'); }, $m[1] ?? []));
        ?>
  <entry>
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" />
    <id><?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?></id>
    <updated><?= htmlspecialchars($updated_item, ENT_QUOTES, 'UTF-8') ?></updated>
    <author><name><?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?></name></author>
    <?php foreach ($cats as $c): ?>
    <category term="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" />
    <?php endforeach; ?>
    <content type="html"><![CDATA[<?= $content_html ?>]]></content>
  </entry>
<?php
    }
} catch (Exception $e) {
    error_log('atom.php error: ' . $e->getMessage());
}
?>
</feed>
