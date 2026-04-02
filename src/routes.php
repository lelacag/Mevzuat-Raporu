<?php
/**
 * Application route definitions.
 *
 * This file maps ALL clean URLs to their PHP handlers,
 * replacing the Apache .htaccess rewrite rules with PHP routing.
 *
 * @var \App\Router $router
 */

// ══════════════════════════════════════════════════════════════
// 301 REDIRECTS — legacy .php URLs → clean Turkish URLs
// ══════════════════════════════════════════════════════════════
$router->redirect('/login.php', '/giris');
$router->redirect('/register.php', '/kayit');
$router->redirect('/logout.php', '/cikis');
$router->redirect('/privacy.php', '/gizlilik');
$router->redirect('/kvkk.php', '/kvkk');
$router->redirect('/cookie-policy.php', '/cerezler');
$router->redirect('/kurallar', '/kurallar-sartlar');
$router->redirect('/profil/password_change.php', '/profil/sifre-degistir');
$router->redirect('/sifirla', '/sifremi-unuttum');
$router->redirect('/forgot_password.php', '/sifremi-unuttum');

// ══════════════════════════════════════════════════════════════
// STATIC ROUTES — exact path → PHP file
// ══════════════════════════════════════════════════════════════
$router->map('/', 'index.php');
$router->map('/anasayfa', 'index.php');
$router->map('/giris', 'login.php');
$router->map('/kayit', 'register.php');
$router->map('/cikis', 'logout.php');
$router->map('/topluluklar', 'groups.php');
$router->map('/etkinlikler', 'events.php');
$router->map('/ara', 'search.php');
$router->map('/kurallar-sartlar', 'rules.php');
$router->map('/gizlilik', 'privacy.php');
$router->map('/kvkk', 'kvkk.php');
$router->map('/cerezler', 'cookie-policy.php');
$router->map('/premium', 'premium.php');
$router->map('/davet-et', 'invite.php');
$router->map('/sifremi-unuttum', 'forgot_password.php');
$router->map('/profil/duzenle', 'profile_edit.php');
$router->map('/profil/sifre-degistir', 'password_change.php');
$router->map('/sifre', 'password_change.php');
$router->map('/tahlil/olustur', 'test_advanced_poll.php');

// RSS / Atom / Robots / Sitemap
$router->map('/rss.xml', 'rss.php');
$router->map('/atom.xml', 'atom.php');
$router->map('/robots.txt', 'robots.php');
$router->map('/sitemap.xml', 'sitemap.php');
$router->map('/health', 'health.php');

// ══════════════════════════════════════════════════════════════
// PATTERN ROUTES — regex path → PHP file + $_GET params
// ══════════════════════════════════════════════════════════════

// Registration with invite token: /kayit/{token}
$router->pattern('#^/kayit/([^/]+)$#', 'register.php', [1 => 'invite']);

// Password reset: /sifremi-unuttum/{64-char-hex-token}
$router->pattern('#^/sifremi-unuttum/([A-Fa-f0-9]{64})$#', 'forgot_password.php', [1 => 'token']);

// Notifications with sub-path: /bildirimler, /bildirimler/mention, etc.
$router->pattern('#^/bildirimler(?:/.*)?$#', 'notification.php', []);

// Announcements: /duyuru/{slug}
$router->pattern('#^/duyuru/([a-zA-Z0-9_-]+)/?$#', 'announcement.php', [1 => 'slug']);

// Posts: /post/{id}, /post/{id}/edit, /post/{id}/karsilastirma[/...]
$router->pattern('#^/post/([0-9]+)/karsilastirma/history$#', 'post.php', [1 => 'id']);
$router->pattern('#^/post/([0-9]+)/karsilastirma/([0-9]+)$#', 'post.php', [1 => 'id', 2 => 'compare']);
$router->pattern('#^/post/([0-9]+)/karsilastirma(?:/latest)?$#', 'post.php', [1 => 'id']);
$router->pattern('#^/post/([0-9]+)/edit$#', 'edit_post.php', [1 => 'id']);
$router->pattern('#^/post/([0-9]+)$#', 'post.php', [1 => 'id']);
$router->pattern('#^/p/([0-9]+)/?$#', 'post.php', [1 => 'id']);

// Reply: /reply/{post_id}/{parent_id}
$router->pattern('#^/reply/([0-9]+)/([0-9]+)/?$#', 'reply.php', [1 => 'post_id', 2 => 'parent_id']);

// Groups: /g/{slug} and /t/{slug} (legacy alias)
$router->pattern('#^/(?:g|t)/([^/]+)/uyeler/?$#', 'group_members.php', [1 => 'slug']);
$router->pattern('#^/(?:g|t)/([^/]+)/post/([0-9]+)/edit/?$#', 'group_post_edit.php', [1 => 'slug', 2 => 'id']);
$router->pattern('#^/(?:g|t)/([^/]+)/post/([0-9]+)/?$#', 'group_post.php', [1 => 'slug', 2 => 'id']);
$router->pattern('#^/(?:g|t)/([^/]+)/rss\.xml$#', 'group_rss.php', [1 => 'slug']);
$router->pattern('#^/(?:g|t)/([^/]+)/?$#', 'group.php', [1 => 'slug']);

// Polls: /anket/{slug}/{id}
$router->pattern('#^/anket/([a-zA-Z0-9_-]+)/([0-9]+)/?$#', 'poll_view.php', [1 => 'slug', 2 => 'id']);

// Tests/Tahlil: /tahlil/duzenle/{id}, /tahlil/{slug}/{id}
$router->pattern('#^/tahlil/duzenle/([0-9]+)/?$#', 'test_advanced_poll.php', [1 => 'edit']);
$router->pattern('#^/tahlil/([a-zA-Z0-9_-]+)/([0-9]+)/?$#', 'test_view.php', [1 => 'slug', 2 => 'id']);

// Per-user RSS: /user/{username}/rss.xml or /{username}/rss.xml
$router->pattern('#^/user/([^/]+)/rss\.xml$#', 'user_rss.php', [1 => 'username']);
$router->pattern('#^/([^/]+)/rss\.xml$#', 'user_rss.php', [1 => 'username']);

// Username-scoped routes: /{user}/{post_id}, /{user}/kuyruktaki, /{user}/kuyrukta
$router->pattern('#^/([^/]+)/([0-9]+)/?$#', 'post.php', [1 => 'username', 2 => 'id']);
$router->pattern('#^/([^/]+)/kuyruktaki$#', 'followers.php', [1 => 'username']);
$router->pattern('#^/([^/]+)/kuyrukta$#', 'following.php', [1 => 'username']);

// karsilastirma compare param injection for matched post routes
// (handled above via pattern order — no extra rule needed)

// ══════════════════════════════════════════════════════════════
// FALLBACK — profile slug or catch-all
// ══════════════════════════════════════════════════════════════
$router->fallback(function (string $path, string $basePath) {
    // Reserved directory prefixes — should never match as profile
    $reserved = ['admin', 'api', 'assets', 'includes', 'lang', 'migrations',
                 'docs', 'templates', 'tests', 'QA', 'src', 'modules', 'vendor',
                 'webhook', 'binder', 'tools', 'logs', 'sitemap_cache', 'tmp'];
    $firstSegment = explode('/', ltrim($path, '/'))[0] ?? '';
    if (in_array(strtolower($firstSegment), $reserved, true)) {
        http_response_code(404);
        echo '404 Not Found';
        return;
    }

    // Clean slug profile: /username-slug
    if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#i', $path, $m)) {
        $_GET['slug'] = $m[1];
        require $basePath . '/profile.php';
        return;
    }

    // Non-ASCII / encoded username (legacy support)
    if (preg_match('#^/([^/]+)$#', $path, $m)) {
        $_GET['username'] = $m[1];
        require $basePath . '/profile.php';
        return;
    }

    // True 404
    http_response_code(404);
    echo '404 Not Found';
});