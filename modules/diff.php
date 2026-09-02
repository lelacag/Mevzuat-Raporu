<?php
/**
 * Module: diff.php — Word-level diff rendering (inline, old, new columns)
 */

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

    $ops = [];
    $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $ops[] = ['op'=>'=','text'=>$a[$i]]; $i++; $j++; }
        elseif ($dp[$i+1][$j] >= $dp[$i][$j+1]) { $ops[] = ['op'=>'-','text'=>$a[$i]]; $i++; }
        else { $ops[] = ['op'=>'+','text'=>$b[$j]]; $j++; }
    }
    while ($i < $n) { $ops[] = ['op'=>'-','text'=>$a[$i++]]; }
    while ($j < $m) { $ops[] = ['op'=>'+','text'=>$b[$j++]]; }

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
    $old = trim((string)$old); $new = trim((string)$new);
    if ($old === '' && $new === '') return '';
    $a = $old === '' ? [] : preg_split('/\s+/', $old);
    $b = $new === '' ? [] : preg_split('/\s+/', $new);
    $n = count($a); $m = count($b);
    $dp = array_fill(0, $n+1, array_fill(0, $m+1, 0));
    for ($i = $n-1; $i >= 0; $i--) for ($j = $m-1; $j >= 0; $j--) {
        if ($a[$i] === $b[$j]) $dp[$i][$j] = $dp[$i+1][$j+1] + 1;
        else $dp[$i][$j] = max($dp[$i+1][$j], $dp[$i][$j+1]);
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
    $old = trim((string)$old); $new = trim((string)$new);
    if ($old === '' && $new === '') return '';
    $a = $old === '' ? [] : preg_split('/\s+/', $old);
    $b = $new === '' ? [] : preg_split('/\s+/', $new);
    $n = count($a); $m = count($b);
    $dp = array_fill(0, $n+1, array_fill(0, $m+1, 0));
    for ($i = $n-1; $i >= 0; $i--) for ($j = $m-1; $j >= 0; $j--) {
        if ($a[$i] === $b[$j]) $dp[$i][$j] = $dp[$i+1][$j+1] + 1;
        else $dp[$i][$j] = max($dp[$i+1][$j], $dp[$i][$j+1]);
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

if (!function_exists('generate_slug')) {
function generate_slug($text) {
    $text = trim((string)$text);
    if ($text === '') return '';
    $trans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $trans = strtolower($trans);
    $trans = preg_replace('/[^a-z0-9]+/', '-', $trans);
    $trans = trim($trans, '-');
    $trans = substr($trans, 0, 200);
    if ($trans === '') $trans = 'item';
    return $trans;
}
}
