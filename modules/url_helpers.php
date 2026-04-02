<?php
// url_helpers module
if (!function_exists('use_clean_urls')) {
    function use_clean_urls() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        if (defined('USE_CLEAN_URLS')) {
            $cached = (bool)USE_CLEAN_URLS;
            if ($cached) {
                return true;
            }
        }
        if (getenv('USE_CLEAN_URLS') === '1') {
            $cached = true;
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri && strpos($uri, '.php') === false) {
            $cached = true;
            return true;
        }

        $cached = false;
        return false;
    }
}

if (!function_exists('is_username_clean_url_safe')) {
    function is_username_clean_url_safe($username) {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/u', $username);
    }
}

if (!function_exists('get_user_slug')) {
    function get_user_slug($username) {
        try {
            $stmt = query("SELECT slug FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['slug'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('profile_url')) {
    function profile_url($username) {
        $username = trim((string)$username);
        if ($slug = get_user_slug($username)) {
            return BASE_PATH . '/' . rawurlencode($slug);
        }
        if (use_clean_urls() && is_username_clean_url_safe($username)) {
            return BASE_PATH . '/' . rawurlencode($username);
        }
        $generated = function_exists('generate_username_slug') ? generate_username_slug($username) : '';
        if (!empty($generated) && is_username_clean_url_safe($generated)) {
            return BASE_PATH . '/' . rawurlencode($generated);
        }
        return BASE_PATH . '/profile.php?username=' . rawurlencode($username);
    }
}

if (!function_exists('get_post_url')) {
    function get_post_url($post_id, $username = null) {
        $post_id = intval($post_id);
        if (!$post_id) {
            return BASE_PATH . '/post.php';
        }

        if (!empty($username) && is_username_clean_url_safe($username)) {
            return BASE_PATH . '/' . rawurlencode($username) . '/p/' . $post_id;
        }

        return BASE_PATH . '/p/' . $post_id;
    }
}

if (!function_exists('post_url')) {
    function post_url($post_id) {
        if (use_clean_urls()) {
            $username = null;
            try {
                $stmt = query("SELECT u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?", [$post_id]);
                $r = $stmt->fetch();
                if ($r) $username = $r['username'];
            } catch (Exception $e) {
            }
            return function_exists('get_post_url') ? get_post_url($post_id, $username) : BASE_PATH . '/p/' . intval($post_id);
        }
        return BASE_PATH . '/post.php?id=' . intval($post_id);
    }
}

if (!function_exists('edit_post_url')) {
    function edit_post_url($post_id) {
        if (use_clean_urls()) {
            return BASE_PATH . '/post/' . intval($post_id) . '/edit';
        }
        return BASE_PATH . '/edit_post.php?id=' . intval($post_id);
    }
}

if (!function_exists('followers_url')) {
    function followers_url($username) {
        if (use_clean_urls() && is_username_clean_url_safe($username)) {
            return BASE_PATH . '/' . rawurlencode($username) . '/kuyruktaki';
        }
        return BASE_PATH . '/followers.php?username=' . rawurlencode($username);
    }
}

if (!function_exists('following_url')) {
    function following_url($username) {
        if (use_clean_urls() && is_username_clean_url_safe($username)) {
            return BASE_PATH . '/' . rawurlencode($username) . '/kuyrukta';
        }
        return BASE_PATH . '/following.php?username=' . rawurlencode($username);
    }
}

if (!function_exists('notification_url')) {
    function notification_url($filter = null) {
        if (!use_clean_urls()) {
            $url = BASE_PATH . '/notification.php';
            if ($filter && $filter !== 'all') {
                $url .= '?filter=' . rawurlencode($filter);
            }
            return $url;
        }
        $url = BASE_PATH . '/bildirimler';
        if ($filter && $filter !== 'all') {
            $url .= '/' . rawurlencode($filter);
        }
        return $url;
    }
}

if (!function_exists('premium_url')) {
    function premium_url() {
        if (use_clean_urls()) {
            return BASE_PATH . '/premium';
        }
        return BASE_PATH . '/premium.php';
    }
}

if (!function_exists('events_url')) {
    function events_url() {
        if (use_clean_urls()) {
            return BASE_PATH . '/etkinlikler';
        }
        return BASE_PATH . '/events.php';
    }
}

if (!function_exists('search_url')) {
    function search_url() {
        if (use_clean_urls()) {
            return BASE_PATH . '/ara';
        }
        return BASE_PATH . '/search.php';
    }
}

if (!function_exists('rules_url')) {
    function rules_url() {
        if (use_clean_urls()) {
            return BASE_PATH . '/kurallar-sartlar';
        }
        return BASE_PATH . '/rules.php';
    }
}

if (!function_exists('privacy_url')) {
    function privacy_url() {
        return BASE_PATH . '/gizlilik';
    }
}

if (!function_exists('kvkk_url')) {
    function kvkk_url() {
        return BASE_PATH . '/kvkk';
    }
}

if (!function_exists('cookie_policy_url')) {
    function cookie_policy_url() {
        return BASE_PATH . '/cerezler';
    }
}

if (!function_exists('admin_url')) {
    function admin_url() {
        return BASE_PATH . '/admin/index.php';
    }
}

if (!function_exists('group_edit_post_url')) {
    function group_edit_post_url($slug, $post_id) {
        return BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post_id . '/edit';
    }
}

if (!function_exists('home_url')) {
    function home_url() {
        if (use_clean_urls()) {
            return BASE_PATH . '/anasayfa';
        }
        return BASE_PATH . '/index.php';
    }
}

if (!function_exists('invite_url')) {
    function invite_url($token = null) {
        if (defined('SITE_URL') && !empty(SITE_URL)) {
            if (!$token) {
                return BASE_PATH . '/kayit';
            }
            return BASE_PATH . '/kayit/' . rawurlencode($token);
        }
        if (!use_clean_urls()) {
            if ($token) {
                return BASE_PATH . '/register.php?invite=' . rawurlencode($token);
            }
            return BASE_PATH . '/register.php';
        }
        if (!$token) {
            return BASE_PATH . '/kayit';
        }
        return BASE_PATH . '/kayit/' . rawurlencode($token);
    }
}

if (!function_exists('password_reset_url')) {
    function password_reset_url($token = null) {
        if (defined('SITE_URL') && !empty(SITE_URL)) {
            if (!$token) {
                return BASE_PATH . '/sifremi-unuttum';
            }
            return BASE_PATH . '/sifremi-unuttum/' . rawurlencode($token);
        }
        if (!use_clean_urls()) {
            if ($token) {
                return BASE_PATH . '/forgot_password.php?token=' . rawurlencode($token);
            }
            return BASE_PATH . '/forgot_password.php';
        }
        if (!$token) {
            return BASE_PATH . '/sifremi-unuttum';
        }
        return BASE_PATH . '/sifremi-unuttum/' . rawurlencode($token);
    }
}

if (!function_exists('canonical_url')) {
    function canonical_url() {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = basename($_SERVER['PHP_SELF'] ?? '');
        if ($script === 'index.php') {
            return $scheme . '://' . $host . home_url();
        }
        if ($script === 'profile.php' && !empty($_GET['username'])) {
            return $scheme . '://' . $host . profile_url($_GET['username']);
        }
        if (($script === 'post.php' || $script === 'p.php') && !empty($_GET['id'])) {
            return $scheme . '://' . $host . post_url((int)$_GET['id']);
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = preg_replace('/([?&])(sid|one|token)=[^&]*/', '\\1', $uri);
        $uri = rtrim($uri, '?&');
        return $scheme . '://' . $host . $uri;
    }
}

if (!function_exists('full_url')) {
    function full_url($path) {
        if (preg_match('#^https?://#i', $path)) return $path;
        if (defined('SITE_URL') && !empty(SITE_URL)) {
            $base = rtrim(SITE_URL, '/');
            if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
            return $base . $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
        return $scheme . '://' . $host . $path;
    }
}
