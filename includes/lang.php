<?php /* EN + TR comments used. */
// Set UTF-8 encoding
if (function_exists('mb_internal_encoding')) {
    // mbstring extension may not be installed in this environment
    mb_internal_encoding('UTF-8');
}
header('Content-Type: text/html; charset=utf-8');

// Simple language loader and helper
function load_language($lang = 'tr') {
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/../lang/tr.php';
    }
    $LANG = include $file;
    if (!is_array($LANG)) {
        return [];
    }
    return $LANG;
}

// Load language from session or default to 'tr'
// Use $GLOBALS to ensure availability from global scope even when lang.php
// is required inside a closure (e.g. the router fallback callback).
$GLOBALS['LANG'] = load_language($_SESSION['lang'] ?? 'tr');
$LANG = &$GLOBALS['LANG'];

function t($key, ...$args) {
    global $LANG;
    $str = $LANG[$key] ?? $key;
    if (!empty($args)) {
        return vsprintf($str, $args);
    }
    return $str;
}
?>