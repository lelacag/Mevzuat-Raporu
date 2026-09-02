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
        static $bad_words_cache = null;
        if ($bad_words_cache === null) {
            $stmt = query("SELECT word FROM bad_words ORDER BY word ASC");
            $bad_words_cache = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        $threshold = get_similarity_threshold();
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $suspicious_matches = [];

        foreach ($words as $word) {
            $clean_word = preg_replace('/[^\p{L}\p{N}\s\-._@!|€$]/u', '', $word);
            $clean_word_lower = mb_strtolower($clean_word);

            if (mb_strlen($clean_word) <= 3 || is_word_approved($clean_word)) {
                continue;
            }

            $word_variants = get_word_variants($clean_word);
            foreach ($bad_words_cache as $bad_word) {
                $bad_word_lower = mb_strtolower($bad_word);
                if ($clean_word_lower === $bad_word_lower) {
                    continue;
                }

                foreach ($word_variants as $variant) {
                    if (mb_strlen($bad_word) >= 3 && mb_strpos($variant, $bad_word_lower) !== false) {
                        $suspicious_matches[] = [
                            'bad_word' => $bad_word,
                            'found_word' => $clean_word,
                            'similarity' => 100.0,
                            'match_type' => 'contains',
                            'variant_used' => $variant !== $clean_word_lower ? $variant : null
                        ];
                        break 2;
                    }

                    $similarity = calculate_similarity($variant, $bad_word);
                    if ($similarity >= $threshold) {
                        $suspicious_matches[] = [
                            'bad_word' => $bad_word,
                            'found_word' => $clean_word,
                            'similarity' => round($similarity, 1),
                            'match_type' => 'similar',
                            'variant_used' => $variant !== $clean_word_lower ? $variant : null
                        ];
                        break 2;
                    }
                }
            }
        }

        return [
            'suspicious' => !empty($suspicious_matches),
            'matched_words' => $suspicious_matches
        ];
    }
}
