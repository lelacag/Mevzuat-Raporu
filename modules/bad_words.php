<?php
// bad_words module
if (!function_exists('filter_bad_words')) {
    function filter_bad_words($text) {
        static $bad_words_cache = null;
        if ($bad_words_cache === null) {
            $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
            $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }
        foreach ($bad_words_cache as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $text)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('censor_bad_words')) {
    function censor_bad_words($text) {
        static $bad_words_cache = null;
        if ($bad_words_cache === null) {
            $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
            $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        $has_bad_words = false;
        $clean_text = $text;
        foreach ($bad_words_cache as $word) {
            $regex = '/\b' . preg_quote($word, '/') . '\b/iu';
            if (preg_match($regex, $clean_text)) {
                $has_bad_words = true;
                $clean_text = preg_replace_callback($regex, function($matches) {
                    return str_repeat('*', mb_strlen($matches[0]));
                }, $clean_text);
            }
        }
        return ['clean' => $clean_text, 'has_bad_words' => $has_bad_words];
    }
}

if (!function_exists('check_suspicious_content')) {
    function check_suspicious_content($text) {
        // Placeholder: can include advanced heuristics later.
        return filter_bad_words($text);
    }
}
