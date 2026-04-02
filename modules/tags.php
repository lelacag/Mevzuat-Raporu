<?php
/**
 * Module: tags.php — Hashtag extraction, tag clicks, trending tags
 */

if (!function_exists('ensure_tag_clicks_table')) {
function ensure_tag_clicks_table() {
    static $ensured = false;
    if ($ensured) return;
    try {
        query("CREATE TABLE IF NOT EXISTS tag_clicks (
            tag VARCHAR(100) PRIMARY KEY,
            click_count INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) { /* ignore */ }
    $ensured = true;
}
} // end guard

if (!function_exists('normalize_tag')) {
function normalize_tag($tag) {
    $tag = trim($tag);
    $tag = ltrim($tag, "#");
    return mb_strtolower($tag, 'UTF-8');
}
} // end guard

if (!function_exists('record_tag_click')) {
function record_tag_click($tag) {
    $t = normalize_tag($tag);
    if ($t === '') return;
    ensure_tag_clicks_table();
    try {
        query("INSERT INTO tag_clicks (tag, click_count) VALUES (?, 1)
               ON DUPLICATE KEY UPDATE click_count = click_count + 1", [$t]);
    } catch (Exception $e) { /* ignore */ }
}
} // end guard

if (!function_exists('extract_hashtags_from_text')) {
function extract_hashtags_from_text($text) {
    $tags = [];
    if (preg_match_all('/#([\p{L}\p{N}_-]+)/u', (string)$text, $m)) {
        foreach ($m[1] as $raw) {
            $tags[] = normalize_tag($raw);
        }
    }
    return $tags;
}
} // end guard

if (!function_exists('get_top_tags')) {
function get_top_tags($limit = 10) {
    $postRows = [];
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT content FROM posts WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 1000");
        $stmt->execute();
        $postRows = array_merge($postRows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { }

    try {
        $stmt = $pdo->prepare("SELECT gp.content FROM group_posts gp JOIN groups_table g ON gp.group_id = g.id WHERE g.is_private = 0 ORDER BY gp.created_at DESC LIMIT 1000");
        $stmt->execute();
        $postRows = array_merge($postRows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { }

    $postCounts = [];
    foreach ($postRows as $r) {
        $tags = extract_hashtags_from_text($r['content'] ?? '');
        foreach (array_unique($tags) as $t) {
            if ($t === '') continue;
            $postCounts[$t] = ($postCounts[$t] ?? 0) + 1;
        }
    }

    ensure_tag_clicks_table();
    $clickCounts = [];
    try {
        $stmt = query("SELECT tag, click_count FROM tag_clicks ORDER BY click_count DESC LIMIT 200");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clickCounts[$row['tag']] = (int)$row['click_count'];
        }
    } catch (Exception $e) { }

    $scores = [];
    foreach ($postCounts as $t => $c) { $scores[$t] = ($scores[$t] ?? 0) + $c; }
    foreach ($clickCounts as $t => $c) { $scores[$t] = ($scores[$t] ?? 0) + 2 * $c; }
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
} // end guard

if (!function_exists('get_trending_tags_for_group')) {
function get_trending_tags_for_group($group_id, $limit = 10) {
    $pdo = db_connect();
    try {
        $stmt = $pdo->prepare("SELECT gp.content, gp.created_at,
            (SELECT COUNT(*) FROM group_post_likes l WHERE l.post_id = gp.id) as likes_count,
            (SELECT COUNT(*) FROM group_post_comments c WHERE c.post_id = gp.id) as comments_count
            FROM group_posts gp WHERE gp.group_id = ? ORDER BY gp.created_at DESC LIMIT 1000");
        $stmt->execute([$group_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $acc = [];
        foreach ($rows as $p) {
            $tags = extract_hashtags_from_text($p['content'] ?? '');
            if (empty($tags)) continue;
            $tags = array_unique($tags);
            $likes = (int)($p['likes_count'] ?? 0);
            $comments = (int)($p['comments_count'] ?? 0);
            foreach ($tags as $t) {
                if ($t === '') continue;
                if (!isset($acc[$t])) $acc[$t] = ['post_count' => 0, 'total_likes' => 0, 'total_comments' => 0, 'last_post_date' => null];
                $acc[$t]['post_count']++;
                $acc[$t]['total_likes'] += $likes;
                $acc[$t]['total_comments'] += $comments;
                if (is_null($acc[$t]['last_post_date']) || strtotime($p['created_at']) > strtotime($acc[$t]['last_post_date'])) {
                    $acc[$t]['last_post_date'] = $p['created_at'];
                }
            }
        }

        $rows = [];
        $now = new DateTime();
        foreach ($acc as $tag => $meta) {
            $last = $meta['last_post_date'] ? new DateTime($meta['last_post_date']) : $now;
            $days = max(0, (int)$now->diff($last)->format('%a'));
            $relevance = ($meta['total_likes'] * 0.5) + ($meta['total_comments'] * 1.0) - ($days * 0.1);
            $rows[] = ['tag' => '#' . $tag, 'post_count' => $meta['post_count'], 'relevance_score' => $relevance];
        }
        usort($rows, function($a, $b) {
            if ($a['relevance_score'] == $b['relevance_score']) return $b['post_count'] <=> $a['post_count'];
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
        return array_slice($rows, 0, $limit);
    } catch (Exception $e) { return []; }
}
} // end guard
