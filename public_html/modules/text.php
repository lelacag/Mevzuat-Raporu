<?php
/**
 * Module: text.php — Rich text rendering, linkification, badge styling
 */

if (!function_exists('is_internal_outbound_destination')) {
function is_internal_outbound_destination($u) {
    if (!is_string($u) || $u === '') return false;
    if (!preg_match('#^https?://#i', $u)) return false;
    $dest_host = parse_url($u, PHP_URL_HOST);
    if (!$dest_host) return false;
    $normalize = function($host) {
        return strtolower(preg_replace('/^www\./i', '', $host));
    };
    $dest_host = $normalize($dest_host);
    $current_host = $normalize($_SERVER['HTTP_HOST'] ?? '');
    if ($current_host !== '' && $dest_host === $current_host) {
        return true;
    }
    if (defined('SITE_URL') && !empty(SITE_URL)) {
        $site_host = parse_url(SITE_URL, PHP_URL_HOST);
        if ($site_host && $normalize($site_host) === $dest_host) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('outbound_link_url')) {
function outbound_link_url($url) {
    if (is_internal_outbound_destination($url)) {
        return $url;
    }
    return BASE_PATH . '/outbound.php?u=' . rawurlencode($url);
}
}

if (!function_exists('render_rich_text')) {
/**
 * Render simple rich text markers (BBCode-like) into safe HTML.
 */
function render_rich_text($text) {
    if ($text === null) return '';
    $t = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

    // Ekstra/Spoiler
    $t = preg_replace_callback('/\[(?:spoiler|ekstra)(?:=([^\]]+))?\](.*?)\[\/(?:spoiler|ekstra)\]/is', function($m){
        $label = isset($m[1]) && trim($m[1]) !== '' ? htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') : 'Ekstra';
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

    // @mentions
    $t = preg_replace_callback('/@([A-Za-z0-9_-]+)/u', function($m){
        $username = $m[1];
        $url = profile_url($username);
        $display = htmlspecialchars('@' . $username, ENT_QUOTES, 'UTF-8');
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $t);

    // Code / KOD
    $t = preg_replace_callback('/\[(?:code|kod)(?:=([A-Za-z0-9_+-]+))?\](.*?)\[\/(?:code|kod)\]/is', function($m){
        $lang = $m[1] ? ' language-' . htmlspecialchars($m[1], ENT_QUOTES) : '';
        $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        return '<pre class="code-block"><code class="' . $lang . '">' . $code . '</code></pre>';
    }, $t);

    // Link — route external links through outbound
    $t = preg_replace_callback('/\[link\s+url="(.+?)(?:"|&quot;|&amp;quot;)\](.*?)\[\/link\]/is', function($m){
        $url = trim($m[1]);
        for ($i = 0; $i < 3; $i++) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $url = preg_replace('/(("|\'|\\\\|&quot;|&amp;quot;)+)$/i', '', $url);
        $url = rtrim($url, "\"'\t\n\r");
        if (!preg_match('#^https?://#i', $url)) return htmlspecialchars($m[2], ENT_QUOTES);
        $text = htmlspecialchars($m[2], ENT_QUOTES);
        $out = outbound_link_url($url);
        return '<a class="post-link" href="' . htmlspecialchars($out, ENT_QUOTES) . '" rel="noopener noreferrer nofollow" target="_blank">' . $text . '</a>';
    }, $t);

    // Image embed tags: [image]URL[/image] and [img]URL[/img]
    $t = preg_replace_callback('/\[(image|img)\](.*?)\[\/\1\]/is', function($m){
        $url = trim($m[2]);
        for ($i = 0; $i < 3; $i++) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $url = preg_replace('/(("|\'|\\|&quot;|&amp;quot;)+)$/i', '', $url);
        $url = rtrim($url, "\"'\t\n\r");
        if (!preg_match('#^(?:https?://|//|/)[^\s<>]+$#i', $url)) {
            return htmlspecialchars($m[0], ENT_QUOTES);
        }
        $urlEscaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<img class="announcement-image" src="' . $urlEscaped . '" alt="" loading="lazy">';
    }, $t);

    // Auto-link plain URLs (skip URLs already inside HTML attributes/tags)
    $t = preg_replace_callback('@(?<!["\">\"])https?://[^\s<"]+@i', function($m){
        $u = $m[0];
        $out = outbound_link_url($u);
        $display = htmlspecialchars($u, ENT_QUOTES);
        return '<a href="' . htmlspecialchars($out, ENT_QUOTES) . '" rel="noopener noreferrer nofollow" target="_blank">' . $display . '</a>';
    }, $t);

    // Newlines to <br> except inside code blocks
    $t = preg_replace_callback('#<pre.*?>.*?</pre>#is', function($m){ return str_replace("\n", '__NEWLINE__', $m[0]); }, $t);
    $t = nl2br($t);
    $t = str_replace('__NEWLINE__', "\n", $t);

    return $t;
}
} // end guard: render_rich_text

if (!function_exists('linkify_mentions')) {
function linkify_mentions($text) {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $result = preg_replace_callback('/@([A-Za-z0-9_-]+)/u', function($m) {
        $username = $m[1];
        $url = profile_url($username);
        $display = htmlspecialchars('@' . $username, ENT_QUOTES, 'UTF-8');
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $escaped);
    return $result;
}
} // end guard: linkify_mentions

if (!function_exists('linkify_text')) {
function linkify_text($text) {
    // Normalize any pre-encoded entities so apostrophes don't become &#039; then #039 hashtags
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Mentions
    $escaped = preg_replace_callback('/@([\p{L}\p{N}_-]+(?: [\p{L}\p{N}_-]+)?)/u', function($m) {
        $username = trim($m[1]);
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

    // Hashtags — never match the # inside HTML entities (e.g. &#039; apostrophe)
    $escaped = preg_replace_callback('/(?<!&)#([\p{L}\p{N}_-]+)/u', function($m) {
        $raw = $m[1];
        $tag = rawurlencode($raw);
        $display = htmlspecialchars('#' . $raw, ENT_QUOTES, 'UTF-8');
        $url = BASE_PATH . '/ara?tag=' . $tag;
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
    }, $escaped);

    return $escaped;
}
} // end guard: linkify_text

if (!function_exists('badge_color_to_class')) {
function badge_color_to_class($badge_color) {
    if (empty($badge_color)) return 'green';
    $c = trim(strtolower($badge_color));
    if ($c !== '' && $c[0] !== '#') $c = '#' . $c;
    $map = [
        '#2ecc71' => 'green', '#3498db' => 'blue', '#e74c3c' => 'red',
        '#f39c12' => 'orange', '#9b59b6' => 'purple', '#1abc9c' => 'turquoise',
        '#34495e' => 'darkgray', '#e67e22' => 'orangered',
    ];
    return $map[$c] ?? 'green';
}
} // end guard: badge_color_to_class
