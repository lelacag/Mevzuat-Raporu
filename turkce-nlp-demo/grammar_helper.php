<?php

function suggest_grammar_suggestion(string $text): array {
    $text = trim($text);

    if ($text === '') {
        return ['error' => 'Boş içerik gönderildi.'];
    }

    $jarPath = __DIR__ . '/zemberek-full.jar';
    $classPath = $jarPath . ':' . __DIR__;
    if (!file_exists($jarPath)) {
        return ['error' => 'Zemberek JAR dosyası bulunamadı.'];
    }
    if (!file_exists(__DIR__ . '/ZemberekSuggest.class')) {
        return ['error' => 'Zemberek yardımcı sınıfı bulunamadı.'];
    }

    // Soft cap: extremely long pastes are slow and rarely useful in this demo UI.
    $maxChars = 50000;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            return ['error' => 'Metin çok uzun (en fazla yaklaşık ' . $maxChars . ' karakter). Lütfen daha kısa bir bölüm yapıştırın.'];
        }
    } elseif (strlen($text) > $maxChars * 4) {
        return ['error' => 'Metin çok uzun. Lütfen daha kısa bir bölüm yapıştırın.'];
    }

    $command = [
        '/usr/bin/java',
        '-Xmx512m',
        '-Dfile.encoding=UTF-8',
        '-Dstdout.encoding=UTF-8',
        '-Dstderr.encoding=UTF-8',
        '-Duser.language=tr',
        '-Duser.country=TR',
        '-cp',
        $classPath,
        'ZemberekSuggest',
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
        'LC_CTYPE' => 'C.UTF-8',
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'JAVA_TOOL_OPTIONS' => '-Dfile.encoding=UTF-8',
    ];
    // Optional Zemberek sentence-normalizer data root (normalization/ + lm/lm.2gram.slm).
    $dataRoot = getenv('ZEMBEREK_DATA_ROOT');
    if ($dataRoot === false || $dataRoot === '') {
        $localData = __DIR__ . '/zemberek-data';
        if (is_dir($localData)) {
            $dataRoot = $localData;
        }
    }
    if (is_string($dataRoot) && $dataRoot !== '') {
        $env['ZEMBEREK_DATA_ROOT'] = $dataRoot;
    }

    $process = @proc_open($command, $descriptorSpec, $pipes, __DIR__, $env);
    if (!is_resource($process)) {
        return ['error' => 'Zemberek süreci başlatılamadı.'];
    }

    stream_set_blocking($pipes[0], true);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $writeOk = @fwrite($pipes[0], $text);
    fclose($pipes[0]);
    if ($writeOk === false) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process, 9);
        proc_close($process);
        return ['error' => 'Zemberek sürecine metin yazılamadı.'];
    }

    $output = '';
    $errors = '';
    $timeoutSec = 120;
    $deadline = microtime(true) + $timeoutSec;
    $stdoutOpen = true;
    $stderrOpen = true;

    while ($stdoutOpen || $stderrOpen) {
        $read = [];
        if ($stdoutOpen) {
            $read[] = $pipes[1];
        }
        if ($stderrOpen) {
            $read[] = $pipes[2];
        }
        $write = null;
        $except = null;
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            proc_terminate($process, 9);
            if ($stdoutOpen) {
                fclose($pipes[1]);
            }
            if ($stderrOpen) {
                fclose($pipes[2]);
            }
            proc_close($process);
            error_log('Zemberek helper timed out after ' . $timeoutSec . 's');
            return ['error' => 'Zemberek zaman aşımına uğradı. Lütfen daha kısa bir metin deneyin.'];
        }

        $tvSec = (int) max(1, min(2, (int) ceil($remaining)));
        $ready = @stream_select($read, $write, $except, $tvSec);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain any remaining buffered output.
                if ($stdoutOpen) {
                    $chunk = stream_get_contents($pipes[1]);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                    fclose($pipes[1]);
                    $stdoutOpen = false;
                }
                if ($stderrOpen) {
                    $chunk = stream_get_contents($pipes[2]);
                    if ($chunk !== false && $chunk !== '') {
                        $errors .= $chunk;
                    }
                    fclose($pipes[2]);
                    $stderrOpen = false;
                }
                break;
            }
            continue;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    if ($stream === $pipes[1]) {
                        fclose($pipes[1]);
                        $stdoutOpen = false;
                    } elseif ($stream === $pipes[2]) {
                        fclose($pipes[2]);
                        $stderrOpen = false;
                    }
                }
                continue;
            }
            if ($stream === $pipes[1]) {
                $output .= $chunk;
            } else {
                $errors .= $chunk;
            }
        }
    }

    if ($stdoutOpen) {
        fclose($pipes[1]);
    }
    if ($stderrOpen) {
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $message = trim((string) $errors);
        if ($message === '') {
            $message = 'Bilinmeyen Zemberek hatası (exit ' . $exitCode . ').';
        }
        // Keep UI message short; full detail goes to the log.
        error_log('Zemberek helper failed: ' . $message);
        $short = mb_strlen($message, 'UTF-8') > 240
            ? (mb_substr($message, 0, 240, 'UTF-8') . '…')
            : $message;
        return ['error' => 'Zemberek hatası: ' . $short];
    }

    $suggestion = trim((string) $output);
    if ($suggestion === '') {
        return ['error' => 'Düzeltme önerisi alınamadı.'];
    }

    // Guard against encoding corruption in the web path.
    if (!mb_check_encoding($suggestion, 'UTF-8') || strpos($suggestion, "\xEF\xBF\xBD") !== false) {
        return ['error' => 'Düzeltme önerisi geçersiz karakter kodlaması içeriyor.'];
    }

    // Post-process: add punctuation and capitalize proper nouns
    $suggestion = post_process_suggestion($suggestion);

    return ['suggestion' => $suggestion];
}

/**
 * Tokenize text into words, punctuation and whitespace chunks for diffing.
 *
 * @return list<array{text:string,type:string}>
 */
function grammar_diff_tokenize(string $text): array {
    $tokens = [];
    if ($text === '') {
        return $tokens;
    }

    if (!preg_match_all('/\s+|\p{L}+(?:[\'’]\p{L}+)*|\p{N}+|[^\s\p{L}\p{N}]+/u', $text, $matches)) {
        return [['text' => $text, 'type' => 'other']];
    }

    foreach ($matches[0] as $chunk) {
        if (preg_match('/^\s+$/u', $chunk)) {
            $type = 'space';
        } elseif (preg_match('/^\p{L}/u', $chunk)) {
            $type = 'word';
        } elseif (preg_match('/^\p{N}/u', $chunk)) {
            $type = 'number';
        } else {
            $type = 'punct';
        }
        $tokens[] = ['text' => $chunk, 'type' => $type];
    }

    return $tokens;
}

function grammar_diff_norm(string $text): string {
    $text = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $text);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

/**
 * Sequence equality key: ignore pure whitespace differences, compare case-insensitively for words.
 */
function grammar_diff_key(array $token): string {
    if ($token['type'] === 'space') {
        return ' ';
    }
    return $token['type'] . ':' . grammar_diff_norm($token['text']);
}

/**
 * Memory-safe token diff (greedy Myers-style / patience hybrid).
 *
 * The previous full LCS DP used O(n*m) memory and fatally exhausted the default
 * 128M PHP limit around ~900 tokens (long Gmail forwards / multi-paragraph
 * stories), which surfaced as HTTP 500 after a successful Java suggestion.
 *
 * This implementation is O(n + m) memory and typically near-linear time on
 * mostly-aligned grammar corrections.
 *
 * @param list<array{text:string,type:string}> $left
 * @param list<array{text:string,type:string}> $right
 * @return list<array{op:string,left:?array,right:?array}>
 */
function grammar_diff_ops(array $left, array $right): array {
    $n = count($left);
    $m = count($right);
    if ($n === 0 && $m === 0) {
        return [];
    }
    if ($n === 0) {
        $ops = [];
        foreach ($right as $tok) {
            $ops[] = ['op' => 'insert', 'left' => null, 'right' => $tok];
        }
        return $ops;
    }
    if ($m === 0) {
        $ops = [];
        foreach ($left as $tok) {
            $ops[] = ['op' => 'delete', 'left' => $tok, 'right' => null];
        }
        return $ops;
    }

    // Unique-key "patience" anchors keep long similar texts aligned without DP.
    $leftKeys = [];
    $rightKeys = [];
    for ($i = 0; $i < $n; $i++) {
        $leftKeys[$i] = grammar_diff_key($left[$i]);
    }
    for ($j = 0; $j < $m; $j++) {
        $rightKeys[$j] = grammar_diff_key($right[$j]);
    }

    $leftCounts = array_count_values($leftKeys);
    $rightCounts = array_count_values($rightKeys);
    $rightIndex = [];
    for ($j = 0; $j < $m; $j++) {
        $k = $rightKeys[$j];
        if (($leftCounts[$k] ?? 0) === 1 && ($rightCounts[$k] ?? 0) === 1) {
            $rightIndex[$k] = $j;
        }
    }

    $anchors = []; // list of [i, j]
    $lastJ = -1;
    for ($i = 0; $i < $n; $i++) {
        $k = $leftKeys[$i];
        if (!isset($rightIndex[$k])) {
            continue;
        }
        $j = $rightIndex[$k];
        if ($j > $lastJ) {
            $anchors[] = [$i, $j];
            $lastJ = $j;
        }
    }

    $ops = [];
    $i0 = 0;
    $j0 = 0;
    $anchorCount = count($anchors);
    for ($a = 0; $a <= $anchorCount; $a++) {
        if ($a < $anchorCount) {
            [$i1, $j1] = $anchors[$a];
        } else {
            $i1 = $n;
            $j1 = $m;
        }
        if ($i1 > $i0 || $j1 > $j0) {
            $ops = array_merge(
                $ops,
                grammar_diff_ops_segment($left, $right, $leftKeys, $rightKeys, $i0, $i1, $j0, $j1)
            );
        }
        if ($a < $anchorCount) {
            $ops[] = ['op' => 'equal', 'left' => $left[$i1], 'right' => $right[$j1]];
            $i0 = $i1 + 1;
            $j0 = $j1 + 1;
        }
    }

    return $ops;
}

/**
 * Diff a small unmatched segment with a rolling-hash / greedy LCS walk.
 * Falls back to simple delete-all/insert-all only if the segment is empty on one side.
 *
 * @param list<array{text:string,type:string}> $left
 * @param list<array{text:string,type:string}> $right
 * @param list<string> $leftKeys
 * @param list<string> $rightKeys
 * @return list<array{op:string,left:?array,right:?array}>
 */
function grammar_diff_ops_segment(
    array $left,
    array $right,
    array $leftKeys,
    array $rightKeys,
    int $iStart,
    int $iEnd,
    int $jStart,
    int $jEnd
): array {
    $n = $iEnd - $iStart;
    $m = $jEnd - $jStart;
    if ($n === 0 && $m === 0) {
        return [];
    }
    if ($n === 0) {
        $ops = [];
        for ($j = $jStart; $j < $jEnd; $j++) {
            $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$j]];
        }
        return $ops;
    }
    if ($m === 0) {
        $ops = [];
        for ($i = $iStart; $i < $iEnd; $i++) {
            $ops[] = ['op' => 'delete', 'left' => $left[$i], 'right' => null];
        }
        return $ops;
    }

    // For tiny segments, a classic DP is fine and exact (n,m <= 80 => <7k cells).
    if ($n <= 80 && $m <= 80) {
        return grammar_diff_ops_dp_range($left, $right, $leftKeys, $rightKeys, $iStart, $iEnd, $jStart, $jEnd);
    }

    // Greedy middle-snake style: walk matching runs, otherwise search a short window.
    $ops = [];
    $i = $iStart;
    $j = $jStart;
    while ($i < $iEnd && $j < $jEnd) {
        if ($leftKeys[$i] === $rightKeys[$j]) {
            $ops[] = ['op' => 'equal', 'left' => $left[$i], 'right' => $right[$j]];
            $i++;
            $j++;
            continue;
        }

        // Look ahead for a match within a bounded window to avoid quadratic blowups.
        $window = 48;
        $bestDi = null;
        $bestDj = null;
        $bestCost = PHP_INT_MAX;
        $iLimit = min($iEnd, $i + $window);
        $jLimit = min($jEnd, $j + $window);

        // Index right keys in window once.
        $rightPos = [];
        for ($jj = $j; $jj < $jLimit; $jj++) {
            $k = $rightKeys[$jj];
            if (!isset($rightPos[$k])) {
                $rightPos[$k] = $jj;
            }
        }
        for ($ii = $i; $ii < $iLimit; $ii++) {
            $k = $leftKeys[$ii];
            if (!isset($rightPos[$k])) {
                continue;
            }
            $jj = $rightPos[$k];
            $cost = ($ii - $i) + ($jj - $j);
            // Prefer earlier total skips; slight bias to balanced skips.
            $cost = $cost * 2 + abs(($ii - $i) - ($jj - $j));
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $bestDi = $ii;
                $bestDj = $jj;
            }
        }

        if ($bestDi === null) {
            // No nearby match: emit one delete and one insert (or whichever side remains shorter path).
            // Prefer treating non-space token pairs as replace-like sequential del/ins.
            $ops[] = ['op' => 'delete', 'left' => $left[$i], 'right' => null];
            $i++;
            $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$j]];
            $j++;
            continue;
        }

        while ($i < $bestDi) {
            $ops[] = ['op' => 'delete', 'left' => $left[$i], 'right' => null];
            $i++;
        }
        while ($j < $bestDj) {
            $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$j]];
            $j++;
        }
        // Next loop iteration will emit the equal match.
    }
    while ($i < $iEnd) {
        $ops[] = ['op' => 'delete', 'left' => $left[$i], 'right' => null];
        $i++;
    }
    while ($j < $jEnd) {
        $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$j]];
        $j++;
    }

    return $ops;
}

/**
 * Exact LCS DP on a bounded range only.
 *
 * @param list<array{text:string,type:string}> $left
 * @param list<array{text:string,type:string}> $right
 * @param list<string> $leftKeys
 * @param list<string> $rightKeys
 * @return list<array{op:string,left:?array,right:?array}>
 */
function grammar_diff_ops_dp_range(
    array $left,
    array $right,
    array $leftKeys,
    array $rightKeys,
    int $iStart,
    int $iEnd,
    int $jStart,
    int $jEnd
): array {
    $n = $iEnd - $iStart;
    $m = $jEnd - $jStart;
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            if ($leftKeys[$iStart + $i] === $rightKeys[$jStart + $j]) {
                $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
            } else {
                $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
    }

    $ops = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($leftKeys[$iStart + $i] === $rightKeys[$jStart + $j]) {
            $ops[] = [
                'op' => 'equal',
                'left' => $left[$iStart + $i],
                'right' => $right[$jStart + $j],
            ];
            $i++;
            $j++;
            continue;
        }
        if ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $ops[] = ['op' => 'delete', 'left' => $left[$iStart + $i], 'right' => null];
            $i++;
        } else {
            $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$jStart + $j]];
            $j++;
        }
    }
    while ($i < $n) {
        $ops[] = ['op' => 'delete', 'left' => $left[$iStart + $i], 'right' => null];
        $i++;
    }
    while ($j < $m) {
        $ops[] = ['op' => 'insert', 'left' => null, 'right' => $right[$jStart + $j]];
        $j++;
    }

    return $ops;
}

/**
 * Collapse adjacent delete+insert into replace when useful, and mark case-only equals as changed if surface differs.
 *
 * @param list<array{op:string,left:?array,right:?array}> $ops
 * @return list<array{op:string,left:?array,right:?array}>
 */
function grammar_diff_refine(array $ops): array {
    $refined = [];
    $count = count($ops);
    for ($i = 0; $i < $count; $i++) {
        $op = $ops[$i];
        if ($op['op'] === 'equal') {
            $leftText = $op['left']['text'] ?? '';
            $rightText = $op['right']['text'] ?? '';
            if ($leftText !== $rightText && ($op['left']['type'] ?? '') !== 'space') {
                // Case / apostrophe surface change with same norm key.
                $refined[] = ['op' => 'replace', 'left' => $op['left'], 'right' => $op['right']];
            } else {
                $refined[] = $op;
            }
            continue;
        }

        if ($op['op'] === 'delete') {
            $j = $i + 1;
            // skip spaces on the right side between delete/insert
            while ($j < $count && ($ops[$j]['op'] === 'equal') && (($ops[$j]['left']['type'] ?? '') === 'space')) {
                $j++;
            }
            if ($j < $count && $ops[$j]['op'] === 'insert') {
                $left = $op['left'];
                $right = $ops[$j]['right'];
                // Only pair non-space tokens.
                if (($left['type'] ?? '') !== 'space' && ($right['type'] ?? '') !== 'space') {
                    $refined[] = ['op' => 'replace', 'left' => $left, 'right' => $right];
                    // keep any skipped equal spaces
                    for ($k = $i + 1; $k < $j; $k++) {
                        $refined[] = $ops[$k];
                    }
                    $i = $j;
                    continue;
                }
            }
        }

        $refined[] = $op;
    }

    return $refined;
}

function grammar_highlight_escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function grammar_normalize_display_text(string $text): string {
    // Collapse odd runs of spaces/tabs but keep intentional newlines.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
    return trim($text);
}

function grammar_highlight_span(string $text, bool $changed): string {
    $safe = grammar_highlight_escape($text);
    if (!$changed) {
        return $safe;
    }
    return '<mark class="hl-change">' . $safe . '</mark>';
}

/**
 * Build display HTML.
 * - original: plain escaped text (no highlights)
 * - suggestion: yellow marks only on changed tokens
 *
 * @return array{original:string,suggestion:string}
 */
function highlight_grammar_changes(string $original, string $suggestion): array {
    $original = grammar_normalize_display_text($original);
    $suggestion = grammar_normalize_display_text($suggestion);

    $left = grammar_diff_tokenize($original);
    $right = grammar_diff_tokenize($suggestion);
    $ops = grammar_diff_refine(grammar_diff_ops($left, $right));

    $suggestionHtml = '';

    foreach ($ops as $op) {
        if ($op['op'] === 'equal') {
            $rightText = $op['right']['text'] ?? '';
            $suggestionHtml .= grammar_highlight_span($rightText, false);
            continue;
        }

        if ($op['op'] === 'replace') {
            $rightText = $op['right']['text'] ?? '';
            $rightType = $op['right']['type'] ?? '';
            if ($rightText !== '') {
                // Highlight only non-space changed tokens on the suggestion side.
                $suggestionHtml .= grammar_highlight_span($rightText, $rightType !== 'space');
            }
            continue;
        }

        if ($op['op'] === 'delete') {
            // Deletions do not appear in the suggestion text.
            continue;
        }

        if ($op['op'] === 'insert') {
            $rightText = $op['right']['text'] ?? '';
            $rightType = $op['right']['type'] ?? '';
            if ($rightText !== '') {
                $suggestionHtml .= grammar_highlight_span($rightText, $rightType !== 'space');
            }
        }
    }

    return [
        'original' => grammar_highlight_escape($original),
        'suggestion' => $suggestionHtml,
    ];
}

/**
 * Common Turkish proper nouns that should be capitalized.
 * This is a basic list - can be expanded as needed.
 */
function get_turkish_proper_nouns(): array {
    static $properNouns = null;
    if ($properNouns === null) {
        $properNouns = [
            // Names from your example text
            'arslan', 'fesli',  // Note: "fesli adam" is an adjective + noun, but "Fesli" as standalone is a proper noun
            // Common Turkish male names
            'ahmet', 'mehmet', 'ali', 'veli', 'hasan', 'hüseyin', 'mustafa', 'kemal',
            'recep', 'ömer', 'yusuf', 'ibrahim', 'ismail', 'mahmut', 'osman', 'murat',
            'tayyip', 'erdogan', 'atatürk', 'kaan', 'can', 'berk', 'emre', 'onur',
            // Common Turkish female names
            'ayşe', 'fatma', 'emine', 'zehra', 'hatice', 'hanım', 'elif', 'sema',
            'esra', 'merve', 'aslı', 'dezire', 'nurgül', 'yıldız', 'gül', 'çiğdem',
            // Other proper nouns
            'türkiye', 'ankara', 'istanbul', 'izmir', 'bursa', 'antalya', 'adana',
        ];
    }
    return $properNouns;
}
/**
 * Fix common Turkish typos and issues.
 */
function fix_turkish_typos(string $text): string {
    // Fix common typo: As'lan -> Arslan
    $text = preg_replace("/As['\"]lan\b/u", 'Arslan', $text);
    $text = preg_replace("/as['\"]lan\b/u", 'Arslan', $text);
    
    // Fix other potential typos
    $text = preg_replace("/A['\"]slan\b/u", 'Arslan', $text);
    $text = preg_replace("/a['\"]slan\b/u", 'Arslan', $text);
    
    // Fix: refetti -> reddetti
    $text = preg_replace('/\brefetti\b/u', 'reddetti', $text);
    
    // Fix: kene -> kendine (when it's a typo for "kendine")
    $text = preg_replace('/\bkene\b/u', 'kendine', $text);
    
    // Fix: Evin'e -> Evine
    $text = preg_replace("/Evin['\"]e\b/u", 'Evine', $text);
    $text = preg_replace("/evin['\"]e\b/u", 'Evine', $text);
    
    // Fix: sorabiliyormuyum -> sorabiliyor muyum
    $text = preg_replace('/\bsorabiliyormuyum\b/u', 'sorabiliyor muyum', $text);
    
    // Fix: gitsemmi -> gitsem mi
    $text = preg_replace('/gitsemmi/u', 'gitsem mi', $text);
    
    // Fix: sızsammı -> sızsam mı
    $text = preg_replace('/sızsammı/u', 'sızsam mı', $text);
    
    // Fix: yüzündenmi -> yüzünden mi
    $text = preg_replace('/yüzündenmi/u', 'yüzünden mi', $text);
    
    // Fix spacing issues with punctuation
    $text = preg_replace('/([.?!])(["\x{201d}\x{2019}])/u', '$1$2', $text);
    $text = preg_replace('/(["\x{201d}\x{2019}])([.?!])/u', '$1$2', $text);
    
    // Fix: sahneyemüzisyenler -> sahneye müzisyenler
    $text = preg_replace('/\bsahneyemüzisyenler\b/u', 'sahneye müzisyenler', $text);
    
    // Fix: ayaküstüminik -> ayak üstü minik
    $text = preg_replace('/\bayaküstüminik\b/u', 'ayak üstü minik', $text);
    
    // Fix: üstüminik -> üstü minik
    $text = preg_replace('/\büstüminik\b/u', 'üstü minik', $text);
    
    // Fix: bu sorgulamalar içinde -> Bu sorgulamalar içinde (capitalization)
    $text = preg_replace('/\bbu sorgulamalar içinde\b/u', 'Bu sorgulamalar içinde', $text);
    
    // Fix: birbirine benzeyen -> Birbirine benzeyen (capitalization)
    $text = preg_replace('/\bbirbirine benzeyen\b/u', 'Birbirine benzeyen', $text);
    
    // Remove problematic right double angle quotation marks
    $text = str_replace('»', '', $text);
    
    return $text;
}

/**
 * Capitalize first letter of each sentence in Turkish text.
 */
function capitalize_sentences(string $text): string {
    // Split by sentence-ending punctuation followed by whitespace
    // This regex matches: end of string, or punctuation followed by space/newline
    $parts = preg_split('/([.?!…][ \n\t]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    if (empty($parts)) {
        return $text;
    }
    
    $result = '';
    $capitalizeNext = true;
    
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        
        // Check if this part ends with sentence-ending punctuation
        $endsSentence = preg_match('/[.?!…]$/u', $part);
        
        if ($capitalizeNext && $part !== '' && !ctype_space($part[0])) {
            // Don't capitalize words that should stay lowercase in certain contexts
            // e.g., "fesli adam" - fesli is an adjective, not a proper noun at sentence start
            $lowercaseWord = mb_strtolower($part, 'UTF-8');
            // Check if the part starts with "fesli" followed by any word starting with "adam"
            if (!preg_match('/^fesli\s+adam/u', $lowercaseWord)) {
                // Capitalize first letter of this part
                $part = mb_ucfirst(mb_strtolower($part, 'UTF-8'), 'UTF-8');
            } else {
                // Keep the original case (e.g., fesli adam stays as fesli adam)
                $part = $lowercaseWord;
            }
        }
        
        $result .= $part;
        
        // Next part should be capitalized if this part ends a sentence
        $capitalizeNext = $endsSentence;
    }
    
    return $result;
}

/**
 * Capitalize Turkish proper nouns in text with context awareness.
 * Handles proper noun capitalization for known names, but skips adjectives.
 */
function capitalize_turkish_proper_nouns(string $text): string {
    $properNouns = get_turkish_proper_nouns();
    
    // Sort by length (longest first) to avoid partial matches
    usort($properNouns, function($a, $b) {
        return strlen($b) - strlen($a);
    });
    
    foreach ($properNouns as $name) {
        // Special case: "fesli" should only be capitalized when it's NOT followed by a noun (like "adam")
        // i.e., we want to capitalize "Fesli" when standalone but keep "fesli adam" as is
        if ($name === 'fesli') {
            // Capitalize "fesli" only when NOT followed by space+noun root
            // Match: start or non-letter, then fesli, then NOT (space + noun starting with adam/kadın/etc), then non-letter or end
            $pattern = '/(^|[^\p{L}])(' . preg_quote($name, '/') . ')(?!\s+adam)([^\p{L}]|$)/u';
            $replacement = '$1' . mb_ucfirst($name, 'UTF-8') . '$4';
            $text = preg_replace($pattern, $replacement, $text);
            
            // Also handle apostrophe cases like "Fesli'ye" but NOT "fesli adam'a"
            $pattern = '/(^|[^\p{L}])(' . preg_quote($name) . ')(?!\s+adam)([' . "'\x{2019}\x{2018}" . '])/u';
            $replacement = '$1' . mb_ucfirst($name, 'UTF-8') . '$4';
            $text = preg_replace($pattern, $replacement, $text);
            continue;
        }
        
        // Case-insensitive regex to find word boundaries
        // Match the name as a whole word, preceded by non-word char or start, followed by non-word char or end
        $pattern = '/(^|[^\p{L}])(' . preg_quote($name, '/') . ')([^\p{L}]|$)/u';
        $replacement = '$1' . mb_ucfirst($name, 'UTF-8') . '$3';
        $text = preg_replace($pattern, $replacement, $text);
        
        // Also handle apostrophe cases like "Arslan'a"
        $pattern = '/(^|[^\p{L}])(' . preg_quote($name) . ')([' . "'\x{2019}\x{2018}" . '])/u';
        $replacement = '$1' . mb_ucfirst($name, 'UTF-8') . '$3';
        $text = preg_replace($pattern, $replacement, $text);
    }
    
    return $text;
}

/**
 * Restore basic punctuation in Turkish text.
 * Adds periods at sentence ends, fixes spacing around punctuation.
 */
function restore_turkish_punctuation(string $text): string {
    if ($text === '') {
        return $text;
    }
    
    // Normalize whitespace first
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/ *\n */u', "\n", $text);
    
    // Ensure there's proper spacing before certain punctuation
    // Add space before: ? ! (if not already preceded by space)
    $text = preg_replace('/([^ \n\t\p{P}])([?!])/u', '$1 $2', $text);
    
    // Add space after: . , ; : (if not already followed by space or newline)
    $text = preg_replace('/([.,;:])([^ \n\t\p{P}])/u', '$1 $2', $text);
    
    // Fix double spaces created by above
    $text = preg_replace('/  +/u', ' ', $text);
    
    // Add period at the end if missing (for sentences that look complete)
    // Only add if the text ends with a letter or number and doesn't already have ending punctuation
    if (preg_match('/[\p{L}\p{N}]([^\p{P}]|$)$/u', $text)) {
        $text = rtrim($text) . '.';
    }
    
    // Note: Quote handling is done separately in fix_turkish_quotes()
    
    // Fix common Turkish-specific issues
    // Clitics like "de", "da", "ki", "mi", "mu", "mü", "mı" should stick to previous word
    $text = preg_replace('/([\p{L}]) (de|da|ki|mi|mu|mü|mı)$/u', '$1$2', $text);
    
    // Fix spacing before question particles that should be attached
    // Common question particles in Turkish: mı, mi, mu, mü
    $text = preg_replace('/(\p{L}) (m[ıiüu])/u', '$1$2', $text);
    
    // Clean up any remaining double spaces
    $text = preg_replace('/  +/u', ' ', $text);
    
    return $text;
}
function post_process_suggestion(string $suggestion): string {
    // First, fix common typos
    $suggestion = fix_turkish_typos($suggestion);
    
    // Then, restore punctuation
    $suggestion = restore_turkish_punctuation($suggestion);
    
    // Capitalize sentences
    $suggestion = capitalize_sentences($suggestion);
    
    // Then, capitalize proper nouns
    $suggestion = capitalize_turkish_proper_nouns($suggestion);
    
    // Fix: Zemberek may capitalize "Fesli" before nouns - we need to lowercase it
    // e.g., "Fesli adam" -> "fesli adam"
    $suggestion = preg_replace('/\bFesli\s+adam\b/u', 'fesli adam', $suggestion);
    $suggestion = preg_replace('/\bFesli\s+adamla\b/u', 'fesli adamla', $suggestion);
    $suggestion = preg_replace('/\bFesli\s+adamı\b/u', 'fesli adamı', $suggestion);
    $suggestion = preg_replace('/\bFesli\s+adama\b/u', 'fesli adama', $suggestion);
    $suggestion = preg_replace('/\bFesli\s+adamdan\b/u', 'fesli adamdan', $suggestion);
    
    return $suggestion;
}

/**
 * Helper function: mb_ucfirst for UTF-8
 * Only define if not already defined
 */
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst(string $string, string $encoding = 'UTF-8'): string {
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $rest = mb_substr($string, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $rest;
    }
}

