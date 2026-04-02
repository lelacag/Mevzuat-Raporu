<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode($uri);
$path = rtrim($uri, '/');
if ($path === '') { $path = '/'; }
$staticFile = __DIR__ . $path;
if (is_file($staticFile) && $path !== '/index.php') { return false; }
$rules = [
    '/' => '/index.php',
    '/anasayfa' => '/index.php',
    '/giris' => '/login.php',
    '/kayit' => '/register.php',
    '/cikis' => '/logout.php',
    '/topluluklar' => '/groups.php',
    '/etkinlikler' => '/events.php',
    '/ara' => '/search.php',
    '/bildirimler' => '/notification.php',
    '/davet-et' => '/invite.php',
    '/admin' => '/admin/index.php',
    '/ascii' => '/landing.php',
    '/profil/duzenle' => '/profile_edit.php',
    '/profil/sifre-degistir' => '/password_change.php',
    '/sifre' => '/password_change.php',
];
if (isset($rules[$path])) { require __DIR__ . $rules[$path]; return; }

if (strpos($path, '/admin/') === 0) { $_SERVER['SCRIPT_NAME'] = '/admin/index.php'; require __DIR__ . '/admin/index.php'; return; }

if (preg_match('#^/(?:g|t)/([^/]+)/uyeler/?$#', $path, $m)) {
    $_GET['slug'] = $m[1]; require __DIR__ . '/group_members.php'; return;
}
if (preg_match('#^/(?:g|t)/([^/]+)/post/([0-9]+)/edit/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    $_GET['id'] = $m[2];
    require __DIR__ . '/group_post_edit.php'; return;
}
if (preg_match('#^/(?:g|t)/([^/]+)/post/([0-9]+)/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    $_GET['id'] = $m[2];
    require __DIR__ . '/group_post.php'; return;
}
if (preg_match('#^/(?:g|t)/([^/]+)/?$#', $path, $m)) {
    $_GET['slug'] = $m[1]; require __DIR__ . '/group.php'; return;
}

if (preg_match('#^/([^/]+)/?$#', $path, $m)) {
    $_GET['username'] = $m[1]; require __DIR__ . '/profile.php'; return;
}
require __DIR__ . '/index.php';
