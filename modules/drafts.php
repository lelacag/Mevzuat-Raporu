<?php
/**
 * Module: drafts.php — Session-backed draft helpers, tag/type insertion
 */

if (!function_exists('save_draft')) {
function save_draft($user_id, $content) {
    if (!$user_id) return false;
    if (!isset($_SESSION)) session_start();
    $_SESSION['drafts'][$user_id] = $content;
    return true;
}
}

if (!function_exists('get_draft')) {
function get_draft($user_id) {
    if (!$user_id) return '';
    if (!isset($_SESSION)) session_start();
    return $_SESSION['drafts'][$user_id] ?? '';
}
}

if (!function_exists('insert_tag_into_text')) {
function insert_tag_into_text($draft, $tag_text) {
    $tag = trim((string)$tag_text);
    $tag = ltrim($tag, '#');
    $tag = preg_replace('/[^\p{L}\p{N}_-]/u', '', $tag);
    if ($tag === '') return $draft;
    if (trim($draft) === '') return '#' . $tag . ' ';
    if (preg_match('/(^|\s)#' . preg_quote($tag, '/') . '(\b|$)/u', $draft)) return $draft;
    $draft = rtrim($draft);
    return $draft . ' #' . $tag . ' ';
}
}

if (!function_exists('insert_type_or_append_to_draft')) {
function insert_type_or_append_to_draft($user_id, $insert_type, $fields = []) {
    if (!$user_id) return false;
    $draft = get_draft($user_id);

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
}
