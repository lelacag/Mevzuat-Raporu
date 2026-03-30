<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Sitemap caching + gzipped copy. Cache TTL in seconds.
$ttl = 3600; // 1 hour
$cacheDir = __DIR__ . '/sitemap_cache';
$cacheFile = $cacheDir . '/sitemap.xml';
$gzFile = $cacheDir . '/sitemap.xml.gz';
$indexFile = $cacheDir . '/sitemap_index.xml';
$indexGz = $cacheDir . '/sitemap_index.xml.gz';
$max_urls = 49000; // per-sitemap limit (slightly under 50k for safety)

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Determine if client accepts gzip
$accepts_gzip = (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false);

// Serve cached single sitemap or sitemap index if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl) && !file_exists($indexFile)) {
    if ($accepts_gzip && file_exists($gzFile)) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        readfile($gzFile);
        exit;
    }
    header('Content-Type: application/xml; charset=utf-8');
    readfile($cacheFile);
    exit;
}

// If index exists and is fresh, serve index
if (file_exists($indexFile) && (time() - filemtime($indexFile) < $ttl)) {
    if ($accepts_gzip && file_exists($indexGz)) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        readfile($indexGz);
        exit;
    }
    header('Content-Type: application/xml; charset=utf-8');
    readfile($indexFile);
    exit;
}

$host = (isset($_SERVER['HTTP_HOST']) ? ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] : 'http://localhost');
$urls = [];
// Static pages
$static = ['', '/landing.php', '/kurallar-sartlar', '/gizlilik', '/kvkk', '/cerezler', '/about.php'];
foreach ($static as $p) {
    $urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . $p, 'priority' => '0.5'];
}
// Add site-level feeds to sitemap
$urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/rss.php', 'priority' => '0.4'];
$urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/rss.xml', 'priority' => '0.4'];
$urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/atom.xml', 'priority' => '0.4'];
try {
    $pdo = db_connect();
    // Add recent posts (limit large number to support full sitemap)
    $stmt = $pdo->prepare("SELECT id, updated_at FROM posts WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 50000");
    $stmt->execute();
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $urls[] = ['loc' => rtrim($host, '/') . post_url($r['id']), 'lastmod' => date('c', strtotime($r['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.7'];
    }
    // Add users (limit large)
    $stmt = $pdo->prepare("SELECT username, updated_at FROM users WHERE deleted_at IS NULL AND is_active = 1 ORDER BY updated_at DESC LIMIT 50000");
    $stmt->execute();
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $urls[] = ['loc' => rtrim($host, '/') . profile_url($r['username']), 'lastmod' => date('c', strtotime($r['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.6'];
        // Add per-user feed entries (long and short form)
        $urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/user/' . rawurlencode($r['username']) . '/rss.xml', 'lastmod' => date('c', strtotime($r['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.4'];
        $urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/' . rawurlencode($r['username']) . '/rss.xml', 'lastmod' => date('c', strtotime($r['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.4'];
    }

    // Add public groups and their feeds
    try {
        $gstmt = $pdo->prepare("SELECT slug, updated_at FROM groups_table WHERE is_private = 0 ORDER BY updated_at DESC LIMIT 50000");
        $gstmt->execute();
        while ($g = $gstmt->fetch(PDO::FETCH_ASSOC)) {
            $urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/g/' . rawurlencode($g['slug']), 'lastmod' => date('c', strtotime($g['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.5'];
            $urls[] = ['loc' => rtrim($host, '/') . BASE_PATH . '/g/' . rawurlencode($g['slug']) . '/rss.xml', 'lastmod' => date('c', strtotime($g['updated_at'] ?? date('Y-m-d H:i:s'))), 'priority' => '0.4'];
        }
    } catch (Exception $eg) {
        // ignore group errors
    }
} catch (Exception $e) {
    // Ignore DB errors for sitemap generation
}

// If number of URLs exceeds max, split into parts and write a sitemap index
$total_urls = count($urls);
if ($total_urls > $max_urls) {
    // Remove any old part files
    foreach (glob($cacheDir . '/sitemap-part-*.xml') as $f) @unlink($f);
    $parts = array_chunk($urls, $max_urls);
    $part_files = [];
    foreach ($parts as $i => $part) {
        $part_xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
        $part_xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n';
        foreach ($part as $u) {
            $part_xml .= "  <url>\n";
            $part_xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";
            if (!empty($u['lastmod'])) $part_xml .= "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
            $part_xml .= "    <changefreq>weekly</changefreq>\n";
            $part_xml .= "    <priority>" . ($u['priority'] ?? '0.5') . "</priority>\n";
            $part_xml .= "  </url>\n";
        }
        $part_xml .= '</urlset>\n';
        $filename = $cacheDir . '/sitemap-part-' . ($i+1) . '.xml';
        @file_put_contents($filename, $part_xml);
        if (function_exists('gzencode')) {
            @file_put_contents($filename . '.gz', gzencode($part_xml, 6));
        }
        $part_files[] = $filename;
    }

    // Build sitemap index
    $index_xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
    $index_xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n';
    foreach ($part_files as $pf) {
        $fname = basename($pf);
        $loc = rtrim($host, '/') . BASE_PATH . '/sitemap_cache/' . $fname;
        $index_xml .= "  <sitemap>\n";
        $index_xml .= "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n";
        $index_xml .= "    <lastmod>" . date('c', filemtime($pf)) . "</lastmod>\n";
        $index_xml .= "  </sitemap>\n";
    }
    $index_xml .= '</sitemapindex>\n';

    // Write index and gz
    @file_put_contents($indexFile, $index_xml);
    if (function_exists('gzencode')) {
        @file_put_contents($indexGz, gzencode($index_xml, 6));
    }

    // Serve index (prefer gz if client accepts it)
    if ($accepts_gzip && file_exists($indexGz)) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        readfile($indexGz);
        exit;
    }
    header('Content-Type: application/xml; charset=utf-8');
    readfile($indexFile);
    exit;
}

// Build single sitemap XML
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $u) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod'])) $xml .= "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
    $xml .= "    <changefreq>weekly</changefreq>\n";
    $xml .= "    <priority>" . ($u['priority'] ?? '0.5') . "</priority>\n";
    $xml .= "  </url>\n";
}
$xml .= '</urlset>\n';

// Write cache and gz
$temp = $cacheFile . '.tmp';
@file_put_contents($temp, $xml);
@rename($temp, $cacheFile);
if (function_exists('gzencode')) {
    @file_put_contents($gzFile, gzencode($xml, 6));
}

// Serve (prefer gz if accept)
if ($accepts_gzip && file_exists($gzFile)) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    readfile($gzFile);
    exit;
}
header('Content-Type: application/xml; charset=utf-8');
echo $xml;
