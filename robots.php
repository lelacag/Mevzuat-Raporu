<?php
// Dynamic robots.txt to include an absolute sitemap URL based on host
require_once __DIR__ . '/includes/config.php';
header('Content-Type: text/plain; charset=utf-8');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim((defined('BASE_PATH') ? BASE_PATH : '/'), '/');
$sitemap = $scheme . '://' . $host . $base . '/sitemap.xml';

// Safety: if this is not production (staging/dev), disallow indexing entirely
if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
    echo "User-agent: *\n";
    echo "Disallow: /\n"; // Block indexing on non-production environments
    echo "\n";
    echo "# Sitemap (not public in non-production): " . $sitemap . "\n";
    exit;
}

echo "User-agent: *\n";
// Disallow sensitive or private paths
echo "Disallow: /admin/\n";
echo "Disallow: /api/\n";
echo "Disallow: /includes/\n";
echo "Disallow: /migrations/\n";
echo "Disallow: /modules/\n";
echo "Disallow: /debug_\n"; // debug pages and dev helpers
echo "Disallow: /dev_\n";
echo "Disallow: /tests/\n";
// Leave the rest crawlable
echo "\n";
// Add sitemap reference
echo "Sitemap: " . $sitemap . "\n";
