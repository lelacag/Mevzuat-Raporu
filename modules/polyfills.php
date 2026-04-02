<?php
// polyfills module
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
        return strtolower($s);
    }
}

if (!function_exists('mb_strrev')) {
    function mb_strrev($str) {
        return strrev($str);
    }
}
