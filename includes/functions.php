<?php /* EN + TR comments used. */
require_once __DIR__ . '/db.php';

// Polyfills for environments lacking mbstring extension
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = 'UTF-8') {
        return strlen($s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = 'UTF-8') {
        if ($len === null) return substr($s, $start);
        return substr($s, $start, $len);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $enc = 'UTF-8') {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trimmarker = '', $enc = 'UTF-8') {
        $ret = substr($s, $start, $width);
        if (strlen($s) > $width) $ret .= $trimmarker;
        return $ret;
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = 'UTF-8') {
        // simple fallback to lowercase; ignores multibyte specifics
        return strtolower($s);
    }
}
if (!function_exists('mb_strrev')) {
    function mb_strrev($str) {
        return strrev($str);
    }
}



/**
 * Clean URL Helper Functions
 * Falls back to query parameters if clean URLs don't work
 */

// Check if mod_rewrite is likely enabled (simple check)
function use_clean_urls() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // explicit configuration overrides
    if (defined('USE_CLEAN_URLS')) {
        $cached = (bool)USE_CLEAN_URLS;
        error_log("use_clean_urls override const => " . var_export($cached, true));
        if ($cached) {
            return $cached; // only short-circuit when constant is true
        }
        // if constant is false, continue to detection below
    }
    if (getenv('USE_CLEAN_URLS') === '1') {
        $cached = true;
        error_log("use_clean_urls override env => true");
        return true;
    }

    // automatic detection: if the current request URI doesn't contain ".php"
    // we assume the rewrite engine produced a clean URL.  ignore the
    // SCRIPT_NAME because pages like /edmin still execute profile.php.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    error_log("use_clean_urls detection: uri='$uri'");
    if ($uri && strpos($uri, '.php') === false) {
        $cached = true;
        error_log("use_clean_urls detection succeeded");
        return true;
    }

    // default fallback
    $cached = false;
    error_log("use_clean_urls fallback false");
    return false;
}

// Determine whether username is safe for clean URL routing
function is_username_clean_url_safe($username) {
    // Allow alphanumeric + underscore + hyphen for clean URL routing.
    // Names with spaces/unicode are handled through slug lookup or query fallback.
    return (bool) preg_match('/^[A-Za-z0-9_-]+$/u', $username);
}

// Generate profile URL
function profile_url($username) {
    $username = trim((string)$username);

    // Prefer the canonical slug for profile URLs when possible
    if ($slug = get_user_slug($username)) {
        return BASE_PATH . '/' . rawurlencode($slug);
    }

    // For names that are already clean-url-safe, use direct clean URL
    if (use_clean_urls() && is_username_clean_url_safe($username)) {
        return BASE_PATH . '/' . rawurlencode($username);
    }

    // For Unicode names (ş, ç, ğ, etc.), use generated slug where available
    $generated = generate_username_slug($username);
    if (!empty($generated) && is_username_clean_url_safe($generated)) {
        return BASE_PATH . '/' . rawurlencode($generated);
    }

    // Last fallback: query parameter form
    return BASE_PATH . '/profile.php?username=' . rawurlencode($username);
}

function get_user_slug($username) {
    try {
        $stmt = query("SELECT slug FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['slug']) ? $row['slug'] : null;
    } catch (Exception $e) {
        // Slug column may not exist; safe fallback to no slug
        error_log('get_user_slug error: ' . $e->getMessage());
        return null;
    }
}

// Generate clean post URL (attempts to include username for SEO)
function post_url($post_id) {
    if (use_clean_urls()) {
        // Try to load the post's username for nicer URLs: /username/123
        $username = null;
        try {
            $stmt = query("SELECT u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?", [$post_id]);
            $r = $stmt->fetch();
            if ($r) $username = $r['username'];
        } catch (Exception $e) {
            error_log('post_url username lookup error: ' . $e->getMessage());
            // fall back to numeric path
        }
        return get_post_url($post_id, $username);
    } else {
        return BASE_PATH . '/post.php?id=' . intval($post_id);
    }
}

// Generate clean edit post URL
function edit_post_url($post_id) {
    if (use_clean_urls()) {
        return BASE_PATH . '/post/' . intval($post_id) . '/edit';
    } else {
        return BASE_PATH . '/edit_post.php?id=' . intval($post_id);
    }
}

// Generate followers URL (kuyruktaki)
function followers_url($username) {
    if (use_clean_urls() && is_username_clean_url_safe($username)) {
        return BASE_PATH . '/' . rawurlencode($username) . '/kuyruktaki';
    }
    return BASE_PATH . '/followers.php?username=' . rawurlencode($username);
}

// Generate following URL (kuyrukta)
function following_url($username) {
    if (use_clean_urls() && is_username_clean_url_safe($username)) {
        return BASE_PATH . '/' . rawurlencode($username) . '/kuyrukta';
    }
    return BASE_PATH . '/following.php?username=' . rawurlencode($username);
}


// Generate notification URL (filter optional)
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

/**
 * Fetch a small set of random posts for landing page.
 * Strategy: select a bounded candidate set of recent post ids, shuffle in PHP,
 * then fetch the full post rows for the chosen ids. This avoids ORDER BY RAND()
 * on large tables while providing good randomness and predictable cost.
 *
 * @param int $count number of posts to return
 * @param int $candidates number of candidate ids to consider (tuneable)
 * @return array list of post rows with additional `like_count` and `comment_count`
 */
function get_random_posts($count = 3, $candidates = 300) {
    global $pdo;

    $count = max(1, (int)$count);
    $candidates = max($count, (int)$candidates);

    try {
        // Step 1: fetch candidate ids (recent posts)
        $stmt = $pdo->prepare(
            "SELECT p.id FROM posts p JOIN users u ON p.user_id = u.id
             WHERE p.parent_id IS NULL AND p.deleted_at IS NULL
               AND u.deleted_at IS NULL AND u.is_approved = 1
             ORDER BY p.created_at DESC
             LIMIT :candidates"
        );
        $stmt->bindValue(':candidates', $candidates, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($ids)) return [];

        // Step 2: shuffle and pick desired count
        shuffle($ids);
        $selected = array_slice($ids, 0, $count);

        // Step 3: fetch full post rows for selected ids
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $q = "SELECT p.*, u.username,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS like_count,
                    (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) AS comment_count
               FROM posts p JOIN users u ON p.user_id = u.id
              WHERE p.id IN ($placeholders)";
        // preserve the selection order by using FIELD if available
        if (stripos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false) {
            $q .= ' ORDER BY FIELD(p.id, ' . implode(',', array_map('intval', $selected)) . ')';
        }
        $stmt2 = $pdo->prepare($q);
        $stmt2->execute($selected);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // If DB didn't preserve order, reorder in PHP to match $selected
        if (count($rows) > 1) {
            $rows_by_id = [];
            foreach ($rows as $r) $rows_by_id[$r['id']] = $r;
            $ordered = [];
            foreach ($selected as $id) {
                if (isset($rows_by_id[$id])) $ordered[] = $rows_by_id[$id];
            }
            return $ordered;
        }

        return $rows;
    } catch (Exception $e) {
        error_log('get_random_posts error: ' . $e->getMessage());
        return [];
    }
}

// Generate premium URL
function premium_url() {
    if (use_clean_urls()) {
        return BASE_PATH . '/premium';
    } else {
        return BASE_PATH . '/premium.php';
    }
}

// Generate events URL
function events_url() {
    if (use_clean_urls()) {
        return BASE_PATH . '/etkinlikler';
    } else {
        return BASE_PATH . '/events.php';
    }
}

// Generate search URL
function search_url() {
    if (use_clean_urls()) {
        return BASE_PATH . '/ara';
    } else {
        return BASE_PATH . '/search.php';
    }
}

// Generate rules URL
function rules_url() {
    if (use_clean_urls()) {
        return BASE_PATH . '/kurallar-sartlar';
    } else {
        return BASE_PATH . '/rules.php';
    }
}

// Generate privacy URL
function privacy_url() {
    // Always use clean URL by default (redirects are in place for legacy PHP paths)
    return BASE_PATH . '/gizlilik';
}

// Generate KVKK URL
function kvkk_url() {
    return BASE_PATH . '/kvkk';
}

// Generate cookie policy URL
function cookie_policy_url() {
    return BASE_PATH . '/cerezler';
}

// Generate admin URL
function admin_url() {
    return BASE_PATH . '/admin/index.php';
}

// Ngrok request limiting is implemented as a module in `modules/ngrok_limit.php`.
// To disable, remove or rename that module file. The admin UI will use the
// module's helpers if present.


// Check whether a username is reserved (case-insensitive).
// Add reserved names here; only admins may create these accounts.
function is_reserved_username($username) {
    $username = mb_strtolower(trim((string)$username));
    $reserved = [
        'mevzuatraporu',
        'mevzuat',
        'rapor',
    ];
    return in_array($username, $reserved, true);
}


// RBAC helpers: roles & permissions (DB-backed)
function get_all_roles() {
    $stmt = query("SELECT id, `key`, name, description FROM roles ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_all_permissions() {
    $stmt = query("SELECT id, `key`, name, description FROM permissions ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_role_by_key($role_key) {
    $stmt = query("SELECT id, `key`, name, description FROM roles WHERE `key` = ? LIMIT 1", [$role_key]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_role_permissions($role_id) {
    $stmt = query("SELECT p.* FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?", [$role_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['key']] = $r;
    return $out;
}

function get_user_roles($user_id) {
    $stmt = query("SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?", [$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_primary_role($user_id) {
    $roles = get_user_roles($user_id);
    if (!empty($roles)) return $roles[0];
    // Fallback to legacy `users.role` column
    $stmt = query("SELECT role FROM users WHERE id = ? LIMIT 1", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['role'])) {
        return ['key' => $row['role'], 'name' => ucfirst($row['role'])];
    }
    return null;
}

// Generate homepage URL
function home_url() {
    if (use_clean_urls()) {
        return BASE_PATH . '/anasayfa';
    } else {
        return BASE_PATH . '/index.php';
    }
}

// Generate registration URL (optionally with invite token)
function invite_url($token = null) {
    // If a SITE_URL is configured, assume clean URL style is desired for emails and external links.
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

// Generate password reset URL (optionally with reset token)
function password_reset_url($token = null) {
    // If a SITE_URL is configured, use clean paths for emails/external links.
    if (defined('SITE_URL') && !empty(SITE_URL)) {
        if (!$token) {
            return BASE_PATH . '/sifirla';
        }
        return BASE_PATH . '/sifirla/' . rawurlencode($token);
    }

    if (!use_clean_urls()) {
        if ($token) {
            return BASE_PATH . '/forgot_password.php?token=' . rawurlencode($token);
        }
        return BASE_PATH . '/forgot_password.php';
    }

    if (!$token) {
        return BASE_PATH . '/sifirla';
    }
    return BASE_PATH . '/sifirla/' . rawurlencode($token);
}

function canonical_url() {
    // Build scheme and host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Homepage
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    if ($script === 'index.php') {
        return $scheme . '://' . $host . home_url();
    }

    // Profile pages
    if ($script === 'profile.php' && !empty($_GET['username'])) {
        return $scheme . '://' . $host . profile_url($_GET['username']);
    }

    // Post pages
    if (($script === 'post.php' || $script === 'p.php') && !empty($_GET['id'])) {
        return $scheme . '://' . $host . post_url((int)$_GET['id']);
    }

    // Fallback: use REQUEST_URI but strip tracking params like sid, one, token
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Remove sid or one or token parameters
    $uri = preg_replace('/([?&])(sid|one|token)=[^&]*/', '\1', $uri);
    $uri = rtrim($uri, '?&');
    return $scheme . '://' . $host . $uri;
}

// Build a full URL from a path or return absolute URLs unchanged.
function full_url($path) {
    // If absolute already, return as-is
    if (preg_match('#^https?://#i', $path)) return $path;

    // Prefer a configured site URL (useful for CLI tasks and email generation)
    if (defined('SITE_URL') && !empty(SITE_URL)) {
        $base = rtrim(SITE_URL, '/');
        if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
        return $base . $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Ensure $path begins with a slash
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');

    return $scheme . '://' . $host . $path;
}

/**
 * Render a small sparkline-style SVG from an array of numeric data.
 * No JavaScript required; output is inline SVG.
 */
function render_sparkline_svg(array $data, $width = 420, $height = 80, $color = '#5a9a3c') {
    if (empty($data)) {
        return '<div class="card-box padded">No data</div>';
    }

    $n = count($data);
    $min = min($data);
    $max = max($data);
    if ($min === $max) { $min -= 1; $max += 1; }

    $stepX = ($n > 1) ? ($width / ($n - 1)) : $width;
    $pad = 4; // small vertical padding
    $points = [];
    $xs = [];
    $ys = [];
    foreach ($data as $i => $v) {
        $x = round($i * $stepX, 2);
        $y = $height - round((($v - $min) / ($max - $min)) * ($height - ($pad * 2)), 2) - $pad;
        $points[] = $x . ',' . $y;
        $xs[] = $x; $ys[] = $y;
    }
    $polyline = implode(' ', $points);

    // Build polygon for filled area (baseline to first/last x at bottom)
    $firstX = $xs[0] . ',' . $height;
    $lastX = end($xs) . ',' . $height;
    $polyfill = $firstX . ' ' . $polyline . ' ' . $lastX;

    // Safe color
    $color_esc = htmlspecialchars($color, ENT_QUOTES);

    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' role='img' aria-hidden='true' preserveAspectRatio='none'>";
    $svg .= "<defs><linearGradient id='g' x1='0' x2='0' y1='0' y2='1'><stop offset='0' stop-color='{$color_esc}' stop-opacity='0.15'/><stop offset='1' stop-color='{$color_esc}' stop-opacity='0.03'/></linearGradient></defs>";
    $svg .= "<polygon points='" . $polyfill . "' fill='url(#g)' />";
    $svg .= "<polyline points='" . $polyline . "' fill='none' stroke='" . $color_esc . "' stroke-width='2' stroke-linejoin='round' stroke-linecap='round' />";
    $svg .= "</svg>";

    return $svg;
}

/**
 * Render a simple horizontal bar chart as inline SVG.
 * $items is an array of ['label' => string, 'value' => int]
 */
function render_bar_chart_svg(array $items, $width = 360, $bar_height = 18, $color = '#5a9a3c') {
    if (empty($items)) return '<div class="card-box padded">Veri yok</div>';

    $max = max(array_column($items, 'value')) ?: 1;
    $label_w = 80; // reserved width for labels
    $gap = 8;
    $inner_w = max(40, $width - $label_w - 40);
    $height = count($items) * ($bar_height + $gap) + 8;

    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' role='img' aria-hidden='true' preserveAspectRatio='none'>";
    $y = 6;
    foreach ($items as $row) {
        $label = htmlspecialchars($row['label']);
        $val = intval($row['value']);
        $w = round(($val / $max) * $inner_w, 2);
        $x = $label_w;
        // Bar background
        $svg .= "<rect x='{$x}' y='{$y}' width='" . ($inner_w) . "' height='{$bar_height}' fill='#f1f3f5' rx='4' />";
        // Bar fill
        $svg .= "<rect x='{$x}' y='{$y}' width='{$w}' height='{$bar_height}' fill='{$color}' rx='4' />";
        // Label
        $svg .= "<text x='6' y='" . ($y + $bar_height/1.6) . "' font-family='sans-serif' font-size='12' fill='#333'>{$label}</text>";
        // Value
        $svg .= "<text x='" . ($x + $inner_w + 6) . "' y='" . ($y + $bar_height/1.6) . "' font-family='sans-serif' font-size='12' fill='#666'>{$val}</text>";
        $y += $bar_height + $gap;
    }
    $svg .= "</svg>";
    return $svg;
}

/**
 * Validate a referer URL/path and ensure it's an internal path.
 * If $require_admin is true, only allow paths within the admin area.
 * Returns a safe referer path (including query string if present) or the provided/default fallback.
 */
function validate_referer($referer, $default = null, $require_admin = false) {
    if ($default === null) {
        $default = $require_admin ? BASE_PATH . '/admin/index.php' : home_url();
    }

    if (empty($referer)) return $default;

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = $referer;

    $p = parse_url($referer);
    if (isset($p['host'])) {
        // Absolute URL: require same host
        if ($p['host'] !== $host) return $default;
        $path = $p['path'] ?? '/';
        if (!empty($p['query'])) $path .= '?' . $p['query'];
    }

    // Normalize path
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');

    $allowed_base = rtrim(BASE_PATH, '/');

    // If path starts with BASE_PATH (e.g., /textsocialmedia/admin/...), allow it
    if (strpos($path, $allowed_base) === 0) {
        if ($require_admin && strpos($path, '/admin') === false) return $default;
        return $path;
    }

    // If admin path is required, allow only if it starts with /admin
    if ($require_admin) {
        if (strpos($path, '/admin') === 0) return $path;
        return $default;
    }

    // For general referer, allow internal absolute paths (starting with /)
    if ($path[0] === '/') return $path;

    return $default;
}

/**
 * Sanitize user input for safe HTML output.
 * Trims whitespace AND encodes HTML entities to prevent XSS.
 * Use for displaying user-provided text in HTML context.
 * For raw (unescaped) trimming only, use trim() directly.
 */
function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function filter_bad_words($text) {
    // Get bad words from database (with caching)
    static $bad_words_cache = null;
    
    if ($bad_words_cache === null) {
        $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
        $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    foreach ($bad_words_cache as $word) {
        // Use word boundary to match whole words only
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $text)) {
            return false;
        }
    }
    return true;
}

// Replace bad words with asterisks and return cleaned text
// Returns: ['clean' => cleaned text, 'has_bad_words' => bool]
function censor_bad_words($text) {
    // Get bad words from database (with caching)
    static $bad_words_cache = null;
    
    if ($bad_words_cache === null) {
        $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
        $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    $has_bad_words = false;
    $clean_text = $text;
    
    foreach ($bad_words_cache as $word) {
        // Use word boundary to match whole words only
        $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
        if (preg_match($pattern, $clean_text)) {
            $has_bad_words = true;
            // Replace with asterisks of same length
            $replacement = str_repeat('*', mb_strlen($word));
            $clean_text = preg_replace($pattern, $replacement, $clean_text);
        }
    }
    
    return [
        'clean' => $clean_text,
        'has_bad_words' => $has_bad_words
    ];
}

// Admin: Get all bad words
function get_bad_words() {
    $stmt = query("SELECT * FROM bad_words ORDER BY word ASC");
    return $stmt->fetchAll();
}

// Admin: Add a bad word
function add_bad_word($word, $admin_id = null) {
    $word = strtolower(trim($word));
    if (empty($word)) {
        return false;
    }
    try {
        query("INSERT INTO bad_words (word, created_by) VALUES (?, ?)", [$word, $admin_id]);
        return true;
    } catch (Exception $e) {
        error_log('add_bad_word error: ' . $e->getMessage());
        return false; // Duplicate or other error
    }
}

// Admin: Delete a bad word
function delete_bad_word($id) {
    query("DELETE FROM bad_words WHERE id = ?", [$id]);
}

// Normalize text to catch word game variations
function normalize_text_variants($text) {
    $text = mb_strtolower($text);
    
    // 1. Leet speak normalization (common substitutions)
    $leet_map = [
        '4' => 'a', '@' => 'a',
        '3' => 'e', '€' => 'e',
        '1' => 'i', '!' => 'i', '|' => 'i',
        '0' => 'o',
        '5' => 's', '$' => 's',
        '7' => 't',
        '9' => 'g',
        '8' => 'b',
    ];
    $text = strtr($text, $leet_map);
    
    // 2. Remove common separators (spaces, dashes, dots, underscores)
    $text = preg_replace('/[\s\-._]+/', '', $text);
    
    // 3. Remove character repetition (more than 2 consecutive same chars)
    // "siiiiik" -> "siik", "helllllo" -> "hello"
    $text = preg_replace('/(.)\1{2,}/u', '$1$1', $text);
    
    // 4. Remove all non-letter/non-number characters
    $text = preg_replace('/[^\p{L}\p{N}]/u', '', $text);
    
    return $text;
}

// Get all variants of a word to check
function get_word_variants($word) {
    $variants = [];
    $normalized = normalize_text_variants($word);
    
    // Original normalized form
    $variants[] = $normalized;
    
    // Reversed form (to catch "kis" for "sik")
    $reversed = mb_strrev($normalized);
    if ($reversed !== $normalized) {
        $variants[] = $reversed;
    }
    
    return array_unique($variants);
}

// Multi-byte string reverse function
function mb_strrev($str) {
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    return implode('', array_reverse($chars));
}

// Calculate Levenshtein similarity percentage between two strings
function calculate_similarity($str1, $str2) {
    $str1 = mb_strtolower($str1);
    $str2 = mb_strtolower($str2);
    
    $len1 = mb_strlen($str1);
    $len2 = mb_strlen($str2);
    
    if ($len1 === 0 || $len2 === 0) {
        return 0;
    }
    
    $distance = levenshtein($str1, $str2);
    $maxLen = max($len1, $len2);
    
    return (1 - ($distance / $maxLen)) * 100;
}

// Check if word is in approved whitelist
function is_word_approved($word) {
    $word = mb_strtolower(trim($word));
    $stmt = query("SELECT COUNT(*) as count FROM approved_words WHERE LOWER(word) = ?", [$word]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

// Get similarity threshold from settings
function get_similarity_threshold() {
    $stmt = query("SELECT setting_value FROM premium_settings WHERE setting_key = 'similarity_threshold'");
    $result = $stmt->fetch();
    return $result ? (int)$result['setting_value'] : 75;
}



// Check text for suspicious word variations
// Returns: ['suspicious' => bool, 'matched_words' => array of [bad_word, found_word, similarity]]
function check_suspicious_content($text) {
    static $bad_words_cache = null;
    
    if ($bad_words_cache === null) {
        $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
        $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    $threshold = get_similarity_threshold();
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $suspicious_matches = [];
    
    foreach ($words as $word) {
        // Clean word from punctuation
        $clean_word = preg_replace('/[^\p{L}\p{N}\s\-._@!|€$]/u', '', $word);
        $clean_word_lower = mb_strtolower($clean_word);
        
        // Skip short words or already approved words
        if (mb_strlen($clean_word) <= 3 || is_word_approved($clean_word)) {
            continue;
        }
        
        // Get all variants of this word (normalized, reversed, etc.)
        $word_variants = get_word_variants($clean_word);
        
        foreach ($bad_words_cache as $bad_word) {
            $bad_word_lower = mb_strtolower($bad_word);
            
            // Skip if exact match (already handled by censoring)
            if ($clean_word_lower === $bad_word_lower) {
                continue;
            }
            
            // Check all variants of the user's word
            foreach ($word_variants as $variant) {
                // Check 1: Does the variant CONTAIN the bad word? (substring match)
                // Example: "sikiminiki" normalized contains "sik"
                if (mb_strlen($bad_word) >= 3 && mb_strpos($variant, $bad_word_lower) !== false) {
                    $suspicious_matches[] = [
                        'bad_word' => $bad_word,
                        'found_word' => $clean_word,
                        'similarity' => 100.0,
                        'match_type' => 'contains',
                        'variant_used' => $variant !== $clean_word_lower ? $variant : null
                    ];
                    break 2; // Found a match, move to next word
                }
                
                // Check 2: Is the variant SIMILAR to the bad word? (Levenshtein distance)
                // Example: "sikk" is similar to "sik"
                $similarity = calculate_similarity($variant, $bad_word);
                
                if ($similarity >= $threshold) {
                    $suspicious_matches[] = [
                        'bad_word' => $bad_word,
                        'found_word' => $clean_word,
                        'similarity' => round($similarity, 1),
                        'match_type' => 'similar',
                        'variant_used' => $variant !== $clean_word_lower ? $variant : null
                    ];
                    break 2; // Found a match, move to next word
                }
            }
        }
    }
    
    return [
        'suspicious' => !empty($suspicious_matches),
        'matched_words' => $suspicious_matches
    ];
}

// Add word to approved whitelist
function approve_word($word, $admin_id) {
    $word = mb_strtolower(trim($word));
    if (empty($word)) {
        return false;
    }
    try {
        query("INSERT INTO approved_words (word, approved_by) VALUES (?, ?)", [$word, $admin_id]);
        return true;
    } catch (Exception $e) {
        error_log('add_approved_word error: ' . $e->getMessage());
        return false; // Duplicate or error
    }
}

/*
 * IP blocking helpers - used by admin UI and middleware to block abusive IPs
 */
function ensure_blocked_ips_table() {
    try {
        query("CREATE TABLE IF NOT EXISTS blocked_ips (
            ip VARCHAR(45) PRIMARY KEY,
            reason VARCHAR(255) DEFAULT '',
            blocked_until DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[BLOCKED_IPS] table create error: ' . $e->getMessage());
    }
}

// Audit log table for admin actions
function ensure_audit_table() {
    try {
        query("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            admin_id INT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[AUDIT] ensure_audit_table error: ' . $e->getMessage());
    }
}

function log_admin_action($action, $details = '', $admin_id = null) {
    ensure_audit_table();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        query("INSERT INTO audit_logs (action, details, admin_id, ip, created_at) VALUES (?, ?, ?, ?, NOW())", [substr($action,0,100), $details, $admin_id, $ip]);
    } catch (Exception $e) {
        error_log('[AUDIT] log_admin_action error: ' . $e->getMessage());
    }
}

function block_ip($ip, $reason = '', $duration_seconds = 3600, $admin_id = null) {
    // FILTER_SANITIZE_STRING is deprecated; use full special chars sanitization
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return false;
    ensure_blocked_ips_table();
    $blocked_until = date('Y-m-d H:i:s', time() + intval($duration_seconds));
    try {
        query("REPLACE INTO blocked_ips (ip, reason, blocked_until, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [$ip, substr($reason,0,255), $blocked_until, $admin_id]);
        if ($admin_id !== null) {
            log_admin_action('block_ip', 'Blocked IP: ' . $ip . ' for ' . intval($duration_seconds) . 's; reason=' . substr($reason,0,200), $admin_id);
        }
        return true;
    } catch (Exception $e) {
        error_log('[BLOCKED_IPS] block_ip error: ' . $e->getMessage());
        return false;
    }
}

function unblock_ip($ip, $admin_id = null) {
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return false;
    ensure_blocked_ips_table();
    try {
        query("DELETE FROM blocked_ips WHERE ip = ?", [$ip]);
        if ($admin_id !== null) {
            log_admin_action('unblock_ip', 'Unblocked IP: ' . $ip, $admin_id);
        }
        return true;
    } catch (Exception $e) {
        error_log('[BLOCKED_IPS] unblock_ip error: ' . $e->getMessage());
        return false;
    }
}

function is_ip_blocked($ip) {
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return false;
    ensure_blocked_ips_table();
    try {
        $stmt = query("SELECT ip, reason, blocked_until FROM blocked_ips WHERE ip = ? LIMIT 1", [$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if ($row['blocked_until'] === null) return true;
        if (strtotime($row['blocked_until']) > time()) return true;
        // expired -> cleanup (remove expired row)
        try { query("DELETE FROM blocked_ips WHERE ip = ?", [$ip]); } catch (Exception $_) { error_log('blocked_ip cleanup error: ' . $_->getMessage()); }
        return false;
    } catch (Exception $e) {
        error_log('is_ip_blocked error: ' . $e->getMessage());
        return false;
    }
}

function get_blocked_ips($limit = 100, $offset = 0) {
    ensure_blocked_ips_table();
    $stmt = query("SELECT ip, reason, blocked_until, created_by, created_at FROM blocked_ips ORDER BY created_at DESC LIMIT ? OFFSET ?", [$limit, $offset]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// SIGNUP REQUESTS / GEO OPEN helpers
function ensure_signup_requests_table() {
    try {
        query("CREATE TABLE IF NOT EXISTS signup_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            country_code CHAR(2) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            token VARCHAR(128) NOT NULL,
            status ENUM('pending','verified','dismissed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_email_country (email, country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // open_countries table
        query("CREATE TABLE IF NOT EXISTS open_countries (
            country_code CHAR(2) PRIMARY KEY,
            opened TINYINT(1) NOT NULL DEFAULT 0,
            opened_at DATETIME DEFAULT NULL,
            opened_by INT DEFAULT NULL,
            auto_opened TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // index
        query("CREATE INDEX IF NOT EXISTS idx_signup_requests_country_status_created ON signup_requests (country_code, status, created_at)");
    } catch (Exception $e) {
        error_log('[SIGNUP_REQUESTS] ensure table error: ' . $e->getMessage());
    }
}

// Invitation helpers ------------------------------------------------
function ensure_invitations_table() {
    try {
        // avoid foreign key errors on badly‑configured users table by omitting FKs
        query("CREATE TABLE IF NOT EXISTS user_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invited_by INT NOT NULL,
            invited_user INT DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            status ENUM('pending','accepted','expired','revoked','already_registered') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME DEFAULT NULL,
            INDEX idx_invites_by (invited_by),
            INDEX idx_invites_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ensure enum includes new states if the table existed prior to update
        query("ALTER TABLE user_invitations MODIFY status ENUM('pending','accepted','expired','revoked','already_registered') NOT NULL DEFAULT 'pending'");

        // Add reminder metadata columns if missing
        query("ALTER TABLE user_invitations 
            ADD COLUMN IF NOT EXISTS last_reminder_at DATETIME DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS reminder_count INT NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        error_log('[INVITES] ensure table error: ' . $e->getMessage());
    }
}

function create_user_invitations($sender_id, array $emails) {
    ensure_invitations_table();
    $pdo = db_connect();
    $out = ['created'=>0,'skipped'=>[]];
    foreach ($emails as $e) {
        $e = mb_strtolower(trim($e));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out['skipped'][] = $e;
            continue;
        }

        // if the user is already registered, do not invite
        $existing_user = query("SELECT id FROM users WHERE LOWER(email)=? LIMIT 1", [$e])->fetch(PDO::FETCH_ASSOC);
        if ($existing_user) {
            $out['skipped'][] = $e;
            continue;
        }

        // maximum 10 invitations per user
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM user_invitations WHERE invited_by = ?");
        $cnt->execute([$sender_id]);
        if ($cnt->fetchColumn() >= 10) break;

        // skip duplicates
        $dup = $pdo->prepare("SELECT 1 FROM user_invitations WHERE invited_by = ? AND email = ? LIMIT 1");
        $dup->execute([$sender_id, $e]);
        if ($dup->fetch()) {
            $out['skipped'][] = $e;
            continue;
        }

        $token = bin2hex(random_bytes(32));
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO user_invitations (invited_by,email,token) VALUES (?,?,?)"
            );
            $stmt->execute([$sender_id,$e,$token]);
            send_invite_email($e, $token);
            $out['created']++;
        } catch (Exception $x) {
            error_log('invite_users error for ' . $e . ': ' . $x->getMessage());
            $out['skipped'][] = $e;
        }
    }
    return $out;
}

function send_invite_email($email, $token) {
    $link = full_url(invite_url($token));
    $subj = SITE_NAME . " - Davetiniz var";
    $body = "Merhaba,\n\n" .
            SITE_NAME . " platformuna davet edildiniz. Aşağıdaki bağlantı ile kayıt olursanız davetçi 1 ay premium üyelik kazanır:\n\n" .
            $link . "\n\n" .
            "Bağlantı 30 gün geçerlidir.";
    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        send_email($email, $subj, $body);
    }
}

function send_invite_reminder_email($email, $token) {
    $link = full_url(invite_url($token));
    $subj = SITE_NAME . " - Davet hatırlatması";
    $body = "Merhaba,\n\n" .
            SITE_NAME . " platformuna davet edildiniz. Kayıt olmaya henüz devam etmediniz. Aşağıdaki bağlantıya tıklayarak kaydolabilirsiniz:\n\n" .
            $link . "\n\n" .
            "Bu hatırlatma haftalık olarak gönderilmektedir.";
    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        send_email($email, $subj, $body);
    }
}

function send_weekly_invite_reminders() {
    ensure_invitations_table();
    $pdo = db_connect();

    $stmt = $pdo->prepare(
        "SELECT * FROM user_invitations ui
         WHERE ui.status = 'pending'
           AND (ui.last_reminder_at IS NULL OR ui.last_reminder_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))
           AND ui.reminder_count < 10"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent_count = 0;
    foreach ($rows as $inv) {
        // if user is already registered by another way, mark accordingly and skip reminder
        $user = query("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1", [$inv['email']])->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            query("UPDATE user_invitations SET status='already_registered', invited_user=?, accepted_at=NOW() WHERE id=?", [$user['id'], $inv['id']]);
            continue;
        }

        // send reminder
        send_invite_reminder_email($inv['email'], $inv['token']);
        query(
            "UPDATE user_invitations SET last_reminder_at=NOW(), reminder_count=reminder_count+1 WHERE id=?",
            [$inv['id']]
        );
        $sent_count++;
    }

    return ['sent' => $sent_count, 'checked' => count($rows)];
}

/*
 * Premium feature helpers – the same list is shown on the dedicated premium
 * page and referenced from other places like the invitation screen.
 *
 * Premium özellik yardımcıları – aynı liste, özel premium sayfasında ve
 * davet ekranı gibi diğer yerlerde kullanılır.
 */
function get_premium_features() {
    return [
        '♾️ Sınırsız gönderi uzunluğu',
        '✅ Gönderi düzenleme ve gelişmiş araçlar',
        '⭐ Premium rozet ve özel rozet oluşturma',
        '🔔 Özel etkinlik güncellemelerine erişim',
    ];
}

/* UNUSED_START render_premium_features
function render_premium_features() {
    echo "<ul class=\"premium-features-list\">\n";
    foreach (get_premium_features() as $feat) {
        echo "    <li>" . htmlspecialchars($feat) . "</li>\n";
    }
    echo "</ul>\n";
}
UNUSED_END render_premium_features */

function accept_invite_if_valid($user_id, $email) {
    ensure_invitations_table();
    if (empty($_GET['invite'])) return false;

    $email_normalized = mb_strtolower(trim($email));
    $stmt = query(
        "SELECT * FROM user_invitations WHERE token = ? AND LOWER(email) = ? AND status = 'pending' LIMIT 1",
        [$_GET['invite'], $email_normalized]
    );
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return false;

    query("UPDATE user_invitations SET status='accepted', invited_user=?, accepted_at=NOW() WHERE id=?", [$user_id, $inv['id']]);

    // grant 1 month premium to inviter
    // davet edene 1 ay premium ver
    $inviter = get_user($inv['invited_by']);
    if ($inviter) {
        $now = time();
        $existing = strtotime($inviter['premium_until'] ?: '0');
        $start = $existing > $now ? $existing : $now;
        $new_until = date('Y-m-d H:i:s', $start + 30*24*3600);
        query("UPDATE users SET is_premium=1, premium_until=? WHERE id=?", [$new_until, $inv['invited_by']]);
    }
    return true;
}

function mark_invite_as_already_registered($user_id, $email) {
    ensure_invitations_table();
    $email_normalized = mb_strtolower(trim($email));
    query(
        "UPDATE user_invitations SET status='already_registered', invited_user=?, accepted_at=NOW() WHERE LOWER(email)=? AND status='pending'",
        [$user_id, $email_normalized]
    );
}

// human-readable status for table output
function invite_status_label($status) {
    switch ($status) {
        case 'pending': return 'beklemede';
        case 'accepted': return 'kabul edildi';
        case 'expired': return 'süresi doldu';
        case 'revoked': return 'iptal edildi';
        case 'already_registered': return 'zaten kayıtlı';
        default: return htmlspecialchars($status);
    }
}

function get_country_by_ip($ip) {
    // Best-effort country lookup. Use geoip extension if available. For localhost return TR in dev.
    if (in_array($ip, ['127.0.0.1', '::1'])) return 'TR';
    if (!empty($_SERVER['GEOIP_COUNTRY_CODE'])) return $_SERVER['GEOIP_COUNTRY_CODE'];
    if (function_exists('geoip_country_code_by_name')) {
        return geoip_country_code_by_name($ip) ?: 'ZZ';
    }
    return 'ZZ';
}

function create_signup_request($email, $ip, $country_code, $user_agent = '') {
    ensure_signup_requests_table();
    $email = mb_strtolower(trim($email));
    $ip = filter_var($ip, FILTER_SANITIZE_STRING);
    $country_code = strtoupper(substr($country_code, 0, 2));

    // Rate limits: per IP per day
    $stmt = query("SELECT COUNT(*) as c FROM signup_requests WHERE ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", [$ip]);
    $c = (int)($stmt->fetch()['c'] ?? 0);
    if ($c >= REQUESTS_MAX_PER_IP_PER_DAY) {
        return ['success' => false, 'error' => 'rate_limit_ip'];
    }

    // Rate limit per email window
    $stmt = query("SELECT COUNT(*) as c FROM signup_requests WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$email, REQUESTS_MAX_PER_EMAIL_WINDOW_DAYS]);
    $e = (int)($stmt->fetch()['c'] ?? 0);
    if ($e > 0) {
        return ['success' => false, 'error' => 'already_requested'];
    }

    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + (REQUEST_TOKEN_EXPIRY_HOURS * 3600));
    try {
        query("INSERT INTO signup_requests (email, ip, country_code, user_agent, token, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?)", [$email, $ip, $country_code, substr($user_agent,0,255), $token, $expires_at]);
    } catch (Exception $e) {
        error_log('[SIGNUP_REQUESTS] insert error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'db_error'];
    }

    // Send verification email using template if available
    $verify_url = BASE_PATH . '/signup_request_verify.php?token=' . urlencode($token);
    $template_file = __DIR__ . '/../templates/email/signup_request_verification.txt';
    if (is_file($template_file)) {
        $tpl = file_get_contents($template_file);
        $body = str_replace(['{{site_name}}','{{verify_url}}','{{expires_hours}}'], [SITE_NAME, full_url($verify_url), REQUEST_TOKEN_EXPIRY_HOURS], $tpl);
        $subject = SITE_NAME . ' - E-posta Doğrulama';
    } else {
        $subject = 'E-posta Doğrulama - ' . SITE_NAME;
        $body = "Merhaba,\n\nLütfen e-posta adresinizi doğrulamak için aşağıdaki bağlantıya tıklayın:\n\n" . full_url($verify_url) . "\n\nBu bağlantı " . REQUEST_TOKEN_EXPIRY_HOURS . " saat sonra geçersiz olacaktır.\n\n- " . SITE_NAME;
    }
    send_email($email, $subject, $body);
    return ['success' => true, 'token' => $token];
}

function verify_signup_request($token) {
    ensure_signup_requests_table();
    $stmt = query("SELECT id, email, country_code, expires_at, status FROM signup_requests WHERE token = ? LIMIT 1", [$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'error' => 'not_found'];
    if ($row['status'] !== 'pending') return ['success' => false, 'error' => 'invalid_status'];
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return ['success' => false, 'error' => 'expired'];

    try {
        query("UPDATE signup_requests SET status = 'verified', verified_at = NOW() WHERE id = ?", [$row['id']]);
        return ['success' => true, 'email' => $row['email'], 'country' => $row['country_code']];
    } catch (Exception $e) {
        error_log('[SIGNUP_REQUESTS] verify update error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'db_error'];
    }
}

/* UNUSED_START count_verified_requests
function count_verified_requests($country_code, $days = null) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    $days = $days === null ? REQUESTS_COUNT_WINDOW_DAYS : intval($days);
    $stmt = query("SELECT COUNT(*) as c FROM signup_requests WHERE country_code = ? AND status = 'verified' AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$country_code, $days]);
    return (int)($stmt->fetch()['c'] ?? 0);
}
UNUSED_END count_verified_requests */

function is_country_open($country_code) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    $stmt = query("SELECT opened FROM open_countries WHERE country_code = ? LIMIT 1", [$country_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (bool)$row['opened'] : false;
}

function open_country($country_code, $admin_id = null, $auto = false) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    try {
        query("REPLACE INTO open_countries (country_code, opened, opened_at, opened_by, auto_opened) VALUES (?, 1, NOW(), ?, ?)", [$country_code, $admin_id, $auto ? 1 : 0]);
        log_admin_action('open_country', 'Opened country: ' . $country_code . ' auto=' . ($auto?1:0), $admin_id);
        return true;
    } catch (Exception $e) {
        error_log('[OPEN_COUNTRY] error: ' . $e->getMessage());
        return false;
    }
}

function close_country($country_code, $admin_id = null) {
    ensure_signup_requests_table();
    $country_code = strtoupper(substr($country_code, 0, 2));
    try {
        query("REPLACE INTO open_countries (country_code, opened, opened_at, opened_by, auto_opened) VALUES (?, 0, NULL, ?, 0)", [$country_code, $admin_id]);
        log_admin_action('close_country', 'Closed country: ' . $country_code, $admin_id);
        return true;
    } catch (Exception $e) {
        error_log('[OPEN_COUNTRY] close error: ' . $e->getMessage());
        return false;
    }
}

function notify_country_opened($country_code) {
    // Send email to verified requesters in the recent window
    ensure_signup_requests_table();
    $country = strtoupper(substr($country_code,0,2));
    $stmt = query("SELECT DISTINCT email FROM signup_requests WHERE country_code = ? AND status = 'verified' AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$country, REQUESTS_COUNT_WINDOW_DAYS]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return;

    $template_file = __DIR__ . '/../templates/email/country_opened.txt';
    if (is_file($template_file)) {
        $tpl = file_get_contents($template_file);
        foreach ($rows as $r) {
            $email = $r['email'];
            $body = str_replace(['{{site_name}}','{{country}}','{{register_url}}'], [SITE_NAME, $country, full_url(invite_url())], $tpl);
            $subject = sprintf('%s — %s için kayıt açıldı', SITE_NAME, $country);
            send_email($email, $subject, $body);
        }
    } else {
        foreach ($rows as $r) {
            $email = $r['email'];
            $subject = sprintf('%s — %s için kayıt açıldı', SITE_NAME, $country);
            $body = "Merhaba,\n\nTalebiniz değerlendirilmiş ve şu anda " . $country . " ülkesinden kayıtlar açılmıştır.\n\nKayıt olmak için: " . full_url(invite_url()) . "\n\nTeşekkürler,\n" . SITE_NAME;
            send_email($email, $subject, $body);
        }
    }
}

// Send notification email to all platform admins
function notify_platform_admins($subject, $body) {
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        error_log('[NOTIFY] MAIL_ENABLED false, skipping admin notification: ' . $subject);
        return false;
    }

    // Admins are users with role=admin or superadmin role key via RBAC.
    $stmt = query("SELECT DISTINCT u.id, u.username, u.email FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email != ''
          AND (u.role = 'admin' OR r.`key` = 'superadmin')");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($admins)) {
        error_log('[NOTIFY] No platform admins found for: ' . $subject);
        return false;
    }

    $sent = 0;
    foreach ($admins as $admin) {
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (send_email($admin['email'], $subject, $body)) {
            $sent++;
        } else {
            error_log('[NOTIFY] Failed to send admin email to: ' . $admin['email']);
        }
    }

    log_admin_action('notify_platform_admins', 'Subject: ' . substr($subject, 0, 200) . ' | sent=' . $sent, null);
    return $sent > 0;
}

function notify_platform_admins_about_district_online_request($district_id, $district_name, $requester_username, $requester_id = null, $reason = '', $request_id = null) {
    $subject = sprintf('[%s] %s için çevrimiçi erişim talebi', SITE_NAME, $district_name ?: 'Bölge');
    $link = full_url(BASE_PATH . '/admin/districts.php');
    $body = "Merhaba Yönetici,\n\n";
    $body .= "Bölge: " . ($district_name ?: "#$district_id") . "\n";
    if ($request_id) {
        $body .= "Talep ID: " . $request_id . "\n";
    }
    $body .= "Talep Eden: " . $requester_username . " (ID: " . ($requester_id ?: 'N/A') . ")\n";
    $body .= "Sebep: " . ($reason ?: 'Belirtilmedi') . "\n\n";
    $body .= "Lütfen aşağıdaki bağlantıyı ziyaret ederek isteği onaylayın/reddedin:\n" . $link . "\n\n";
    $body .= "Teşekkürler,\n" . SITE_NAME;

    return notify_platform_admins($subject, $body);
}


function auto_open_countries_check() {
    ensure_signup_requests_table();
    // For each country not open, check counts
    $stmt = query("SELECT country_code, COUNT(*) as cnt FROM signup_requests WHERE status = 'verified' AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY country_code", [REQUESTS_COUNT_WINDOW_DAYS]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $country = $r['country_code'];
        $cnt = (int)$r['cnt'];
        if ($cnt >= REQUESTS_AUTO_OPEN_THRESHOLD && !is_country_open($country)) {
            if (REQUESTS_AUTO_OPEN) {
                if (open_country($country, null, true)) {
                    notify_country_opened($country);
                }
            } else {
                // Notify admins for review
                log_admin_action('country_threshold', 'Country ' . $country . ' reached threshold: ' . $cnt, null);
            }
        }
    }
}

function get_request_counts_by_country($limit = 100) {
    ensure_signup_requests_table();
    $stmt = query("SELECT country_code, SUM(status='verified') as verified_count, SUM(status='pending') as pending_count FROM signup_requests GROUP BY country_code ORDER BY verified_count DESC LIMIT ?", [$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all approved words
function get_approved_words() {
    $stmt = query("SELECT aw.*, u.username as approved_by_name 
                   FROM approved_words aw 
                   LEFT JOIN users u ON aw.approved_by = u.id 
                   ORDER BY aw.approved_at DESC");
    return $stmt->fetchAll();
}

// Delete approved word
function delete_approved_word($id) {
    query("DELETE FROM approved_words WHERE id = ?", [$id]);
}

// Get pending review posts
function get_pending_posts($limit = 50) {
    $stmt = query("SELECT p.*, u.username 
                   FROM posts p 
                   JOIN users u ON p.user_id = u.id 
                   WHERE p.review_status = 'pending' 
                   AND p.deleted_at IS NULL 
                   ORDER BY p.created_at DESC 
                   LIMIT ?", [$limit]);
    return $stmt->fetchAll();
}

// Approve post and optionally add words to whitelist
function approve_post_review($post_id, $admin_id, $words_to_approve = []) {
    // Update post status
    query("UPDATE posts SET review_status = 'approved' WHERE id = ?", [$post_id]);
    
    // Add words to whitelist
    foreach ($words_to_approve as $word) {
        approve_word($word, $admin_id);
    }
    
    return true;
}

/* UNUSED_START post_validation_helpers
function validate_post($content) {
    if (strlen($content) > MAX_POST_LENGTH || !filter_bad_words($content)) {
        return false;
    }
    return true;
}

function is_valid_emoji($char) {
    return preg_match('/^\p{So}$/u', $char);
}
UNUSED_END post_validation_helpers */

// Get user by ID
function get_user($user_id) {
    // use NULL AS event_code so code runs even if DB column isn't yet migrated
    $stmt = query("SELECT id, username, bio, role, is_premium, premium_until, suspended_until, NULL AS event_code, created_at, email, notify_by_email, notify_on_mention, notify_on_reply, notify_on_report, notify_on_system, birthday, is_approved, is_online, last_activity FROM users WHERE id = ? AND deleted_at IS NULL", [$user_id]);
    return $stmt->fetch();
}

// Get user by username
function get_user_by_username($username) {
    ensure_user_slug_column();
    try {
        $stmt = query("SELECT id, username, bio, role, is_premium, premium_until, suspended_until, NULL AS event_code, created_at, email, notify_by_email, notify_on_mention, notify_on_reply, notify_on_report, notify_on_system, birthday, is_approved, is_online, last_activity, slug FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
        return $stmt->fetch();
    } catch (Exception $e) {
        // slug column may be missing in old schema; ignore and return basic user without slug
        error_log('get_user_by_username slug fallback: ' . $e->getMessage());
        try {
            $stmt = query("SELECT id, username, bio, role, is_premium, premium_until, suspended_until, NULL AS event_code, created_at, email, notify_by_email, notify_on_mention, notify_on_reply, notify_on_report, notify_on_system, birthday, is_approved, is_online, last_activity FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
            return $stmt->fetch();
        } catch (Exception $_) {
            error_log('get_user_by_username fallback error: ' . $_->getMessage());
            return false;
        }
    }
}

function ensure_user_slug_column() {
    try {
        $col = query("SHOW COLUMNS FROM users LIKE 'slug'");
        if (!$col->fetch()) {
            query("ALTER TABLE users ADD COLUMN `slug` VARCHAR(255) NULL");
        }
        $idx = query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_slug'");
        if (!$idx->fetch()) {
            query("CREATE UNIQUE INDEX idx_users_slug ON users (slug)");
        }
    } catch (Exception $e) {
        error_log('[USERS] ensure_user_slug_column error: ' . $e->getMessage());
    }
}

function generate_username_slug($username) {
    $slug = trim(mb_strtolower($username, 'UTF-8'));
    $trans = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'Á' => 'a','À'=>'a','Â'=>'a','Ä'=>'a','á'=>'a','à'=>'a','â'=>'a','ä'=>'a',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Ô'=>'o','Ö'=>'o','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ç'=>'c','Ğ'=>'g','Ş'=>'s'
    ];
    $slug = strtr($slug, $trans);
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'user-' . bin2hex(random_bytes(3));
    }
    return $slug;
}

function get_user_by_slug($slug) {
    ensure_user_slug_column();
    $slug_normalized = mb_strtolower(trim($slug), 'UTF-8');

    $stmt = query("SELECT id, username, bio, role, is_premium, premium_until, suspended_until, NULL AS event_code, created_at, email, notify_by_email, notify_on_mention, notify_on_reply, notify_on_report, notify_on_system, birthday, is_approved, is_online, last_activity, slug FROM users WHERE LOWER(slug) = ? AND deleted_at IS NULL", [$slug_normalized]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    // fallback: compare generated slug against username for existing users
    $stmt2 = query("SELECT id, username, bio, role, is_premium, premium_until, suspended_until, NULL AS event_code, created_at, email, notify_by_email, notify_on_mention, notify_on_reply, notify_on_report, notify_on_system, birthday, is_approved, is_online, last_activity, slug FROM users WHERE deleted_at IS NULL");
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (generate_username_slug($row['username']) === $slug_normalized) {
            return $row;
        }
    }
    return false;
}

function update_user_slug($user_id, $username) {
    ensure_user_slug_column();
    $slug = generate_username_slug($username);
    try {
        query("UPDATE users SET slug = ? WHERE id = ?", [$slug, $user_id]);
    } catch (Exception $e) {
        // If collision, add random suffix until unique
        for ($i = 1; $i <= 5; $i++) {
            $testSlug = $slug . '-' . $i;
            try {
                query("UPDATE users SET slug = ? WHERE id = ?", [$testSlug, $user_id]);
                return $testSlug;
            } catch (Exception $x) {
                error_log('generate_slug retry collision: ' . $x->getMessage());
                continue;
            }
        }
        error_log('[USERS] update_user_slug collision: ' . $e->getMessage());
        return null;
    }
    return $slug;
}

// Premium: Check if user has active premium
function is_user_premium($user_id) {
    $user = get_user($user_id);
    if (!$user) return false;

    // Admin users should have access to premium features by default
    if (!empty($user['role']) && $user['role'] === 'admin') {
        return true;
    }

    if (!$user['is_premium']) return false;

    // Check if premium hasn't expired
    if ($user['premium_until'] && strtotime($user['premium_until']) > time()) {
        return true;
    }

    // Check for lifetime premium (premium_until is NULL)
    if ($user['is_premium'] && !$user['premium_until']) {
        return true;
    }

    // Premium expired, remove status
    if ($user['premium_until'] && strtotime($user['premium_until']) <= time()) {
        query("UPDATE users SET is_premium = 0 WHERE id = ?", [$user_id]);
        return false;
    }

    return false;
}

// Premium: Get premium setting
function get_premium_setting($key, $default = null) {
    $stmt = query("SELECT setting_value FROM premium_settings WHERE setting_key = ?", [$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}

// Premium: Get user's custom badge
function get_user_custom_badge($user_id) {
    // table stores `status` rather than boolean; treat 'approved' as permitted
    $stmt = query("SELECT badge_text, badge_color FROM user_custom_badges WHERE user_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$user_id]);
    return $stmt->fetch();
}

// Event codes for premium users
function generate_event_code_string($length = 6) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // exclude ambiguous characters
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function get_or_create_event_code($user_id) {
    // If the DB hasn't been migrated yet, avoid queries referencing `event_code`.
    try {
        $col_check = query("SHOW COLUMNS FROM users LIKE 'event_code'")->fetch();
        if (!$col_check) return '';
    } catch (Exception $e) {
        error_log('get_or_create_event_code column check failed: ' . $e->getMessage());
        return '';
    }

    $stmt = query("SELECT event_code FROM users WHERE id = ? LIMIT 1", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['event_code'])) return $row['event_code'];

    // generate and persist a unique code
    $tries = 0;
    do {
        $code = generate_event_code_string(6);
        $exists = query("SELECT id FROM users WHERE event_code = ? LIMIT 1", [$code])->fetch();
        $tries++;
        if ($tries > 20) throw new Exception('Unable to generate unique event code');
    } while ($exists);

    query("UPDATE users SET event_code = ? WHERE id = ?", [$code, $user_id]);
    return $code;
}

function regenerate_event_code($user_id) {
    // If column missing, do nothing and return empty string
    try {
        $col_check = query("SHOW COLUMNS FROM users LIKE 'event_code'")->fetch();
        if (!$col_check) return '';
    } catch (Exception $e) {
        error_log('regenerate_event_code column check error: ' . $e->getMessage());
        return '';
    }

    // generate a new unique code and overwrite
    $tries = 0;
    do {
        $code = generate_event_code_string(6);
        $exists = query("SELECT id FROM users WHERE event_code = ? LIMIT 1", [$code])->fetch();
        $tries++;
        if ($tries > 20) throw new Exception('Unable to generate unique event code');
    } while ($exists);

    query("UPDATE users SET event_code = ? WHERE id = ?", [$code, $user_id]);
    return $code;
}

// Session-backed draft helpers
function save_draft($user_id, $content) {
    if (!$user_id) return false;
    if (!isset($_SESSION)) session_start();
    $_SESSION['drafts'][$user_id] = $content;
    return true;
}

function get_draft($user_id) {
    if (!$user_id) return '';
    if (!isset($_SESSION)) session_start();
    return $_SESSION['drafts'][$user_id] ?? '';
}

/**
 * Insert a tag into a content draft in a best-effort way (no-JS support).
 * - If draft is empty, returns a string starting with the tag.
 * - Avoids duplicating an existing tag.
 * - Appends the tag with a leading space if needed.
 */
function insert_tag_into_text($draft, $tag_text) {
    $tag = trim((string)$tag_text);
    $tag = ltrim($tag, '#');
    // Allow unicode letters/numbers and common tag chars
    $tag = preg_replace('/[^\p{L}\p{N}_-]/u', '', $tag);
    if ($tag === '') return $draft;

    if (trim($draft) === '') {
        return '#' . $tag . ' ';
    }

    // Don't duplicate an existing tag (word-boundary aware)
    if (preg_match('/(^|\s)#' . preg_quote($tag, '/') . '(\b|$)/u', $draft)) {
        return $draft;
    }

    $draft = rtrim($draft);
    return $draft . ' #' . $tag . ' ';
}

/**
 * Insert a type snippet (spoiler/link/code) into a draft or append it
 * (keeps server-side insert helpers working without JS)
 */
function insert_type_or_append_to_draft($user_id, $insert_type, $fields = []) {
    if (!$user_id) return false;

    // Prefer current user content from the submitted form so we don't discard what
    // the user has typed when they click an insert helper button.
    $draft = isset($fields['content']) ? trim($fields['content']) : get_draft($user_id);

    if ($insert_type === 'spoiler') {
        $label = 'Ekstra';
        $inner = trim($fields['spoiler_text'] ?? 'Gizli içerik');
        if ($inner === '') $inner = 'Gizli içerik';
        $draft .= "\n[ekstra=" . $label . "]" . $inner . "[/ekstra]\n";
    } elseif ($insert_type === 'kod') {
        $lang = preg_replace('/[^A-Za-z0-9_+-]/', '', $fields['code_lang'] ?? '');
        $code = $fields['code_text'] ?? '...kod buraya...';
        $draft .= "\n[kod" . ($lang ? "=" . $lang : '') . "]" . $code . "[/kod]\n";
    } elseif ($insert_type === 'link') {
        $url = trim($fields['link_url'] ?? 'https://example.com');
        $text = trim($fields['link_text'] ?? 'link metni');
        if ($url === '') $url = 'https://example.com';
        $draft .= " [link url=\"" . $url . "\"]" . $text . "[/link] ";
    }

    save_draft($user_id, $draft);
    return true;
}

/**
 * Get top tags but exclude tags that appear only in private groups.
 * Include recent public group posts as well as public posts.
 */
function get_top_tags($limit = 10) {
    // Count tags from recent posts and public group posts
    $postRows = [];
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT content FROM posts WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 1000");
        $stmt->execute();
        $postRows = array_merge($postRows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('get_top_tags posts query error: ' . $e->getMessage());
    }

    // Include public group posts (join with groups_table where is_private = 0)
    try {
        $stmt = $pdo->prepare("SELECT gp.content FROM group_posts gp JOIN groups_table g ON gp.group_id = g.id WHERE g.is_private = 0 ORDER BY gp.created_at DESC LIMIT 1000");
        $stmt->execute();
        $postRows = array_merge($postRows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('get_top_tags group posts query error: ' . $e->getMessage());
    }

    $postCounts = [];
    foreach ($postRows as $r) {
        $tags = extract_hashtags_from_text($r['content'] ?? '');
        foreach (array_unique($tags) as $t) {
            if ($t === '') continue;
            $postCounts[$t] = ($postCounts[$t] ?? 0) + 1;
        }
    }

    // Click counts
    ensure_tag_clicks_table();
    $clickCounts = [];
    try {
        $stmt = query("SELECT tag, click_count FROM tag_clicks ORDER BY click_count DESC LIMIT 200");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clickCounts[$row['tag']] = (int)$row['click_count'];
        }
    } catch (Exception $e) {
        error_log('get_top_tags tag_clicks error: ' . $e->getMessage());
    }

    // Score = post_count + 2 * click_count
    $scores = [];
    foreach ($postCounts as $t => $c) {
        $scores[$t] = ($scores[$t] ?? 0) + $c;
    }
    foreach ($clickCounts as $t => $c) {
        $scores[$t] = ($scores[$t] ?? 0) + 2 * $c;
    }
    arsort($scores);
    $result = [];
    foreach (array_slice(array_keys($scores), 0, $limit) as $t) {
        $result[] = [
            'tag' => $t,
            'post_count' => (int)($postCounts[$t] ?? 0),
            'click_count' => (int)($clickCounts[$t] ?? 0),
            'score' => (int)($scores[$t] ?? 0),
        ];
    }
    return $result;
}

/**
 * Get trending tags within a specific group (from group_posts only)
 */
function get_trending_tags_for_group($group_id, $limit = 10) {
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT gp.content, gp.created_at,
            (SELECT COUNT(*) FROM group_post_likes l WHERE l.post_id = gp.id) as likes_count,
            (SELECT COUNT(*) FROM group_post_comments c WHERE c.post_id = gp.id) as comments_count
            FROM group_posts gp
            WHERE gp.group_id = ?
            ORDER BY gp.created_at DESC
            LIMIT 1000");
        $stmt->execute([$group_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $acc = [];
        foreach ($rows as $p) {
            $content = $p['content'] ?? '';
            $likes = (int)($p['likes_count'] ?? 0);
            $comments = (int)($p['comments_count'] ?? 0);
            $created_at = $p['created_at'] ?? null;

            $tags = extract_hashtags_from_text($content);
            if (empty($tags)) continue;
            $tags = array_unique($tags);
            foreach ($tags as $t) {
                if ($t === '') continue;
                if (!isset($acc[$t])) {
                    $acc[$t] = ['post_count' => 0, 'total_likes' => 0, 'total_comments' => 0, 'last_post_date' => null];
                }
                $acc[$t]['post_count'] += 1;
                $acc[$t]['total_likes'] += $likes;
                $acc[$t]['total_comments'] += $comments;
                if (is_null($acc[$t]['last_post_date']) || strtotime($created_at) > strtotime($acc[$t]['last_post_date'])) {
                    $acc[$t]['last_post_date'] = $created_at;
                }
            }
        }

        $rows = [];
        $now = new DateTime();
        foreach ($acc as $tag => $meta) {
            $last = $meta['last_post_date'] ? new DateTime($meta['last_post_date']) : $now;
            $days = max(0, (int)$now->diff($last)->format('%a'));
            $relevance = ($meta['total_likes'] * 0.5) + ($meta['total_comments'] * 1.0) - ($days * 0.1);
            $rows[] = [
                'tag' => '#' . $tag,
                'post_count' => $meta['post_count'],
                'relevance_score' => $relevance
            ];
        }
        usort($rows, function($a, $b) {
            if ($a['relevance_score'] == $b['relevance_score']) return $b['post_count'] <=> $a['post_count'];
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
        return array_slice($rows, 0, $limit);
    } catch (Exception $e) {
        error_log('get_trending_tags error: ' . $e->getMessage());
        return [];
    }
}


// Convert badge color hex to CSS class suffix (normalize inputs)
function badge_color_to_class($badge_color) {
    if (empty($badge_color)) return 'green';
    $c = trim(strtolower($badge_color));
    if ($c !== '' && $c[0] !== '#') $c = '#' . $c;
    $map = [
        '#2ecc71' => 'green',
        '#3498db' => 'blue',
        '#e74c3c' => 'red',
        '#f39c12' => 'orange',
        '#9b59b6' => 'purple',
        '#1abc9c' => 'turquoise',
        '#34495e' => 'darkgray',
        '#e67e22' => 'orangered'
    ];
    return $map[$c] ?? 'green';
}

// Render simple rich text markers into safe HTML
function render_rich_text($text) {
    if ($text === null) return '';
    // Escape first but preserve quotes so tag attributes like url="..." remain matchable by regex.
    // Individual callbacks will HTML-escape user content as needed.
    $t = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

    // Ekstra/Spoiler (supports optional label: [ekstra=Label]content[/ekstra] or [spoiler=Label])
    // Render inner content recursively so nested BBCode (like [link]) is parsed.
    $t = preg_replace_callback('/\[(?:spoiler|ekstra)(?:=([^\]]+))?\](.*?)\[\/(?:spoiler|ekstra)\]/is', function($m){
        $label = isset($m[1]) && trim($m[1]) !== '' ? htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') : 'Ekstra';
        // Decode any entities that were escaped earlier, then render inner BBCode recursively
        $inner_raw = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inner = render_rich_text($inner_raw);
        return '<details class="spoiler"><summary>' . $label . '</summary><div class="spoiler-content">' . $inner . '</div></details>';
    }, $t);

    // Bold / Italic / Underline
    $t = preg_replace_callback('/\[(b|i|u)\](.*?)\[\/\1\]/is', function($m){
        $tag = strtolower($m[1]);
        $inner = nl2br(htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8'));
        $tag = $tag === 'b' ? 'strong' : ($tag === 'i' ? 'em' : 'u');
        return '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }, $t);

    // Headings (h1-h3)
    $t = preg_replace_callback('/\[(h[1-3])\](.*?)\[\/\1\]/is', function($m){
        $tag = strtolower($m[1]);
        $inner = nl2br(htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8'));
        return '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }, $t);

    // Replace @mentions with profile links (safe inside the escaped HTML)
    $t = preg_replace_callback('/@([A-Za-z0-9_-]+)/u', function($m){
        $username = $m[1];
        $url = profile_url($username);
        $display = htmlspecialchars('@' . $username, ENT_QUOTES, 'UTF-8');
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $t);

    // Code / KOD (optional lang) - accept [code] or [kod]
    $t = preg_replace_callback('/\[(?:code|kod)(?:=([A-Za-z0-9_+-]+))?\](.*?)\[\/(?:code|kod)\]/is', function($m){
        $lang = $m[1] ? ' language-' . htmlspecialchars($m[1], ENT_QUOTES) : '';
        $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        return '<pre class="code-block"><code class="' . $lang . '">' . $code . '</code></pre>';
    }, $t);

    // Removed BK/BKZ tag renderer: hashtags (#) are used for topic links instead.

    // Link - route external links through outbound confirmation for safety
    $t = preg_replace_callback('/\[link\s+url="(.+?)(?:"|&quot;|&amp;quot;)\](.*?)\[\/link\]/is', function($m){
        // Allow URLs to be pasted from HTML-escaped sources (e.g. ending in &quot;)
        $url = trim($m[1]);
        // Decode entities multiple times: some input can arrive already escaped (e.g. &amp;quot;)
        for ($i = 0; $i < 3; $i++) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Remove trailing quote entities, backslash, or whitespace
        $url = preg_replace('/(("|\'|\\|&quot;|&amp;quot;)+)$/i', '', $url);
        $url = rtrim($url, "\"'\t\n\r");
        // Debug log
        // file_put_contents('/tmp/bbcode_url_debug.txt', $url."\n", FILE_APPEND);
        if (!preg_match('#^https?://#i', $url)) return htmlspecialchars($m[2], ENT_QUOTES);
        $text = htmlspecialchars($m[2], ENT_QUOTES);
        $out = BASE_PATH . '/outbound.php?u=' . rawurlencode($url);
        return '<a class="post-link" href="' . htmlspecialchars($out, ENT_QUOTES) . '" rel="noopener noreferrer nofollow" target="_blank">' . $text . '</a>';
    }, $t);

    // Auto-link plain urls - also route via outbound
    $t = preg_replace_callback('@(https?://[^\s<]+)@i', function($m){
        $u = $m[1];
        $out = BASE_PATH . '/outbound.php?u=' . rawurlencode($u);
        $display = htmlspecialchars($u, ENT_QUOTES);
        return '<a href="' . htmlspecialchars($out, ENT_QUOTES) . '" rel="noopener noreferrer nofollow" target="_blank">' . $display . '</a>';
    }, $t);

    // Finally, convert newlines to <br> except inside code blocks (already handled)
    $t = preg_replace_callback('#<pre.*?>.*?</pre>#is', function($m){ return str_replace("\n", '__NEWLINE__', $m[0]); }, $t);
    $t = nl2br($t);
    $t = str_replace('__NEWLINE__', "\n", $t);

    return $t;
}

// Linkify @mentions to profile URLs
function linkify_mentions($text) {
    // Escape first to avoid XSS, then replace @username with a safe anchor
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $result = preg_replace_callback('/@([A-Za-z0-9_-]+)/u', function($m) {
        $username = $m[1];
        $url = profile_url($username);
        $display = htmlspecialchars('@' . $username, ENT_QUOTES, 'UTF-8');
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $escaped);
    return $result;
}

// Linkify plain text: mentions and hashtags (handles Unicode hashtags)
function linkify_text($text) {
    // Escape once to avoid XSS, then replace patterns in the escaped string
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Mentions (support Unicode names and hyphens; optional one space-separated second term)
    $escaped = preg_replace_callback('/@([\p{L}\p{N}_-]+(?: [\p{L}\p{N}_-]+)?)/u', function($m) {
        $username = trim($m[1]);
        $url = profile_url($username);
        $u = get_user_by_username($username) ?: get_user_by_slug($username);
        if (!$u && strpos($username, ' ') !== false) {
            list($first, $rest) = explode(' ', $username, 2);
            $u = get_user_by_username($first) ?: get_user_by_slug($first);
            if ($u) {
                return '<a href="' . htmlspecialchars(profile_url($first), ENT_QUOTES, 'UTF-8') . '">@' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . '</a> ' . htmlspecialchars($rest, ENT_QUOTES, 'UTF-8');
            }
        }
        if ($u) {
            $url = profile_url($u['username']);
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">@' . htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        return htmlspecialchars('@' . $username, ENT_QUOTES, 'UTF-8');
    }, $escaped);

    // Hashtags (Unicode-aware)
    $escaped = preg_replace_callback('/#([\p{L}\p{N}_-]+)/u', function($m) {
        $raw = $m[1];
        $tag = rawurlencode($raw);
        $display = htmlspecialchars('#' . $raw, ENT_QUOTES, 'UTF-8');
        $url = BASE_PATH . '/ara?tag=' . $tag;
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $escaped);

    return $escaped;
}

// Premium: Get max post length for user
function get_user_post_limit($user_id) {
    if (is_user_premium($user_id)) {
        return 0; // 0 means unlimited for premium users
    }
    return MAX_POST_LENGTH;
}

// Get number of posts (non-deleted) by user
// NOTE: includes top-level posts and replies/comments. Use get_user_top_level_post_count for rookie limit/progress.
function get_user_post_count($user_id) {
    $stmt = query("SELECT COUNT(*) as c FROM posts WHERE user_id = ? AND deleted_at IS NULL", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['c'] : 0;
}

// Get number of top-level posts (non-deleted) by user, used for rookie auto-approval limit and progress.
function get_user_top_level_post_count($user_id) {
    $stmt = query("SELECT COUNT(*) as c FROM posts WHERE user_id = ? AND parent_id IS NULL AND deleted_at IS NULL", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['c'] : 0;
}

// Premium: Edit post (premium only - unlimited time)
function edit_post($user_id, $post_id, $new_content) {
    $post = get_post($post_id);
    if (!$post) {
            error_log("edit_post failed: post_not_found user={$user_id} post={$post_id}");
            return ['error' => 'post_not_found'];
        }
        
        // Check ownership
        if ($post['user_id'] != $user_id) {
            error_log("edit_post failed: not_owner user={$user_id} post_owner={$post['user_id']} post={$post_id}");
            return ['error' => 'not_owner'];
        }

        // Explicit rookie block: disallow role='rookie' from editing posts UNLESS the user is Premium
        $usercheck = get_user($user_id);
        if ($usercheck && isset($usercheck['role']) && $usercheck['role'] === 'rookie' && !is_user_premium($user_id)) {
            error_log("edit_post failed: rookie_restricted user={$user_id} role=rookie post={$post_id}");
            return ['error' => 'rookie_restricted'];
        }

        // Only premium users (or admins) may edit posts
        if (!is_user_premium($user_id) && !is_admin()) {
            // Log more context to help debug premium status
            $up = get_user($user_id);
            $premium_info = json_encode(['is_premium' => $up['is_premium'] ?? null, 'premium_until' => $up['premium_until'] ?? null]);
            error_log("edit_post failed: premium_required user={$user_id} post={$post_id} premium_info={$premium_info}");
            return ['error' => 'premium_required'];
        }

        // Try to record an edit snapshot using the newer schema if available.
        // If `save_post_edit` is not present (older installs), fall back to the legacy `original_content` insert.
        if (function_exists('save_post_edit')) {
            try {
                // Pass editor id when available; may be null for system edits.
                save_post_edit($post_id, $user_id ?? null, $post['content'], $new_content ?? null);
            } catch (Exception $e) {
                error_log('post_edits save_post_edit failed: ' . $e->getMessage());
                try { query("INSERT INTO post_edits (post_id, original_content) VALUES (?, ?)", [$post_id, $post['content']]); } catch (Exception $_) { error_log('post_edits fallback insert error: ' . $_->getMessage()); }
            }
        } else {
            try { query("INSERT INTO post_edits (post_id, original_content) VALUES (?, ?)", [$post_id, $post['content']]); } catch (Exception $_) { error_log('post_edits direct insert error: ' . $_->getMessage()); }
        }
    
    // Censor bad words in new content
    $censored = censor_bad_words($new_content);
    $new_content = $censored['clean'];
    
    // Update post
    query("UPDATE posts SET content = ?, has_censored_words = ? WHERE id = ?", [$new_content, $censored['has_bad_words'] ? 1 : 0, $post_id]);
    
    return ['success' => true, 'has_bad_words' => $censored['has_bad_words']];
}

// Premium: Check if post can be edited
function can_edit_post($user_id, $post_id) {
    $post = get_post($post_id);
    if (!$post || $post['user_id'] != $user_id) {
        return false;
    }
    
    // Only premium users (or admins) may edit posts
    if (is_admin() || is_user_premium($user_id)) {
        return true;
    }
    return false;
} 

// Create a new post
function create_post($user_id, $content, $parent_id = null) {
    if (empty(trim($content))) {
        return false;
    }
    
    $content = trim($content);

    try {
        // Check suspended status first
        $user = get_user($user_id);
        if ($user && !empty($user['suspended_until']) && strtotime($user['suspended_until']) > time()) {
            return [ 'error' => 'suspended', 'until' => $user['suspended_until'] ];
        }
        
        // Check if user is approved; previously rookies were prevented from posting after 10
        // but the requirement now is to allow unlimited posts (they will simply be unapproved
        // and hidden from the main feed).  We keep the query in case other logic needs it.
        $user_check = query("SELECT is_approved, role FROM users WHERE id = ?", [$user_id])->fetch(PDO::FETCH_ASSOC);
        // no error returned here any more; approval flag handles visibility later
    
    // Get user's post limit (premium gets higher limit)
    $user_limit = get_user_post_limit($user_id);

    // Capture previous top-level post count for module triggers (detect first top-level post)
    $pre_top_level_count = null;
    try {
        $pre_top_level_count = get_user_top_level_post_count($user_id);
    } catch (Throwable $_t) {
        error_log('pre_top_level_count error: ' . $_t->getMessage());
        $pre_top_level_count = null;
    }
    
    // If content exceeds limit, enforce post-length policy.
    // Use a normalized "visible" version (strip markup) so link markup doesn't unfairly inflate length.
    $visible_content = trim(strip_tags(render_rich_text($content)));

    $chunks = [];
    if ($user_limit > 0 && mb_strlen($visible_content, 'UTF-8') > $user_limit) {
        // Non-premium users are not allowed to post longer than their limit
        $premium_url = BASE_PATH . '/premium.php';
        return ['error' => 'limit_exceeded', 'message' => sprintf(t('post_length_error_premium'), $user_limit, $user_limit, $premium_url)];
    } else {
        // Premium users (limit=0) or content within limit - post as single chunk
        $chunks[] = $content;
    }
    
    // Create main post with first chunk
    $first_chunk = $chunks[0];
    
    // Censor bad words
    $censored = censor_bad_words($first_chunk);
    $first_chunk = $censored['clean'];
    $has_bad_words = $censored['has_bad_words'];
    
    // Check for suspicious content (bypass attempts)
    $suspicious_check = check_suspicious_content($first_chunk);
    $review_status = NULL;
    
    if ($suspicious_check['suspicious']) {
        // Flag for admin review
        $review_status = 'pending';
    }

    // Determine approval for rookies: allow first N top-level posts automatically
    $approved = 1;
    if ($user && $user['role'] === 'rookie') {
        $count = $pre_top_level_count !== null ? $pre_top_level_count : get_user_top_level_post_count($user_id);
        if ($count >= (int)ROOKIE_AUTO_APPROVE_POST_COUNT) {
            $approved = 0; // needs admin approval
        }
    }

    query("INSERT INTO posts (user_id, content, parent_id, approved, has_censored_words, review_status) VALUES (?, ?, ?, ?, ?, ?)", 
          [$user_id, $first_chunk, $parent_id, $approved, $has_bad_words ? 1 : 0, $review_status]);
    $inserted_id = insert_id();
    
    // Only update replies_count and notify if the reply was auto-approved
    if ($parent_id && $approved) {
        query("UPDATE posts SET replies_count = replies_count + 1 WHERE id = ?", [$parent_id]);
        
        // Create notification for reply
        $post = get_post($parent_id);
        if ($post && $post['user_id'] != $user_id) {
            create_notification($post['user_id'], 'reply', $user_id, $parent_id);
        }
    }

    // Notify mentioned users if the post was auto-approved
    if ($approved) {
        $mentioned = get_mentions($first_chunk);
        foreach ($mentioned as $m) {
            if ($m['id'] != $user_id) {
                create_notification($m['id'], 'mention', $user_id, $inserted_id);
            }
        }
    }
    
    // Create continuation posts as replies
    if (count($chunks) > 1) {
        for ($i = 1; $i < count($chunks); $i++) {
            $chunk_content = $chunks[$i];
            
            // Censor bad words in continuation
            $censored_chunk = censor_bad_words($chunk_content);
            $chunk_content = $censored_chunk['clean'];
            if ($censored_chunk['has_bad_words']) {
                $has_bad_words = true;
            }
            
            // Check suspicious content in continuation
            $chunk_suspicious = check_suspicious_content($chunk_content);
            $chunk_review_status = NULL;
            if ($chunk_suspicious['suspicious']) {
                $chunk_review_status = 'pending';
            }
            
            // All continuations reply to the main post (not chained)
            query("INSERT INTO posts (user_id, content, parent_id, approved, has_censored_words, review_status) VALUES (?, ?, ?, ?, ?, ?)", 
                  [$user_id, $chunk_content, $inserted_id, $approved, $censored_chunk['has_bad_words'] ? 1 : 0, $chunk_review_status]);
            
            // Update replies count for the main post
            if ($approved) {
                query("UPDATE posts SET replies_count = replies_count + 1 WHERE id = ?", [$inserted_id]);
            }
        }
    }
    
    // If this was the author's first top-level post, allow optional module to auto-like it
    if ($parent_id === null && $pre_top_level_count !== null && $pre_top_level_count == 0 && $approved) {
        // load module if available
        $modfile = __DIR__ . '/../modules/mevzuat_triggers.php';
        if (file_exists($modfile)) {
            require_once $modfile;
            if (function_exists('mevzuat_auto_like_first_post')) {
                try {
                    mevzuat_auto_like_first_post($inserted_id, $user_id);
                } catch (Throwable $_t) {
                    error_log('mevzuat_auto_like_first_post error: ' . $_t->getMessage());
                }
            }
        }
    }

    return [ 'id' => $inserted_id, 'approved' => (bool)$approved, 'has_bad_words' => $has_bad_words, 'chunks' => count($chunks) ];
    } catch (Throwable $e) {
        error_log('create_post exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
} 

// Helper to determine if a user is restricted from creating tests/polls (rookie block unless premium/admin)
function is_user_creation_restricted($user_id) {
    $u = get_user($user_id);
    if (!$u) return true; // unknown users restricted by default
    // If role is 'rookie' and not premium and not admin => restricted
    if (isset($u['role']) && $u['role'] === 'rookie' && !is_user_premium($user_id) && !is_admin()) {
        return true;
    }
    return false;
}

// History / compare helpers (backwards-compatible)
if (!function_exists('save_post_edit')) {
    function save_post_edit($post_id, $editor_id = null, $previous = null, $new = null) {
        $prev = mb_substr($previous ?? '', 0, 100 * 1024, 'UTF-8');
        $n = mb_substr($new ?? '', 0, 100 * 1024, 'UTF-8');
        try {
            // Try the new schema first (editor_id, previous_content, new_content)
            query("INSERT INTO post_edits (post_id, editor_id, previous_content, new_content, created_at) VALUES (?, ?, ?, ?, NOW())", [$post_id, $editor_id, $prev, $n]);
            return true;
        } catch (Exception $e) {
            // Fallback to legacy single-column schema if new columns don't exist
            error_log('save_post_edit new schema failed, trying legacy: ' . $e->getMessage());
            try {
                query("INSERT INTO post_edits (post_id, original_content) VALUES (?, ?)", [$post_id, $previous ?? '']);
                return true;
            } catch (Exception $_e) {
                error_log('save_post_edit fallback failed: ' . $_e->getMessage());
                return false;
            }
        }
    }
}

if (!function_exists('get_post_edits')) {
    function get_post_edits($post_id, $limit = 50) {
        // If new schema exists, created_at will be present and used; legacy rows will still be returned.
        try {
            $stmt = query("SELECT id, post_id, editor_id, created_at, previous_content, new_content, original_content FROM post_edits WHERE post_id = ? ORDER BY created_at DESC LIMIT ?", [$post_id, (int)$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback to legacy shape
            error_log('get_post_edits new schema failed, trying legacy: ' . $e->getMessage());
            try {
                $stmt = query("SELECT id, post_id, NULL AS editor_id, NULL AS created_at, NULL AS previous_content, NULL AS new_content, original_content FROM post_edits WHERE post_id = ? ORDER BY id DESC LIMIT ?", [$post_id, (int)$limit]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $_e) {
                error_log('get_post_edits failed: ' . $_e->getMessage());
                return [];
            }
        }
    }
}

if (!function_exists('get_post_edit')) {
    function get_post_edit($edit_id) {
        try {
            $stmt = query("SELECT * FROM post_edits WHERE id = ? LIMIT 1", [$edit_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (Exception $e) {
            error_log('get_post_edit error: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('render_diff_html')) {
    function render_diff_html($old, $new) {
        $old = trim((string)$old);
        $new = trim((string)$new);
        if ($old === '' && $new === '') return '';
        $a = $old === '' ? [] : preg_split('/\s+/', $old);
        $b = $new === '' ? [] : preg_split('/\s+/', $new);
        $n = count($a); $m = count($b);
        $dp = array_fill(0, $n+1, array_fill(0, $m+1, 0));
        for ($i = $n-1; $i >= 0; $i--) {
            for ($j = $m-1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) $dp[$i][$j] = $dp[$i+1][$j+1] + 1;
                else $dp[$i][$j] = max($dp[$i+1][$j], $dp[$i][$j+1]);
            }
        }
        $i = 0; $j = 0; $ops = [];
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $ops[] = ['op' => '=', 'text' => $a[$i]]; $i++; $j++; }
            elseif ($dp[$i+1][$j] >= $dp[$i][$j+1]) { $ops[] = ['op' => '-', 'text' => $a[$i]]; $i++; }
            else { $ops[] = ['op' => '+', 'text' => $b[$j]]; $j++; }
        }
        while ($i < $n) { $ops[] = ['op' => '-', 'text' => $a[$i++]]; }
        while ($j < $m) { $ops[] = ['op' => '+', 'text' => $b[$j++]]; }
        $out = '';
        foreach ($ops as $o) {
            if ($o['op'] === '=') $out .= ' ' . htmlspecialchars($o['text'], ENT_QUOTES, 'UTF-8');
            elseif ($o['op'] === '-') $out .= ' <del class="diff-removed">' . htmlspecialchars($o['text'], ENT_QUOTES, 'UTF-8') . '</del>';
            else $out .= ' <ins class="diff-added">' . htmlspecialchars($o['text'], ENT_QUOTES, 'UTF-8') . '</ins>';
        }
        return trim($out);
    }
}

if (!function_exists('render_diff_old_html')) {
    function render_diff_old_html($old, $new) {
        $old = trim((string)$old);
        $new = trim((string)$new);
        if ($old === '' && $new === '') return '';
        $a = $old === '' ? [] : preg_split('/\s+/', $old);
        $b = $new === '' ? [] : preg_split('/\s+/', $new);
        $n = count($a); $m = count($b);
        $dp = array_fill(0, $n+1, array_fill(0, $m+1, 0));
        for ($i = $n-1; $i >= 0; $i--) {
            for ($j = $m-1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) $dp[$i][$j] = $dp[$i+1][$j+1] + 1;
                else $dp[$i][$j] = max($dp[$i+1][$j], $dp[$i][$j+1]);
            }
        }
        $i = 0; $j = 0; $out = '';
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $out .= ' ' . htmlspecialchars($a[$i], ENT_QUOTES, 'UTF-8'); $i++; $j++; }
            elseif ($dp[$i+1][$j] >= $dp[$i][$j+1]) { $out .= ' <span class="diff-removed">' . htmlspecialchars($a[$i], ENT_QUOTES, 'UTF-8') . '</span>'; $i++; }
            else { $j++; }
        }
        while ($i < $n) { $out .= ' <span class="diff-removed">' . htmlspecialchars($a[$i++], ENT_QUOTES, 'UTF-8') . '</span>'; }
        return trim($out);
    }
}

if (!function_exists('render_diff_new_html')) {
    function render_diff_new_html($old, $new) {
        $old = trim((string)$old);
        $new = trim((string)$new);
        if ($old === '' && $new === '') return '';
        $a = $old === '' ? [] : preg_split('/\s+/', $old);
        $b = $new === '' ? [] : preg_split('/\s+/', $new);
        $n = count($a); $m = count($b);
        $dp = array_fill(0, $n+1, array_fill(0, $m+1, 0));
        for ($i = $n-1; $i >= 0; $i--) {
            for ($j = $m-1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) $dp[$i][$j] = $dp[$i+1][$j+1] + 1;
                else $dp[$i][$j] = max($dp[$i+1][$j], $dp[$i][$j+1]);
            }
        }
        $i = 0; $j = 0; $out = '';
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $out .= ' ' . htmlspecialchars($b[$j], ENT_QUOTES, 'UTF-8'); $i++; $j++; }
            elseif ($dp[$i+1][$j] >= $dp[$i][$j+1]) { $i++; }
            else { $out .= ' <span class="diff-added">' . htmlspecialchars($b[$j], ENT_QUOTES, 'UTF-8') . '</span>'; $j++; }
        }
        while ($j < $m) { $out .= ' <span class="diff-added">' . htmlspecialchars($b[$j++], ENT_QUOTES, 'UTF-8') . '</span>'; }
        return trim($out);
    }
}

// Helper to generate SEO slugs
function generate_slug($text) {
    $text = trim((string)$text);
    if ($text === '') return '';
    // transliterate to ascii where possible
    $trans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $trans = strtolower($trans);
    $trans = preg_replace('/[^a-z0-9]+/', '-', $trans);
    $trans = trim($trans, '-');
    $trans = substr($trans, 0, 200);
    if ($trans === '') $trans = 'item';
    return $trans;
}

// Polls: create, fetch and vote helpers
function create_poll($user_id, $title, $post_id = null, $group_post_id = null, $options = []) {
    // Prevent rookie users from creating polls (enforced server-side)
    if (is_user_creation_restricted($user_id)) {
        return ['error' => 'rookie_restricted'];
    }
    $title = trim($title ?? '');
    // Normalize options: keep only non-empty trimmed and unique, limit to 2..10
    $norm = [];
    foreach ($options as $o) {
        $t = trim((string)$o);
        if ($t === '') continue;
        $norm[] = $t;
    }
    $norm = array_values(array_unique($norm));
    if (count($norm) < 2) return ['error' => 'need_two_options'];
    if (count($norm) > 10) return ['error' => 'too_many_options'];

    $pdo = db_connect();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO polls (post_id, group_post_id, user_id, title) VALUES (?, ?, ?, ?)");
        $stmt->execute([$post_id, $group_post_id, $user_id, $title]);
        $poll_id = (int)$pdo->lastInsertId();
        $opt_stmt = $pdo->prepare("INSERT INTO poll_options (poll_id, text) VALUES (?, ?)");
        foreach ($norm as $opt) {
            $opt_stmt->execute([$poll_id, $opt]);
        }

        // Generate and save slug (non-unique slug is fine; id is used in URL)
        $slug = generate_slug($title);
        if ($slug === '') $slug = 'anket';
        $slug = $slug . '-' . $poll_id;
        if (column_exists('polls', 'slug')) {
            $pdo->prepare("UPDATE polls SET slug = ? WHERE id = ?")->execute([$slug, $poll_id]);
        } else {
            error_log('create_poll: slug column missing in polls table; slug not saved for poll ' . $poll_id);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('create_poll exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
    return ['id' => $poll_id, 'count' => count($norm), 'slug' => $slug];
}

function update_poll($user_id, $poll_id, $title, $content, $options = []) {
    $title = trim($title ?? '');

    // Normalize options
    $norm = [];
    foreach ($options as $o) {
        $t = trim((string)$o);
        if ($t === '') continue;
        $norm[] = $t;
    }
    $norm = array_values(array_unique($norm));
    if (count($norm) < 2) return ['error' => 'need_two_options'];
    if (count($norm) > 10) return ['error' => 'too_many_options'];

    $pdo = db_connect();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ? LIMIT 1");
        $stmt->execute([$poll_id]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) {
            $pdo->rollBack();
            return ['error' => 'not_found'];
        }

        if ((int)$poll['user_id'] !== (int)$user_id && !is_admin()) {
            $pdo->rollBack();
            return ['error' => 'forbidden'];
        }

        // If votes exist, disallow option update.
        $optStmt = $pdo->prepare("SELECT SUM(votes_count) as total_votes FROM poll_options WHERE poll_id = ?");
        $optStmt->execute([$poll_id]);
        $row = $optStmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['total_votes'] > 0) {
            $pdo->rollBack();
            return ['error' => 'has_votes'];
        }

        // Update simple fields
        $pdo->prepare("UPDATE polls SET title = ? WHERE id = ?")->execute([$title, $poll_id]);

        $slug = trim((string)($poll['slug'] ?? ''));
        if ($slug === '') {
            $slug = generate_slug($title);
            if ($slug === '') $slug = 'anket';
            $slug .= '-' . $poll_id;
            if (column_exists('polls', 'slug')) {
                $pdo->prepare("UPDATE polls SET slug = ? WHERE id = ?")->execute([$slug, $poll_id]);
            }
        }

        if (!empty($poll['post_id'])) {
            $pdo->prepare("UPDATE posts SET content = ? WHERE id = ?")->execute([$content, $poll['post_id']]);
        } elseif (!empty($poll['group_post_id'])) {
            $pdo->prepare("UPDATE group_posts SET content = ? WHERE id = ?")->execute([$content, $poll['group_post_id']]);
        }

        $pdo->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$poll_id]);
        $optInsert = $pdo->prepare("INSERT INTO poll_options (poll_id, text, votes_count) VALUES (?, ?, 0)");
        foreach ($norm as $optText) {
            $optInsert->execute([$poll_id, $optText]);
        }

        $pdo->commit();
        return ['id' => $poll_id, 'slug' => $slug];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('update_poll exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
}

function get_poll_for_post($post_id) {
    if (!$post_id) return null;
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT * FROM polls WHERE post_id = ? LIMIT 1");
        $stmt->execute([$post_id]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) return null;
        $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
        $opts->execute([$poll['id']]);
        $options = $opts->fetchAll(PDO::FETCH_ASSOC);
        $poll['options'] = $options;
        if (empty($options)) {
            error_log('get_poll_for_post: poll id ' . (int)$poll['id'] . ' has no options');
        }
        // user vote
        $user_id = get_current_user_id();
        $poll['user_vote'] = null;
        if ($user_id) {
            $v = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
            $v->execute([$poll['id'], $user_id]);
            $row = $v->fetch(PDO::FETCH_ASSOC);
            if ($row) $poll['user_vote'] = (int)$row['option_id'];
        }
        return $poll;
    } catch (PDOException $e) {
        // Table might not exist yet; fail gracefully until migration is run
        error_log('get_poll_for_post DB error: ' . $e->getMessage());
        return null;
    }
}

function get_poll_for_group_post($group_post_id) {
    if (!$group_post_id) return null;
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT * FROM polls WHERE group_post_id = ? LIMIT 1");
        $stmt->execute([$group_post_id]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) return null;
        $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
        $opts->execute([$poll['id']]);
        $options = $opts->fetchAll(PDO::FETCH_ASSOC);
        $poll['options'] = $options;
        $user_id = get_current_user_id();
        $poll['user_vote'] = null;
        if ($user_id) {
            $v = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
            $v->execute([$poll['id'], $user_id]);
            $row = $v->fetch(PDO::FETCH_ASSOC);
            if ($row) $poll['user_vote'] = (int)$row['option_id'];
        }
        return $poll;
    } catch (PDOException $e) {
        error_log('get_poll_for_group_post DB error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Batch-load polls for multiple post IDs in a single set of queries.
 * Returns an associative array keyed by post_id. Posts without polls will not appear in the result.
 * This replaces the N+1 pattern of calling get_poll_for_post() in a loop.
 */
function get_polls_for_posts(array $post_ids) {
    if (empty($post_ids)) return [];
    $pdo = db_connect();
    try {
        $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
        
        // 1. Fetch all polls for these posts
        $stmt = $pdo->prepare("SELECT * FROM polls WHERE post_id IN ($placeholders)");
        $stmt->execute(array_values($post_ids));
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($polls)) return [];
        
        // Index by poll ID and post_id
        $polls_by_id = [];
        $polls_by_post = [];
        foreach ($polls as &$poll) {
            $poll['options'] = [];
            $poll['user_vote'] = null;
            $polls_by_id[$poll['id']] = &$poll;
            $polls_by_post[$poll['post_id']] = &$poll;
        }
        unset($poll);
        
        $poll_ids = array_keys($polls_by_id);
        $poll_placeholders = implode(',', array_fill(0, count($poll_ids), '?'));
        
        // 2. Fetch all options for these polls
        $opts_stmt = $pdo->prepare("SELECT id, poll_id, text, votes_count FROM poll_options WHERE poll_id IN ($poll_placeholders) ORDER BY id ASC");
        $opts_stmt->execute($poll_ids);
        foreach ($opts_stmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            if (isset($polls_by_id[$opt['poll_id']])) {
                $polls_by_id[$opt['poll_id']]['options'][] = $opt;
            }
        }
        
        // 3. Fetch user votes (if logged in)
        $user_id = get_current_user_id();
        if ($user_id) {
            $votes_stmt = $pdo->prepare("SELECT poll_id, option_id FROM poll_votes WHERE poll_id IN ($poll_placeholders) AND user_id = ?");
            $votes_stmt->execute(array_merge($poll_ids, [$user_id]));
            foreach ($votes_stmt->fetchAll(PDO::FETCH_ASSOC) as $vote) {
                if (isset($polls_by_id[$vote['poll_id']])) {
                    $polls_by_id[$vote['poll_id']]['user_vote'] = (int)$vote['option_id'];
                }
            }
        }
        
        return $polls_by_post;
    } catch (PDOException $e) {
        error_log('get_polls_for_posts DB error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Batch-load tests for multiple post IDs in a single set of queries.
 * Returns an associative array keyed by post_id. Posts without tests will not appear in the result.
 */
function get_tests_for_posts(array $post_ids) {
    if (empty($post_ids)) return [];
    $pdo = db_connect();
    try {
        $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
        
        $stmt = $pdo->prepare("SELECT pt.post_id, pt.test_id FROM post_tests pt WHERE pt.post_id IN ($placeholders)");
        $stmt->execute(array_values($post_ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];
        
        $result = [];
        foreach ($rows as $row) {
            // get_test_by_id is still called per-test, but there are typically very few tests
            $test = get_test_by_id((int)$row['test_id']);
            if ($test) {
                $result[$row['post_id']] = $test;
            }
        }
        return $result;
    } catch (PDOException $e) {
        error_log('get_tests_for_posts DB error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Helper: Attach polls and tests to an array of posts using batch loading.
 * Mutates the $posts array in place, setting $post['poll'] and $post['test'] on each.
 */
function attach_polls_and_tests(array &$posts) {
    if (empty($posts)) return;
    $post_ids = array_column($posts, 'id');
    $polls = get_polls_for_posts($post_ids);
    $tests = get_tests_for_posts($post_ids);
    foreach ($posts as &$p) {
        $p['poll'] = $polls[$p['id']] ?? null;
        $p['test'] = $tests[$p['id']] ?? null;
    }
    unset($p);
}

/**
 * Helper: Attach polls only (no tests) to an array of posts.
 */
function attach_polls(array &$posts) {
    if (empty($posts)) return;
    $post_ids = array_column($posts, 'id');
    $polls = get_polls_for_posts($post_ids);
    foreach ($posts as &$p) {
        $p['poll'] = $polls[$p['id']] ?? null;
    }
    unset($p);
}

/**
 * Return aggregated poll statistics for a given poll id.
 * - totals per option, percentages, total votes
 * - if attached to a group post, include group member count and response rate
 */
function get_poll_stats($poll_id) {
    $pdo = db_connect();
    $resp = ['total_votes' => 0, 'options' => [], 'group_member_count' => null, 'response_rate' => null];

    $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
    $opts->execute([$poll_id]);
    $options = $opts->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($options as $o) $total += (int)$o['votes_count'];
    foreach ($options as &$o) {
        $o['votes_count'] = (int)$o['votes_count'];
        $o['percent'] = $total ? round($o['votes_count'] / $total * 100, 1) : 0.0;
    }
    unset($o);

    $resp['total_votes'] = $total;
    $resp['options'] = $options;

    $pstmt = $pdo->prepare("SELECT group_post_id FROM polls WHERE id = ? LIMIT 1");
    $pstmt->execute([$poll_id]);
    $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
    if ($prow && !empty($prow['group_post_id'])) {
        $gpstmt = $pdo->prepare("SELECT group_id FROM group_posts WHERE id = ? LIMIT 1");
        $gpstmt->execute([$prow['group_post_id']]);
        $gprow = $gpstmt->fetch(PDO::FETCH_ASSOC);
        if ($gprow && !empty($gprow['group_id'])) {
            $countstmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ?");
            $countstmt->execute([$gprow['group_id']]);
            $gmcount = (int)$countstmt->fetchColumn();
            $resp['group_member_count'] = $gmcount;
            $resp['response_rate'] = $gmcount > 0 ? round(($total / $gmcount) * 100, 1) : null;
        }
    }

    return $resp;
}

function vote_poll($user_id, $poll_id, $option_id) {
    $pdo = db_connect();
    // Validate poll
    $p = $pdo->prepare("SELECT id FROM polls WHERE id = ? LIMIT 1");
    $p->execute([$poll_id]);
    if (!$p->fetch()) return ['error' => 'poll_not_found'];

    // Special case: option_id == 0 means remove/unvote
    if ((int)$option_id === 0) {
        try {
            $pdo->beginTransaction();
            $v = $pdo->prepare("SELECT id, option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
            $v->execute([$poll_id, $user_id]);
            $existing = $v->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $pdo->commit();
                return ['status' => 'no_change'];
            }
            // decrement old option
            $dec = $pdo->prepare("UPDATE poll_options SET votes_count = GREATEST(votes_count - 1, 0) WHERE id = ?");
            $dec->execute([$existing['option_id']]);
            // delete vote record
            $del = $pdo->prepare("DELETE FROM poll_votes WHERE id = ?");
            $del->execute([$existing['id']]);
            $pdo->commit();
            return ['status' => 'removed'];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('vote_on_poll remove rollback: ' . $e->getMessage());
            return ['error' => 'db_error', 'message' => $e->getMessage()];
        }
    }

    // Validate option exists
    $o = $pdo->prepare("SELECT id, votes_count FROM poll_options WHERE id = ? AND poll_id = ? LIMIT 1");
    $o->execute([$option_id, $poll_id]);
    $opt = $o->fetch(PDO::FETCH_ASSOC);
    if (!$opt) return ['error' => 'option_not_found'];

    try {
        $pdo->beginTransaction();
        // Check existing vote
        $v = $pdo->prepare("SELECT id, option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
        $v->execute([$poll_id, $user_id]);
        $existing = $v->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['option_id'] === (int)$option_id) {
                // same vote, ignore
                $pdo->commit();
                return ['status' => 'no_change'];
            }
            // decrement old option
            $dec = $pdo->prepare("UPDATE poll_options SET votes_count = GREATEST(votes_count - 1, 0) WHERE id = ?");
            $dec->execute([$existing['option_id']]);
            // update vote record
            $up = $pdo->prepare("UPDATE poll_votes SET option_id = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$option_id, $existing['id']]);
            // increment new option
            $inc = $pdo->prepare("UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?");
            $inc->execute([$option_id]);
            $pdo->commit();
            return ['status' => 'changed'];
        } else {
            $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
            $ins->execute([$poll_id, $option_id, $user_id]);
            $inc = $pdo->prepare("UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?");
            $inc->execute([$option_id]);
            $pdo->commit();
            return ['status' => 'voted'];
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('vote_on_poll rollback: ' . $e->getMessage());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
}

// Get a single post by ID
function get_post($post_id) {
    $stmt = query("SELECT p.*, u.username, (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) AS comment_count FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ? AND p.deleted_at IS NULL AND u.deleted_at IS NULL", [$post_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['like_count'] = (int)($row['likes_count'] ?? 0);
    $row['poll'] = get_poll_for_post($post_id);
    $row['test'] = get_test_for_post($post_id);
    return $row;
}

// Get timeline posts with cursor-based pagination (no OFFSET for scale)
function get_posts_paginated($limit = 40, $viewer_id = null, $after = null, $before = null) {
    $posts = [];
    $has_next = false;
    $has_prev = false;
    
    // Determine sort direction and cursor
    if ($after) {
        $cursor_condition = "AND p.id < ?";
        $cursor_value = $after;
        $sort = "DESC";
    } elseif ($before) {
        $cursor_condition = "AND p.id > ?";
        $cursor_value = $before;
        $sort = "ASC";
    } else {
        $cursor_condition = "";
        $cursor_value = null;
        $sort = "DESC";
    }
    
    $fetch_limit = $limit + 1;
    
    if ($viewer_id) {
        $viewer = get_user($viewer_id);
        $is_admin = $viewer && $viewer['role'] === 'admin';
        
        if ($is_admin) {
            $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL $cursor_condition";
            if ($cursor_value) {
                $params = [$viewer_id, $cursor_value, $fetch_limit];
            } else {
                $params = [$viewer_id, $fetch_limit];
            }
        } else {
            // viewer is not admin: allow approved users always, the viewer's own posts,
            // and for rookies allow their first ten posts to show to everyone.
            $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL"
                    . " AND (u.is_approved = 1 OR u.id = ?"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
            if ($cursor_value) {
                $params = [$viewer_id, $viewer_id, $cursor_value, $fetch_limit];
            } else {
                $params = [$viewer_id, $viewer_id, $fetch_limit];
            }
        }
        
        $query_str = "
            SELECT p.*, u.username,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            $where
            ORDER BY p.id $sort
            LIMIT ?
        ";
        
        $stmt = query($query_str, $params);
    } else {
        // Public view
        // public users: show approved users or first-ten posts by rookies
        $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL"
                    . " AND (u.is_approved = 1"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
        if ($cursor_value) {
            $params = [$cursor_value, $fetch_limit];
        } else {
            $params = [$fetch_limit];
        }
        
        $stmt = query("
            SELECT p.*, u.username,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                0 as user_has_liked,
                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            $where
            ORDER BY p.id $sort
            LIMIT ?
        ", $params);
    }
    
    $results = $stmt->fetchAll();
    
    // If we fetched more than limit, we have more posts
    if (count($results) > $limit) {
        array_pop($results);
        $has_next = true;
    }
    
    // If we were going backward (DESC on ASC), reverse results
    if ($before) {
        $results = array_reverse($results);
        $has_next = true;
        $has_prev = false;
    } else {
        $has_prev = ($after !== null);
    }
    
    // Attach polls and tests to posts (if any)
    attach_polls_and_tests($results);

    return [
        'posts' => $results,
        'has_next' => $has_next,
        'has_prev' => $has_prev,
        'first_id' => count($results) > 0 ? $results[0]['id'] : null,
        'last_id' => count($results) > 0 ? $results[count($results) - 1]['id'] : null
    ];
}

// Get total count of posts (for display)
/* UNUSED_START get_posts_count
function get_posts_count($viewer_id = null) {
    if ($viewer_id) {
        $viewer = get_user($viewer_id);
        if ($viewer && $viewer['role'] === 'admin') {
            $stmt = query("
                SELECT COUNT(*) as total FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
            ");
        } else {
            $stmt = query("
                SELECT COUNT(*) as total FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
                    AND (u.is_approved = 1 OR u.id = ?
                         OR (u.role = 'rookie' AND (
                                SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                            ) <= 10 ) )
            ", [$viewer_id]);
        }
    } else {
        $stmt = query("
            SELECT COUNT(*) as total FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
                AND (u.is_approved = 1
                     OR (u.role = 'rookie' AND (
                            SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                        ) <= 10 ) )
        ");
    }
    return $stmt->fetch()['total'] ?? 0;
}
UNUSED_END get_posts_count */

// Count new items in the user's main feed since a timestamp
function get_new_feed_count($viewer_id, $since) {
    if (!$viewer_id || !$since) { return 0; }
    $pdo = db_connect();
    $viewer = get_user($viewer_id);

    // Posts (top-level only), approved filtering similar to get_posts_count
    if ($viewer && $viewer['role'] === 'admin') {
        $post_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL AND p.created_at > ?");
        $post_stmt->execute([$since]);
    } else {
        // only count posts that the viewer would see
        $post_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
                AND (u.is_approved = 1 OR u.id = ?
                     OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                     ) <= 10 ) ) AND p.created_at > ?");
        $post_stmt->execute([$viewer_id, $since]);
    }
    $posts_new = (int)($post_stmt->fetch()['c'] ?? 0);

    // Public group posts (include private for admins)
    $privacyFilter = ($viewer && $viewer['role'] === 'admin') ? '' : 'AND COALESCE(g.is_private,0) = 0';
    $group_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM group_posts gp JOIN groups_table g ON gp.group_id = g.id WHERE 1=1 $privacyFilter AND gp.created_at > ?");
    $group_stmt->execute([$since]);
    $groups_new = (int)($group_stmt->fetch()['c'] ?? 0);

    return $posts_new + $groups_new;
}

// Get timeline posts (all users) with approval filtering
function get_posts($limit = 50, $viewer_id = null) {
    // Filter out posts from unapproved users (except when viewing own posts)
    if ($viewer_id) {
        $viewer = get_user($viewer_id);
        if ($viewer && $viewer['role'] === 'admin') {
            $stmt = query("
                SELECT p.*, u.username,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                    (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL 
                ORDER BY p.created_at DESC LIMIT ?
            ", [$viewer_id, $limit]);
            $rows = $stmt->fetchAll();
            attach_polls_and_tests($rows);
            return $rows;
        }
        // Show all users' posts (no approval/rookie visibility limit for user-specific feed)
        $stmt = query("
            SELECT p.*, u.username,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
            ORDER BY p.created_at DESC LIMIT ?
        ", [$viewer_id, $limit]);
        $rows = $stmt->fetchAll();
        attach_polls_and_tests($rows);
        return $rows;
    }
    // Public view: show all users' top-level posts (no rookie limit)
    $stmt = query("
        SELECT p.*, u.username,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
            0 as user_has_liked,
            (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
        ORDER BY p.created_at DESC LIMIT ?
    ", [$limit]);
    $rows = $stmt->fetchAll();
    attach_polls($rows);
    return $rows;
} 

// Get posts by user (respect approval unless viewer is owner or admin)
function get_user_posts($user_id, $limit = 50, $viewer_id = null) {
    if ($viewer_id) {
        $viewer = get_user($viewer_id);
        if ($viewer && $viewer['role'] === 'admin') {
            $stmt = query("
                SELECT p.*, u.username,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                    (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.user_id = ? AND p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL 
                ORDER BY p.created_at DESC LIMIT ?
            ", [$viewer_id, $user_id, $limit]);
            $rows = $stmt->fetchAll();
            attach_polls($rows);
            return $rows;
        }
        // Owner sees their own posts even if not approved
        if ($viewer_id == $user_id) {
            $stmt = query("
                SELECT p.*, u.username,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                    (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.user_id = ? AND p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL 
                ORDER BY p.created_at DESC LIMIT ?
            ", [$viewer_id, $user_id, $limit]);
            $rows = $stmt->fetchAll();
            attach_polls($rows);
            return $rows;
        }
    }
    // Public view: show posts for any user (everyone can view anyone's posts)
    $user = get_user($user_id);
    if (!$user) {
        return [];
    }
    $stmt = query("
        SELECT p.*, u.username,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
            0 as user_has_liked,
            (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.user_id = ? AND p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL 
        ORDER BY p.created_at DESC LIMIT ?
    ", [$user_id, $limit]);
    return $stmt->fetchAll();
} 

// Get replies to a post (respect approval: show approved users' replies or own)
function get_replies($post_id, $viewer_id = null, $depth = 0, $max_depth = 5) {
    if ($depth >= $max_depth) {
        return [];
    }
    
    if ($viewer_id) {
        // Authenticated viewer: show all replies (including rookies) as long as reply and author are not deleted
        $stmt = query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id = ? AND p.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY p.created_at ASC", [$post_id]);
        $replies = $stmt->fetchAll();
    } else {
        // Public view: show all replies from non-deleted users (no role-based restriction)
        $stmt = query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id = ? AND p.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY p.created_at ASC", [$post_id]);
        $replies = $stmt->fetchAll();
    }
    
    // Add nested replies recursively
    foreach ($replies as &$reply) {
        $reply['replies'] = get_replies($reply['id'], $viewer_id, $depth + 1, $max_depth);
        $reply['depth'] = $depth;
    }
    
    return $replies;
}

// Get threaded group comments (similar to get_replies but for group_post_comments)
function get_group_comments($post_id, $viewer_id = null, $parent_id = null, $depth = 0, $max_depth = 5) {
    if ($depth >= $max_depth) {
        return [];
    }
    
    try {
        if ($parent_id === null) {
            // Top-level comments (no parent)
            $stmt = query("SELECT c.*, u.username,
                           (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id) as likes_count,
                           (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id AND user_id = ?) as user_liked
                           FROM group_post_comments c 
                           JOIN users u ON c.user_id = u.id 
                           WHERE c.post_id = ? AND c.parent_id IS NULL
                           ORDER BY c.created_at ASC", [$viewer_id ?: 0, $post_id]);
        } else {
            // Replies to a specific comment
            $stmt = query("SELECT c.*, u.username,
                           (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id) as likes_count,
                           (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id AND user_id = ?) as user_liked
                           FROM group_post_comments c 
                           JOIN users u ON c.user_id = u.id 
                           WHERE c.post_id = ? AND c.parent_id = ?
                           ORDER BY c.created_at ASC", [$viewer_id ?: 0, $post_id, $parent_id]);
        }
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add nested replies recursively
        foreach ($comments as &$comment) {
            $comment['replies'] = get_group_comments($post_id, $viewer_id, $comment['id'], $depth + 1, $max_depth);
            $comment['depth'] = $depth;
            $comment['post_id'] = $post_id;
        }
        
        return $comments;
    } catch (Exception $e) {
        error_log('get_group_comments error: ' . $e->getMessage());
        return [];
    }
}

// Count total group comments for a post (including nested)
function count_group_comments($post_id) {
    try {
        $stmt = query("SELECT COUNT(*) as cnt FROM group_post_comments WHERE post_id = ?", [$post_id]);
        return (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) {
        error_log('count_group_comments error: ' . $e->getMessage());
        return 0;
    }
}

// Report an item (post or reply)
function report_item($reporter_id, $target_type, $target_id, $reason = null, $ip_address = null) {
    $ip = $ip_address ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    query("INSERT INTO reports (reporter_id, target_type, target_id, reason, ip_address) VALUES (?, ?, ?, ?, ?)", [$reporter_id, $target_type, $target_id, $reason, $ip]);
}

/* UNUSED_START approve_helpers
// Approve a user (admin)
function approve_user($admin_id, $user_id) {
    query("UPDATE users SET role = 'member' WHERE id = ?", [$user_id]);
    create_notification($user_id, 'account_approved', $admin_id, null);
}

// Approve a pending post/reply (admin)
function approve_post($admin_id, $post_id) {
    query("UPDATE posts SET approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?", [$admin_id, $post_id]);
    $post = get_post($post_id);
    if ($post) {
        // If it's a reply, increment parent replies_count
        if ($post['parent_id']) {
            query("UPDATE posts SET replies_count = replies_count + 1 WHERE id = ?", [$post['parent_id']]);
            // Notify parent author about the reply
            $parent = get_post($post['parent_id']);
            if ($parent && $parent['user_id'] != $post['user_id']) {
                create_notification($parent['user_id'], 'reply', $post['user_id'], $post['parent_id']);
            }
        }
        // Notify post author that their post was approved
        create_notification($post['user_id'], 'account_approved', $admin_id, $post_id);

        // Notify mentioned users in this newly-approved post
        $mentioned = get_mentions($post['content']);
        foreach ($mentioned as $m) {
            if ($m['id'] != $post['user_id']) {
                create_notification($m['id'], 'mention', $post['user_id'], $post_id);
            }
        }
    }
}
UNUSED_END approve_helpers */


// Admin: suspend a user for N days (default 30)
function admin_suspend_user($admin_id, $user_id, $days = 30, $reason = null) {
    $until = date('Y-m-d H:i:s', strtotime("+" . intval($days) . " days"));
    query("UPDATE users SET suspended_until = ? WHERE id = ?", [$until, $user_id]);
    create_notification($user_id, 'suspended', $admin_id, null);
}

// Admin: lift a suspension
function admin_unsuspend_user($admin_id, $user_id) {
    query("UPDATE users SET suspended_until = NULL WHERE id = ?", [$user_id]);
    create_notification($user_id, 'unsuspended', $admin_id, null);
}

// Admin: permanently (soft) delete a user and their posts
function admin_delete_user($admin_id, $user_id) {
    // Soft-delete the user and their posts
    query("UPDATE users SET deleted_at = NOW() WHERE id = ?", [$user_id]);
    query("UPDATE posts SET deleted_at = NOW() WHERE user_id = ?", [$user_id]);
}

// Admin: resolve a report
function admin_resolve_report($admin_id, $report_id) {
    query("UPDATE reports SET status = 'resolved' WHERE id = ?", [$report_id]);
}

// Admin: delete a post (soft delete)
function admin_delete_post($admin_id, $post_id) {
    // Fetch the post first so we can notify the author even after deletion
    $post = get_post($post_id);
    // mark as deleted
    query("UPDATE posts SET deleted_at = NOW() WHERE id = ?", [$post_id]);

    // If this was a reply, decrement parent replies_count
    if ($post && !empty($post['parent_id'])) {
        query("UPDATE posts SET replies_count = GREATEST(0, replies_count - 1) WHERE id = ?", [$post['parent_id']]);
    }

    // Notify author if present
    if ($post) {
        create_notification($post['user_id'], 'report', $admin_id, $post_id);
    }

    // mark any reports on this target as resolved (post or reply)
    query("UPDATE reports SET status = 'resolved' WHERE target_id = ? AND (target_type = 'post' OR target_type = 'reply')", [$post_id]);
}

// User delete their own post
function user_delete_post($user_id, $post_id) {
    // Fetch the post to verify ownership
    $post = get_post($post_id);
    
    if (!$post || $post['user_id'] != $user_id) {
        error_log("user_delete_post failed: not_owner_or_missing user={$user_id} post={$post_id} post_owner=" . ($post['user_id'] ?? 'null'));
        return ['error' => 'not_owner']; // Not the owner
    }
    
    // Check if user is approved - unapproved users cannot delete posts
    $user = get_user($user_id);
    if ($user && isset($user['is_approved']) && $user['is_approved'] == 0) {
        error_log("user_delete_post failed: unapproved user={$user_id} post={$post_id}");
        return ['error' => 'unapproved']; // Unapproved users cannot delete posts
    }

    // Special restriction: rookies may self-delete if allowed by config.
    if ($user && isset($user['role']) && $user['role'] === 'rookie' && !is_user_premium($user_id) && !ROOKIE_ALLOW_SELF_DELETE) {
        error_log("user_delete_post failed: rookie_restricted user={$user_id} role=rookie post={$post_id}");
        return ['error' => 'rookie_restricted'];
    }
    
    // mark as deleted
    query("UPDATE posts SET deleted_at = NOW() WHERE id = ?", [$post_id]);

    // If this was a reply, decrement parent replies_count
    if (!empty($post['parent_id'])) {
        query("UPDATE posts SET replies_count = GREATEST(0, replies_count - 1) WHERE id = ?", [$post['parent_id']]);
    }
    
    return true;
}

// Get reports (admin). Optionally filter by status: 'open' or 'resolved'.
function get_reports($status = null, $limit = 200) {
    $sql = "SELECT r.*, u.username as reporter_username, p.content as target_content, p.user_id AS target_user_id, p.deleted_at as target_deleted_at FROM reports r LEFT JOIN users u ON r.reporter_id = u.id LEFT JOIN posts p ON r.target_id = p.id";
    $params = [];

    if ($status === 'open') {
        // Treat NULL as open for backward compatibility
        $sql .= " WHERE (r.status IS NULL OR r.status = 'open')";
    } elseif ($status === 'resolved') {
        $sql .= " WHERE r.status = 'resolved'";
    }

    $sql .= " ORDER BY r.created_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = query($sql, $params);
    return $stmt->fetchAll();
}

// Get reports for posts that have been deleted. Optionally filter by year/month of deletion.
function get_deleted_post_reports($year = null, $month = null, $limit = 500) {
    $sql = "SELECT r.*, u.username as reporter_username, p.content as target_content, p.user_id AS target_user_id, p.deleted_at as target_deleted_at FROM reports r LEFT JOIN users u ON r.reporter_id = u.id LEFT JOIN posts p ON r.target_id = p.id WHERE p.deleted_at IS NOT NULL";
    $params = [];

    if ($year !== null) {
        $sql .= " AND YEAR(p.deleted_at) = ?";
        $params[] = intval($year);
    }
    if ($month !== null) {
        $sql .= " AND MONTH(p.deleted_at) = ?";
        $params[] = intval($month);
    }

    $sql .= " ORDER BY p.deleted_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = query($sql, $params);
    return $stmt->fetchAll();
}

// Get month/year buckets for deleted posts (for navigation)
function get_deleted_post_months() {
    $stmt = query("SELECT YEAR(deleted_at) AS y, MONTH(deleted_at) AS m, COUNT(*) AS c FROM posts WHERE deleted_at IS NOT NULL GROUP BY y, m ORDER BY y DESC, m DESC");
    return $stmt->fetchAll();
}

// Like/unlike a post
function toggle_like($user_id, $post_id, $reaction = '🔥') {
    // Check if already liked
    $stmt = query("SELECT id FROM likes WHERE user_id = ? AND post_id = ?", [$user_id, $post_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Unlike
        query("DELETE FROM likes WHERE user_id = ? AND post_id = ?", [$user_id, $post_id]);
        query("UPDATE posts SET likes_count = likes_count - 1 WHERE id = ?", [$post_id]);
        return false;
    } else {
        // Like
        query("INSERT INTO likes (user_id, post_id, reaction) VALUES (?, ?, ?)", [$user_id, $post_id, $reaction]);
        query("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?", [$post_id]);
        
        // Create notification
        $post = get_post($post_id);
        if ($post && $post['user_id'] != $user_id) {
            create_notification($post['user_id'], 'like', $user_id, $post_id);
        }

        // Sync badges for the post author if likes changed
        maybe_sync_badges_after_like($post_id);
        return true;
    }
}

// Check if user liked a post
function is_liked($user_id, $post_id) {
    $stmt = query("SELECT id FROM likes WHERE user_id = ? AND post_id = ?", [$user_id, $post_id]);
    return $stmt->fetch() !== false;
}

/* UNUSED_START like_and_link_helpers
// Get likes for a post
function get_likes($post_id) {
    $stmt = query("SELECT l.*, u.username FROM likes l JOIN users u ON l.user_id = u.id WHERE l.post_id = ? ORDER BY l.created_at DESC", [$post_id]);
    return $stmt->fetchAll();
}

// Convert @username mentions to profile links and escape the rest
function link_usernames($text) {
    $escaped = htmlspecialchars($text);
    return preg_replace_callback('/@([A-Za-z0-9_]+)/', function($m) {
        $u = get_user_by_username($m[1]);
        if ($u) {
            return '<a href="' . profile_url($u['username']) . '">@' . htmlspecialchars($u['username']) . '</a>';
        }
        return htmlspecialchars($m[0]);
    }, $escaped);
}
UNUSED_END like_and_link_helpers */

// Get mentioned users in a text. Returns array of user rows (id, username, ...)
function get_mentions($text) {
    $users = [];
    if (preg_match_all('/@([\p{L}\p{N}_-]+(?: [\p{L}\p{N}_-]+)?)/u', $text, $matches)) {
        $names = array_unique($matches[1]);
        foreach ($names as $name) {
            $name = trim($name);
            $u = get_user_by_username($name);
            if (!$u) {
                // Try slug-based lookup for names like @hatasiz-cool
                $u = get_user_by_slug($name);
            }
            if ($u) {
                $users[] = $u;
            }
        }
    }
    return $users;
}

// Badges: CRUD + assignment
function create_badge($name, $slug, $description = null, $min_likes = 0) {
    query("INSERT INTO badges (name, slug, description, min_likes) VALUES (?, ?, ?, ?)", [$name, $slug, $description, $min_likes]);
    return insert_id();
}

function update_badge($id, $name, $slug, $description, $min_likes) {
    query("UPDATE badges SET name = ?, slug = ?, description = ?, min_likes = ? WHERE id = ?", [$name, $slug, $description, $min_likes, $id]);
}

function delete_badge($id) {
    query("DELETE FROM badges WHERE id = ?", [$id]);
}

function get_badges($limit = 100) {
    $stmt = query("SELECT * FROM badges ORDER BY min_likes ASC LIMIT ?", [$limit]);
    return $stmt->fetchAll();
}

function get_badge($id) {
    $stmt = query("SELECT * FROM badges WHERE id = ?", [$id]);
    return $stmt->fetch();
}

function get_user_badges($user_id) {
    $stmt = query("SELECT b.* FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ? ORDER BY b.min_likes ASC", [$user_id]);
    return $stmt->fetchAll();
}

function assign_badge_to_user($user_id, $badge_id, $assigned_by = null) {
    // insert if not exists
    try {
        query("INSERT INTO user_badges (user_id, badge_id, assigned_by) VALUES (?, ?, ?)", [$user_id, $badge_id, $assigned_by]);
    } catch (PDOException $e) {
        // ignore duplicates
        error_log('assign_badge_to_user duplicate or error: ' . $e->getMessage());
    }
}

function remove_badge_from_user($user_id, $badge_id) {
    query("DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?", [$user_id, $badge_id]);
}

function get_likes_received($user_id) {
    $stmt = query("SELECT COALESCE(SUM(likes_count), 0) as c FROM posts WHERE user_id = ? AND deleted_at IS NULL", [$user_id]);
    $row = $stmt->fetch();
    return (int)($row['c'] ?? 0);
}

// --- Tags utilities ---
function ensure_tag_clicks_table() {
    static $ensured = false;
    if ($ensured) return;
    try {
        query("CREATE TABLE IF NOT EXISTS tag_clicks (
            tag VARCHAR(100) PRIMARY KEY,
            click_count INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('ensure_tag_clicks_table failed: ' . $e->getMessage());
    }
    $ensured = true;
}

function normalize_tag($tag) {
    $tag = trim($tag);
    $tag = ltrim($tag, "#");
    return mb_strtolower($tag, 'UTF-8');
}

function record_tag_click($tag) {
    $t = normalize_tag($tag);
    if ($t === '') return;
    ensure_tag_clicks_table();
    try {
        query("INSERT INTO tag_clicks (tag, click_count) VALUES (?, 1)
               ON DUPLICATE KEY UPDATE click_count = click_count + 1", [$t]);
    } catch (Exception $e) {
        error_log('record_tag_click failed: ' . $e->getMessage());
    }
}

function extract_hashtags_from_text($text) {
    $tags = [];
    if (preg_match_all('/#([\p{L}\p{N}_-]+)/u', (string)$text, $m)) {
        foreach ($m[1] as $raw) {
            $tags[] = normalize_tag($raw);
        }
    }
    return $tags;
}



// Sync badges for a user based on min_likes thresholds
function sync_user_badges_by_likes($user_id) {
    $likes = get_likes_received($user_id);
    $badges = get_badges(1000);

    foreach ($badges as $b) {
        if ($likes >= $b['min_likes']) {
            assign_badge_to_user($user_id, $b['id']);
        } else {
            // remove if previously assigned automatically (we can't tell if it was manual), but we will remove it to reflect thresholds
            remove_badge_from_user($user_id, $b['id']);
        }
    }
}

// Sync badges for the post's author on like change
function maybe_sync_badges_after_like($post_id) {
    $post = get_post($post_id);
    if ($post) {
        sync_user_badges_by_likes($post['user_id']);
    }
}

// Follow/unfollow a user
function toggle_follow($follower_id, $following_id) {
    if ($follower_id == $following_id) {
        return false;
    }
    
    $stmt = query("SELECT * FROM follows WHERE follower_id = ? AND following_id = ?", [$follower_id, $following_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Unfollow
        query("DELETE FROM follows WHERE follower_id = ? AND following_id = ?", [$follower_id, $following_id]);
        return false;
    } else {
        // Follow
        query("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)", [$follower_id, $following_id]);
        create_notification($following_id, 'follow', $follower_id, null);
        return true;
    }
}

// Check if following
function is_following($follower_id, $following_id) {
    $stmt = query("SELECT * FROM follows WHERE follower_id = ? AND following_id = ?", [$follower_id, $following_id]);
    return $stmt->fetch() !== false;
}

// Get follower count
function get_followers_count($user_id) {
    $stmt = query("SELECT COUNT(*) as count FROM follows WHERE following_id = ?", [$user_id]);
    $result = $stmt->fetch();
    return $result['count'];
}

// Get following count
function get_following_count($user_id) {
    $stmt = query("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?", [$user_id]);
    $result = $stmt->fetch();
    return $result['count'];
}

// Get list of followers
function get_followers($user_id, $limit = 100) {
    $stmt = query("SELECT u.id, u.username, u.bio, u.created_at FROM follows f JOIN users u ON f.follower_id = u.id WHERE f.following_id = ? AND u.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT ?", [$user_id, $limit]);
    return $stmt->fetchAll();
}

// Get list of following users
function get_following($user_id, $limit = 100) {
    $stmt = query("SELECT u.id, u.username, u.bio, u.created_at FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? AND u.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT ?", [$user_id, $limit]);
    return $stmt->fetchAll();
}

// Get friend/follow suggestions for a user (server-side, simple mutuals + active fallback)
// Returns an array of ['id'=>int,'username'=>string,'reason'=>string]
function get_friend_suggestions($user_id, $limit = 5) {
    if (!$user_id) return [];

    // 1) Candidates followed by people the user follows (friends-of-friends)
    $stmt = query(
        "SELECT u.id, u.username, u.is_online, u.last_activity, COUNT(*) AS mutual_count
         FROM follows f1
         JOIN follows f2 ON f1.following_id = f2.follower_id
         JOIN users u ON u.id = f2.following_id
         WHERE f1.follower_id = ? AND u.deleted_at IS NULL AND u.id != ?
           AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = ?)
         GROUP BY u.id
         ORDER BY mutual_count DESC, u.last_activity DESC
         LIMIT ?",
        [$user_id, $user_id, $user_id, $limit]
    );

    $rows = $stmt->fetchAll();
    $res = [];
    $selected_ids = [];
    foreach ($rows as $r) {
        $mutual = intval($r['mutual_count']);
        $reason = $mutual > 0 ? ($mutual . ' ortak takipçi') : 'Önerilen';
        $res[] = ['id' => $r['id'], 'username' => $r['username'], 'is_online' => !empty($r['is_online']), 'last_activity' => $r['last_activity'], 'reason' => $reason];
        $selected_ids[] = $r['id'];
    }

    // 2) Fallback: popular/active users (order by last_activity), exclude already followed & already selected
    if (count($res) < $limit) {
        $remaining = $limit - count($res);

        // Get list of already-followed ids
        $stmt2 = query("SELECT following_id FROM follows WHERE follower_id = ?", [$user_id]);
        $already = array_column($stmt2->fetchAll(), 'following_id');

        $exclude = array_unique(array_merge([$user_id], $already, $selected_ids));

        // Build exclusion placeholders
        $placeholders = implode(',', array_fill(0, max(1, count($exclude)), '?'));
        $params = $exclude;
        $params[] = $remaining;

        $sql = "SELECT id, username, is_online, last_activity FROM users WHERE deleted_at IS NULL AND id NOT IN ($placeholders) ORDER BY last_activity DESC LIMIT ?";
        $stmt3 = query($sql, $params);
        foreach ($stmt3->fetchAll() as $r) {
            $res[] = ['id' => $r['id'], 'username' => $r['username'], 'is_online' => !empty($r['is_online']), 'last_activity' => $r['last_activity'], 'reason' => 'Popüler üye'];
            if (count($res) >= $limit) break;
        }
    }

    return $res;
}

// Create notification (deduplicate mentions so each user gets at most one per post)
function create_notification($user_id, $type, $from_user_id, $post_id = null) {
    try {
        // Deduplicate mention notifications (one per user per post per from_user)
        if ($type === 'mention' && $post_id !== null) {
            $stmt = query("SELECT id FROM notifications WHERE user_id = ? AND type = ? AND post_id = ? AND from_user_id = ?", [$user_id, $type, $post_id, $from_user_id]);
            if ($stmt->fetch()) {
                // Already notified
                return false;
            }
        }

        query("INSERT INTO notifications (user_id, type, from_user_id, post_id) VALUES (?, ?, ?, ?)", [$user_id, $type, $from_user_id, $post_id]);

        // Optionally send email if enabled and user opted in for this type
        try {
            if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                $u = get_user($user_id);
                if ($u && !empty($u['email']) && !empty($u['notify_by_email'])) {
                    $send = false;
                    switch ($type) {
                        case 'mention': $send = !empty($u['notify_on_mention']); break;
                        case 'reply': $send = !empty($u['notify_on_reply']); break;
                        case 'report': $send = !empty($u['notify_on_report']); break;
                        case 'suspended':
                        case 'unsuspended':
                        case 'account_approved':
                            $send = !empty($u['notify_on_system']); break;
                        default:
                            $send = false;
                    }

                    if ($send) {
                        $subject = SITE_NAME . " - Yeni bildirim";
                        $from_user = $from_user_id ? get_user($from_user_id) : null;
                        $from = $from_user ? $from_user['username'] : 'Sistem';

                        switch ($type) {
                            case 'mention':
                                $notification_text = "Sizi bir gonderide bahsettiler. Gonderiye bakmak icin: " . full_url(get_post_url($post_id));
                                break;
                            case 'reply':
                                $notification_text = "Gonderinize yeni bir yanit var: " . full_url(get_post_url($post_id));
                                break;
                            case 'report':
                                $notification_text = "Gonderiniz rapor edildi. Bir yonetici islem yapmis olabilir.";
                                break;
                            case 'suspended':
                                $notification_text = "Hesabiniz bir yonetici tarafindan yasaklandi.";
                                break;
                            case 'unsuspended':
                                $notification_text = "Hesabinizin yasagi kaldirildi.";
                                break;
                            case 'account_approved':
                                $notification_text = "Hesabiniz onaylandi. Artik paylasimlariniz gorunebilir.";
                                break;
                            default:
                                $notification_text = "Yeni bir bildirim var.";
                                break;
                        }

                        $body_lines = [
                            "Merhaba " . $u['username'] . ",",
                            $notification_text,
                            "Saygilar,",
                            SITE_NAME,
                            BASE_PATH,
                        ];

                        $body = trim(implode("\n\n", array_filter($body_lines))) . "\n";

                        $mail_sent = send_email($u['email'], $subject, $body);
                        error_log("[NOTIFY] type=" . $type . " user=" . $u['id'] . " email=" . $u['email'] . " send=" . ($mail_sent ? 'ok' : 'fail'));
                    }
                }
            }
        } catch (Exception $ex) {
            error_log("email send error: " . $ex->getMessage());
        }

        return true;
    } catch (PDOException $e) {
        // If notifications enum doesn't include this type yet, skip notification to avoid fatal errors.
        // Log exception to error log to aid debugging (e.g., missing enum value)
        error_log("create_notification error: " . $e->getMessage());
        return false;
    }
}

/* UNUSED_START notification_helpers
// Get notifications for user
function get_notifications($user_id) {
    $stmt = query("SELECT n.*, u.username as from_username, p.content as post_content FROM notifications n LEFT JOIN users u ON n.from_user_id = u.id LEFT JOIN posts p ON n.post_id = p.id WHERE n.user_id = ? ORDER BY n.created_at DESC", [$user_id]);
    return $stmt->fetchAll();
}

// Mark notification as read
function mark_notification_read($notification_id, $user_id) {
    query("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?", [$notification_id, $user_id]);
}
UNUSED_END notification_helpers */

// Get unread notification count
function get_unread_count($user_id) {
    $stmt = query("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND read_at IS NULL", [$user_id]);
    $result = $stmt->fetch();
    return $result['count'];
}

// Format timestamp
function format_time($timestamp) {
    $tzName = date_default_timezone_get() ?: 'Europe/Istanbul';
    $tz = new DateTimeZone($tzName);

    try {
        $dt = new DateTimeImmutable($timestamp, $tz);
    } catch (Exception $e) {
        error_log('format_time parse error for "' . $timestamp . '": ' . $e->getMessage());
        $ts = @strtotime($timestamp);
        if ($ts === false) {
            $ts = time();
        }
        $dt = (new DateTimeImmutable())->setTimestamp($ts)->setTimezone($tz);
    }

    $now = new DateTimeImmutable('now', $tz);
    $diff = $now->getTimestamp() - $dt->getTimestamp();

    if ($diff < 60) {
        return 'az önce';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' dakika önce';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' saat önce';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' gün önce';
    } else {
        return $dt->format('d.m.Y H:i');
    }
}

// Send an email, preferring SMTP via PHPMailer (if configured) and falling back to mail()
function send_email($to, $subject, $body) {
    $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'no-reply@example.com';
    $from_name = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : SITE_NAME;

    // Resolve SMTP settings from constants (config.php) or env vars
    $smtp_host = defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST');
    $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : intval(getenv('SMTP_PORT') ?: 587);
    $smtp_user = defined('SMTP_USER') ? SMTP_USER : getenv('SMTP_USER');
    $smtp_pass = defined('SMTP_PASS') ? SMTP_PASS : getenv('SMTP_PASS');
    $smtp_secure = defined('SMTP_SECURE') ? SMTP_SECURE : (getenv('SMTP_SECURE') ?: 'tls');

    // Debug: log which email transport is being used
    error_log(sprintf('[MAIL] send_email(%s) smtp_host=%s smtp_user=%s smtp_pass=%s phpmailer=%s',
        $to,
        $smtp_host ?: 'NONE',
        $smtp_user ? 'SET' : 'NONE',
        $smtp_pass ? 'SET' : 'NONE',
        class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'yes' : 'no'
    ));

    // Load PHPMailer if available (for SMTP sending)
    $phpmailer_dir = __DIR__ . '/../vendor/phpmailer/src';
    if (is_dir($phpmailer_dir)) {
        require_once $phpmailer_dir . '/Exception.php';
        require_once $phpmailer_dir . '/PHPMailer.php';
        require_once $phpmailer_dir . '/SMTP.php';
    } else {
        error_log('[MAIL] PHPMailer directory not found: ' . $phpmailer_dir);
    }

    // If SMTP configured and PHPMailer is available, use it
    if (!empty($smtp_host) && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log('[MAIL] Using PHPMailer via SMTP host ' . $smtp_host . ' for ' . $to);
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = $smtp_secure;
            $mail->CharSet = 'UTF-8';
            // Use the authenticated SMTP user as the envelope sender to avoid relay/alias rejection.
            // Keep the site 'From' in Reply-To so replies go to the configured address.
            $from_address = !empty($smtp_user) ? $smtp_user : $from_email;
            $mail->setFrom($from_address, $from_name);
            if ($from_address !== $from_email && !empty($from_email)) {
                $mail->addReplyTo($from_email, $from_name);
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            $mail->isHTML(false);
            if ($mail->send()) {
                error_log('[MAIL] PHPMailer send ok for ' . $to);
                @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer ok to $to\n", FILE_APPEND);
                return true;
            }
            error_log('[MAIL] PHPMailer send failed for ' . $to);
            @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer failed to $to\n", FILE_APPEND);
        } catch (Exception $e) {
            error_log('[MAIL] PHPMailer error: ' . $e->getMessage());
            @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer error to $to: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        // Do not fall back to mail() when SMTP is configured; prefer explicit failure for debugging.
        return false;
    }

    error_log('[MAIL] Falling back to mail() for ' . $to);
    // Fallback to simple mail() if PHPMailer not available or SMTP not configured
    $headers = "From: " . $from_name . " <" . $from_email . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        return mail($to, $subject, $body, $headers);
    } else {
        error_log('[MAIL] Mail disabled (MAIL_ENABLED=false) - not sending to ' . $to);
        return false;
    }
}

// Get post URL (supports clean and fallback formats)
function get_post_url($post_id, $username = null) {
    if (!$post_id) {
        return BASE_PATH . '/post.php?id=0';
    }

    if ($username) {
        $slug = get_user_slug($username);
        if (!empty($slug)) {
            return BASE_PATH . '/' . rawurlencode($slug) . '/' . intval($post_id);
        }
        if (use_clean_urls() && is_username_clean_url_safe($username)) {
            return BASE_PATH . '/' . rawurlencode($username) . '/' . intval($post_id);
        }
        return BASE_PATH . '/user_post.php?username=' . rawurlencode($username) . '&p=' . intval($post_id);
    }

    if (USE_CLEAN_URLS) {
        return BASE_PATH . '/p/' . intval($post_id);
    }
    return BASE_PATH . '/post.php?id=' . intval($post_id);
}

function reply_url($post_id, $parent_id) {
    $post_id = intval($post_id);
    $parent_id = intval($parent_id);
    if ($post_id <= 0 || $parent_id <= 0) {
        return BASE_PATH . '/reply.php?post_id=' . $post_id . '&parent_id=' . $parent_id;
    }
    if (use_clean_urls()) {
        return BASE_PATH . '/reply/' . $post_id . '/' . $parent_id;
    }
    return BASE_PATH . '/reply.php?post_id=' . $post_id . '&parent_id=' . $parent_id;
}

// Get user's group posts
function get_user_group_posts($user_id, $limit = 50, $viewer_id = null) {
    $stmt = query("
        SELECT gp.id, gp.group_id, gp.user_id, gp.content, gp.created_at, 
               u.username, gt.name as group_name, gt.slug,
               (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) as like_count,
               (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) as user_has_liked,
               (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) as comment_count
        FROM group_posts gp
        JOIN users u ON gp.user_id = u.id
        JOIN groups_table gt ON gp.group_id = gt.id
        WHERE gp.user_id = ?
        ORDER BY gp.created_at DESC
        LIMIT ?
    ", [$viewer_id ?? 0, $user_id, $limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['poll'] = get_poll_for_group_post($r['id']); }
    return $rows;
}

// SEO-friendly URL generators
function group_url($slug) {
    // Prefer /g/ as the canonical group prefix, keep /t/ for legacy compatibility
    // Normalize slug to ASCII-friendly version in clean URL mode for unicode names.
    $normalized = preg_replace('/[^a-z0-9_-]+/i', '-', trim(strtolower($slug)));
    $normalized = trim($normalized, '-');
    $slug_for_url = USE_CLEAN_URLS ? urlencode($normalized) : urlencode($slug);

    if (USE_CLEAN_URLS) {
        return BASE_PATH . '/g/' . $slug_for_url;
    }
    return BASE_PATH . '/group.php?slug=' . $slug_for_url;
}

function group_post_url($slug, $post_id) {
    return BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post_id;
}

function group_members_url($slug) {
    // Keep group URL canonicalized the same as group_url.
    $normalized = preg_replace('/[^a-z0-9_-]+/i', '-', trim(strtolower($slug)));
    $normalized = trim($normalized, '-');
    if (USE_CLEAN_URLS) {
        return BASE_PATH . '/g/' . urlencode($normalized) . '/uyeler';
    }
    return BASE_PATH . '/group_members.php?slug=' . urlencode($slug);
}

function announcement_url($slug, $id, $created_at = null) {
    if (USE_CLEAN_URLS && !empty($slug)) {
        // Format: /duyuru/title-slug-YYYY-MM-DD
        $date_part = $created_at ? date('Y-m-d', strtotime($created_at)) : '';
        $url_slug = $date_part ? $slug . '-' . $date_part : $slug;
        return BASE_PATH . '/duyuru/' . urlencode($url_slug);
    }
    return BASE_PATH . '/announcement.php?id=' . (int)$id;
}

function user_url($username) {
    // alias for profile_url; keep semantics clear
    return profile_url($username);
}

// Track user activity - updates last_activity and sets is_online to 1
function track_user_activity($user_id) {
    if (!$user_id) return;
    try {
        query("UPDATE users SET is_online = 1, last_activity = NOW() WHERE id = ?", [$user_id]);
    } catch (Exception $e) {
        error_log('track_user_activity failed for user ' . $user_id . ': ' . $e->getMessage());
    }
}

// Get user's followed users IDs
function get_followed_user_ids($user_id) {
    if (!$user_id) return [];
    $stmt = query("SELECT following_id FROM followers WHERE follower_id = ?", [$user_id]);
    $results = $stmt->fetchAll();
    return array_map(function($row) { return $row['following_id']; }, $results);
}

// Get user's favorite tags (tags they've interacted with via likes)
function get_user_favorite_tags($user_id, $limit = 10) {
    if (!$user_id) return [];
    $stmt = query("
        SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(p.content, '#', numbers.n), '#', -1) as tag,
               COUNT(*) as tag_count
        FROM (SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) numbers
        JOIN posts p ON (LENGTH(p.content) - LENGTH(REPLACE(p.content, '#', '')) >= numbers.n)
        JOIN likes l ON l.post_id = p.id
        WHERE l.user_id = ? AND p.parent_id IS NULL
        GROUP BY tag
        ORDER BY tag_count DESC
        LIMIT ?
    ", [$user_id, $limit]);
    $results = $stmt->fetchAll();
    return array_map(function($row) { return trim(preg_replace('/[^\p{L}\p{N}_-]/u', '', $row['tag'])); }, $results);
}

// Get user's interaction count (likes + comments)
function get_user_engagement_level($user_id) {
    if (!$user_id) return 0;
    $stmt = query("
        SELECT 
            (SELECT COUNT(*) FROM likes WHERE user_id = ?) as likes_count
            + (SELECT COUNT(*) FROM posts WHERE user_id = ? AND parent_id IS NOT NULL AND deleted_at IS NULL) as comments_count
        as total_engagement
    ", [$user_id, $user_id]);
    $result = $stmt->fetch();
    return $result['total_engagement'] ?? 0;
}

// Get similar users based on tag interests
function get_similar_users($user_id, $limit = 5) {
    if (!$user_id) return [];
    $stmt = query("
        SELECT u.id, u.username, 
               COUNT(DISTINCT l1.post_id) as common_likes
        FROM users u
        JOIN likes l1 ON u.id = l1.user_id
        JOIN likes l2 ON l1.post_id = l2.post_id AND l2.user_id = ?
        WHERE u.id != ? AND u.deleted_at IS NULL
        GROUP BY u.id
        ORDER BY common_likes DESC
        LIMIT ?
    ", [$user_id, $user_id, $limit]);
    return $stmt->fetchAll();
}

// Get relevant posts for user (personalized feed)
// Uses relevance scoring based on: followed users, favorite tags, engagement, recency, popularity
function get_relevant_posts($user_id = null, $limit = 40, $after = null) {
    $posts = [];
    
    if (!$user_id) {
        // For anonymous users, return recent popular posts
        $cursor_condition = $after ? "AND p.id < ?" : "";
        $cursor_param = $after ? [$after, $limit + 1] : [$limit + 1];
        
        $stmt = query("
            SELECT p.*, u.username, u.is_premium,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count,
                0 as user_has_liked
            FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
              AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW())
              AND (u.is_approved = 1
                   OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                   ) <= 10)) $cursor_condition
            ORDER BY p.created_at DESC
            LIMIT ?
        ", array_merge($cursor_param));
        
        return $stmt->fetchAll();
    }
    
    // Get user's followed users
    $followed_ids = get_followed_user_ids($user_id);
    $followed_str = !empty($followed_ids) ? implode(',', $followed_ids) : '0';
    
    // Get user's favorite tags
    $favorite_tags = get_user_favorite_tags($user_id, 5);
    
    // Build cursor condition
    $cursor_condition = $after ? "AND p.id < ?" : "";
    $cursor_param = $after ? [$after] : [];
    
    // Build LIKE conditions for tags
    $tag_conditions = "";
    if (count($favorite_tags) > 0) {
        $tag_likes = array_map(function($t) { 
            // Escape LIKE wildcards properly (no addslashes)
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $t);
            return "p.content LIKE '%#" . $escaped . "%'"; 
        }, $favorite_tags);
        $tag_conditions = " OR (" . implode(" OR ", $tag_likes) . ")";
    }
    
    // Fetch paginated results with relevance scoring
    $stmt = query("
        SELECT 
            p.*, 
            u.username, 
            u.is_premium,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
            (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count,
            (
                CASE WHEN p.user_id IN ($followed_str) THEN 100 ELSE 0 END
                + CASE WHEN p.content LIKE '%#%' $tag_conditions THEN 50 ELSE 0 END
                + ((SELECT COUNT(*) FROM likes WHERE post_id = p.id) * 0.5)
                + ((SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) * 1.0)
                + (DATEDIFF(NOW(), p.created_at) * -2)
            ) as relevance_score
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
        AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW())
        AND (u.is_approved = 1 OR u.id = ?
             OR (u.role = 'rookie' AND (
                    SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                ) <= 10))
        $cursor_condition
        ORDER BY relevance_score DESC, p.created_at DESC
        LIMIT ?
    ", array_merge([$user_id, $user_id], $cursor_param, [$limit + 1]));
    
    return $stmt->fetchAll();
}

// Get pagination info for relevant posts
function get_relevant_posts_paginated($user_id = null, $limit = 40, $after = null) {
    $all_posts = get_relevant_posts($user_id, $limit, $after);
    
    $has_next = false;
    $posts = [];
    
    if (count($all_posts) > $limit) {
        array_pop($all_posts);
        $has_next = true;
    }
    
    $posts = $all_posts;
    // Attach polls to relevant posts
    attach_polls_and_tests($posts);
    
    return [
        'posts' => $posts,
        'has_next' => $has_next,
        'first_id' => count($posts) > 0 ? $posts[0]['id'] : null,
        'last_id' => count($posts) > 0 ? $posts[count($posts) - 1]['id'] : null
    ];
}

// Follow a user
function follow_user($follower_id, $following_id) {
    if ($follower_id == $following_id) return false; // Can't follow yourself
    if (!$follower_id || !$following_id) return false;
    
    try {
        query("INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?, ?)", [$follower_id, $following_id]);
        return true;
    } catch (Exception $e) {
        error_log('follow_user failed: ' . $e->getMessage());
        return false;
    }
}

/* UNUSED_START unfollow_helper
// Unfollow a user
function unfollow_user($follower_id, $following_id) {
    if (!$follower_id || !$following_id) return false;
    
    try {
        query("DELETE FROM followers WHERE follower_id = ? AND following_id = ?", [$follower_id, $following_id]);
        return true;
    } catch (Exception $e) {
        error_log('unfollow_user failed: ' . $e->getMessage());
        return false;
    }
}
UNUSED_END unfollow_helper */

/**
 * Get trending tags based on relevancy and context
 * Sorts by engagement (likes + comments) and recency
 * Uses PHP-side extraction to support Unicode hashtags reliably
 */
function get_trending_tags($limit = 10, $user_id = null) {
    try {
        $pdo = db_connect();
        // Fetch recent posts with like/comment counts (bounded to 1000 recent posts)
        $stmt = $pdo->prepare("SELECT p.id, p.content, p.created_at,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
            (SELECT COUNT(*) FROM posts c WHERE c.parent_id = p.id AND c.deleted_at IS NULL) as comments_count
            FROM posts p
            WHERE p.deleted_at IS NULL AND p.parent_id IS NULL
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY p.created_at DESC
            LIMIT 1000");
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $acc = [];
        foreach ($posts as $p) {
            $content = $p['content'] ?? '';
            $likes = (int)($p['likes_count'] ?? 0);
            $comments = (int)($p['comments_count'] ?? 0);
            $created_at = $p['created_at'] ?? null;

            $tags = extract_hashtags_from_text($content);
            if (empty($tags)) continue;
            $tags = array_unique($tags);
            foreach ($tags as $t) {
                if ($t === '') continue;
                if (!isset($acc[$t])) {
                    $acc[$t] = ['post_count' => 0, 'total_likes' => 0, 'total_comments' => 0, 'last_post_date' => null];
                }
                $acc[$t]['post_count'] += 1;
                $acc[$t]['total_likes'] += $likes;
                $acc[$t]['total_comments'] += $comments;
                if (is_null($acc[$t]['last_post_date']) || strtotime($created_at) > strtotime($acc[$t]['last_post_date'])) {
                    $acc[$t]['last_post_date'] = $created_at;
                }
            }
        }

        // Compute relevance and prepare result rows
        $rows = [];
        $now = new DateTime();
        foreach ($acc as $tag => $meta) {
            $last = $meta['last_post_date'] ? new DateTime($meta['last_post_date']) : $now;
            $days = max(0, (int)$now->diff($last)->format('%a'));
            $relevance = ($meta['total_likes'] * 0.5) + ($meta['total_comments'] * 1.0) - ($days * 0.1);
            $rows[] = [
                'tag' => '#' . $tag,
                'post_count' => $meta['post_count'],
                'total_likes' => $meta['total_likes'],
                'total_comments' => $meta['total_comments'],
                'last_post_date' => $meta['last_post_date'],
                'relevance_score' => $relevance
            ];
        }

        // Sort by relevance then post_count
        usort($rows, function($a, $b) {
            if ($a['relevance_score'] == $b['relevance_score']) return $b['post_count'] <=> $a['post_count'];
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        return array_slice($rows, 0, $limit);
    } catch (Exception $e) {
        error_log("Error getting trending tags: " . $e->getMessage());
        return [];
    }
}

// --- Test / Questionnaire persistence helpers (DB-backed) ---
// Helper: check whether a table has a column (defensive for migrations)
function column_exists($table, $column) {
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace("`","",$table) . "` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log('column_exists error: ' . $e->getMessage());
        return false;
    }
}

function create_test_db($user_id, $title, $questions, $thresholds) {
    // Prevent rookie users from creating tests (enforced server-side)
    if (is_user_creation_restricted($user_id)) {
        return ['error' => 'rookie_restricted'];
    }
    $pdo = db_connect();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO tests (user_id, title) VALUES (?, ?)");
        $stmt->execute([$user_id, $title]);
        $test_id = (int)$pdo->lastInsertId();

        // Generate slug for SEO-friendly URL
        $slug = generate_slug($title);
        if ($slug === '') $slug = 'tahlil';
        $slug = $slug . '-' . $test_id;
        // Save slug only if column exists (defensive: migration may not have run yet)
        if (column_exists('tests', 'slug')) {
            $pdo->prepare("UPDATE tests SET slug = ? WHERE id = ?")->execute([$slug, $test_id]);
        } else {
            error_log('create_test_db: slug column missing in tests table; slug not saved for test ' . $test_id);
        }

        $qpos = 0;
        foreach ($questions as $q) {
            $qpos++;
            $stmtQ = $pdo->prepare("INSERT INTO test_questions (test_id, position, question_text) VALUES (?, ?, ?)");
            $stmtQ->execute([$test_id, $qpos, $q['question_text']]);
            $question_id = (int)$pdo->lastInsertId();
            $opos = 0;
            foreach ($q['options'] as $opt) {
                $opos++;
                $stmtO = $pdo->prepare("INSERT INTO question_options (question_id, position, points, label) VALUES (?, ?, ?, ?)");
                $stmtO->execute([$question_id, $opos, (int)$opt['points'], $opt['label']]);
            }
        }

        $tpos = 0;
        foreach ($thresholds as $th) {
            $tpos++;
            $stmtT = $pdo->prepare("INSERT INTO test_thresholds (test_id, position, value, out_text) VALUES (?, ?, ?, ?)");
            $stmtT->execute([$test_id, $tpos, (int)$th['value'], $th['out']]);
        }

        // Update slug to reflect final title & id
        $slug = generate_slug($title);
        if ($slug === '') $slug = 'tahlil';
        $slug = $slug . '-' . $test_id;
        if (column_exists('tests', 'slug')) {
            $pdo->prepare("UPDATE tests SET slug = ? WHERE id = ?")->execute([$slug, $test_id]);
        } else {
            error_log('create_test_db: slug column missing (final update) in tests table; slug not saved for test ' . $test_id);
        }

        $pdo->commit();
        return ['id' => $test_id, 'slug' => $slug];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('create_test_db error: ' . $e->getMessage());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
}

function update_test_db($user_id, $test_id, $title, $questions, $thresholds) {
    // Only allow owner to update
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM tests WHERE id = ? LIMIT 1");
        $stmt->execute([$test_id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) return ['error' => 'not_found'];
        if ((int)$t['user_id'] !== (int)$user_id) return ['error' => 'forbidden'];

        $pdo->beginTransaction();
        // Update title (and set updated_at if column exists)
        if (column_exists('tests', 'updated_at')) {
            $pdo->prepare("UPDATE tests SET title = ?, updated_at = NOW() WHERE id = ?")->execute([$title, $test_id]);
        } else {
            $pdo->prepare("UPDATE tests SET title = ? WHERE id = ?")->execute([$title, $test_id]);
        }
        // Remove existing questions (cascade removes options due to FK)
        $pdo->prepare("DELETE FROM test_questions WHERE test_id = ?")->execute([$test_id]);
        // Remove thresholds
        $pdo->prepare("DELETE FROM test_thresholds WHERE test_id = ?")->execute([$test_id]);

        // Re-insert questions and options
        $qpos = 0;
        foreach ($questions as $q) {
            $qpos++;
            $stmtQ = $pdo->prepare("INSERT INTO test_questions (test_id, position, question_text) VALUES (?, ?, ?)");
            $stmtQ->execute([$test_id, $qpos, $q['question_text']]);
            $question_id = (int)$pdo->lastInsertId();
            $opos = 0;
            foreach ($q['options'] as $opt) {
                $opos++;
                $stmtO = $pdo->prepare("INSERT INTO question_options (question_id, position, points, label) VALUES (?, ?, ?, ?)");
                $stmtO->execute([$question_id, $opos, (int)$opt['points'], $opt['label']]);
            }
        }

        // Re-insert thresholds
        $tpos = 0;
        foreach ($thresholds as $th) {
            $tpos++;
            $stmtT = $pdo->prepare("INSERT INTO test_thresholds (test_id, position, value, out_text) VALUES (?, ?, ?, ?)");
            $stmtT->execute([$test_id, $tpos, (int)$th['value'], $th['out']]);
        }

        // Update slug for SEO (reflect new title)
        $slug = generate_slug($title);
        if ($slug === '') $slug = 'tahlil';
        $slug = $slug . '-' . $test_id;
        if (column_exists('tests', 'slug')) {
            $pdo->prepare("UPDATE tests SET slug = ? WHERE id = ?")->execute([$slug, $test_id]);
        } else {
            error_log('update_test_db: slug column missing in tests table; slug not updated for test ' . $test_id);
        }

        $pdo->commit();
        return ['id' => $test_id, 'slug' => $slug];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('update_test_db error: ' . $e->getMessage());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
}

function get_test_by_id($test_id) {
    $pdo = db_connect();
    // Include author information for nicer single-item views
    $stmt = $pdo->prepare("SELECT t.*, u.username as author_name, u.id as author_id FROM tests t JOIN users u ON t.user_id = u.id WHERE t.id = ? LIMIT 1");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$test) return null;

    // Load questions and options
    $qstmt = $pdo->prepare("SELECT id, position, question_text FROM test_questions WHERE test_id = ? ORDER BY position ASC");
    $qstmt->execute([$test_id]);
    $questions = [];
    while ($q = $qstmt->fetch(PDO::FETCH_ASSOC)) {
        $opts = [];
        $ostmt = $pdo->prepare("SELECT id, position, points, label FROM question_options WHERE question_id = ? ORDER BY position ASC");
        $ostmt->execute([$q['id']]);
        while ($o = $ostmt->fetch(PDO::FETCH_ASSOC)) {
            $opts[] = $o;
        }
        $q['options'] = $opts;
        $questions[] = $q;
    }

    $tstmt = $pdo->prepare("SELECT id, position, value, out_text FROM test_thresholds WHERE test_id = ? ORDER BY position ASC");
    $tstmt->execute([$test_id]);
    $thresholds = $tstmt->fetchAll(PDO::FETCH_ASSOC);

    $test['questions'] = $questions;
    $test['thresholds'] = $thresholds;
    return $test;
}

/**
 * Return aggregated test statistics: attempts, average score, per-question option counts
 */
function get_test_stats($test_id) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, AVG(sum_points) AS avg_score FROM test_attempts WHERE test_id = ?");
    $stmt->execute([$test_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_attempts = (int)($r['total'] ?? 0);
    $avg_score = $r['avg_score'] !== null ? (float)$r['avg_score'] : null;

    $questions = [];
    $qstmt = $pdo->prepare("SELECT id, question_text FROM test_questions WHERE test_id = ? ORDER BY position ASC");
    $qstmt->execute([$test_id]);
    while ($q = $qstmt->fetch(PDO::FETCH_ASSOC)) {
        $opts = [];
        $ostmt = $pdo->prepare("SELECT id, label FROM question_options WHERE question_id = ? ORDER BY position ASC");
        $ostmt->execute([$q['id']]);
        while ($o = $ostmt->fetch(PDO::FETCH_ASSOC)) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM test_answers ta JOIN test_attempts tat ON ta.attempt_id = tat.id WHERE ta.question_id = ? AND ta.option_id = ?");
            $countStmt->execute([$q['id'], $o['id']]);
            $cnt = (int)$countStmt->fetchColumn();
            $opts[] = ['id' => (int)$o['id'], 'label' => $o['label'], 'count' => $cnt, 'percent' => $total_attempts ? round($cnt / $total_attempts * 100, 1) : 0.0];
        }
        $q['options'] = $opts;
        $questions[] = $q;
    }

    return ['total_attempts' => $total_attempts, 'avg_score' => $avg_score, 'questions' => $questions];
}

function get_test_for_post($post_id) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT t.id FROM post_tests pt JOIN tests t ON pt.test_id = t.id WHERE pt.post_id = ? LIMIT 1");
    $stmt->execute([$post_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return get_test_by_id((int)$r['id']);
}

function get_test_for_group_post($group_post_id) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT t.id FROM post_tests pt JOIN tests t ON pt.test_id = t.id WHERE pt.group_post_id = ? LIMIT 1");
    $stmt->execute([$group_post_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return get_test_by_id((int)$r['id']);
}

function record_test_attempt($user_id, $test_id, $answers_by_question_id, $notify_author = true) {
    // $answers_by_question_id: [ question_id => selected_option_id, ... ]
    $pdo = db_connect();
    try {
        $pdo->beginTransaction();

        // Sum points
        $sum = 0;
        foreach ($answers_by_question_id as $qid => $optid) {
            $stmt = $pdo->prepare("SELECT points FROM question_options WHERE id = ? AND question_id = ? LIMIT 1");
            $stmt->execute([$optid, $qid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) $sum += (int)$r['points'];
        }

        // Insert attempt
        $ins = $pdo->prepare("INSERT INTO test_attempts (test_id, user_id, sum_points) VALUES (?, ?, ?)");
        $ins->execute([$test_id, $user_id ?: null, $sum]);
        $attempt_id = (int)$pdo->lastInsertId();

        // Insert answers
        foreach ($answers_by_question_id as $qid => $optid) {
            $pdo->prepare("INSERT INTO test_answers (attempt_id, question_id, option_id) VALUES (?, ?, ?)")->execute([$attempt_id, $qid, $optid]);
        }

        // Determine threshold result
        $tstmt = $pdo->prepare("SELECT value, out_text FROM test_thresholds WHERE test_id = ? ORDER BY value ASC");
        $tstmt->execute([$test_id]);
        $result_out = null;
        $last_out = null;
        while ($th = $tstmt->fetch(PDO::FETCH_ASSOC)) {
            $last_out = $th['out_text'];
            if ($sum <= (int)$th['value']) { $result_out = $th['out_text']; break; }
        }
        if ($result_out === null) $result_out = $last_out ?? '';

        // Notifications: to taker
        try {
            $notif_text = "Test sonucu: '" . htmlspecialchars((string)$result_out, ENT_QUOTES, 'UTF-8') . "' — Toplam puan: " . intval($sum);
            query("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())", [$user_id, $notif_text, null]);
        } catch (Exception $e) {
            error_log('record_test_attempt notify taker error: ' . $e->getMessage());
        }

        // Notify author if requested
        if ($notify_author) {
            try {
                $stmt = $pdo->prepare("SELECT user_id, title FROM tests WHERE id = ? LIMIT 1");
                $stmt->execute([$test_id]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($t && !empty($t['user_id'])) {
                    $author_id = (int)$t['user_id'];
                    // Don't notify the author if they are the taker
                    if ($author_id !== (int)$user_id) {
                        $author_text = "Bir kullanıcı '" . htmlspecialchars($t['title'] ?? 'test', ENT_QUOTES, 'UTF-8') . "' testini tamamladı. Sonuç: " . htmlspecialchars((string)$result_out, ENT_QUOTES, 'UTF-8') . " (Puan: " . intval($sum) . ")";
                        query("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())", [$author_id, $author_text, $user_id]);
                    }
                }
            } catch (Exception $e) {
                error_log('record_test_attempt notify author error: ' . $e->getMessage());
            }
        }

        $pdo->commit();
        return ['sum' => $sum, 'out' => $result_out, 'attempt_id' => $attempt_id];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('record_test_attempt error: ' . $e->getMessage());
        return ['error' => 'db_error', 'message' => $e->getMessage()];
    }
}
