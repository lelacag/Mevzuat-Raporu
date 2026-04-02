<?php
// posts module
if (!function_exists('get_post_by_id')) {
    function get_post_by_id($post_id) {
        return query("SELECT * FROM posts WHERE id = ? LIMIT 1", [$post_id])->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_user_group_posts')) {
    function get_user_group_posts($user_id, $limit = 50, $viewer_id = null) {
        $stmt = query("SELECT gp.id, gp.group_id, gp.user_id, gp.content, gp.created_at, u.username, gt.name as group_name, gt.slug FROM group_posts gp JOIN users u ON gp.user_id = u.id JOIN groups_table gt ON gp.group_id = gt.id WHERE gp.user_id = ? ORDER BY gp.created_at DESC LIMIT ?", [$user_id, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { if (function_exists('get_poll_for_group_post')) { $r['poll'] = get_poll_for_group_post($r['id']); } }
        return $rows;
    }
}

if (!function_exists('get_posts_paginated')) {
    function get_posts_paginated($limit = 40, $viewer_id = null, $after = null, $before = null) {
        $has_next = false;
        $has_prev = false;

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
            $viewer = function_exists('get_user') ? get_user($viewer_id) : null;
            $is_admin = $viewer && (!empty($viewer['role']) && $viewer['role'] === 'admin');

            if ($is_admin) {
                $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL $cursor_condition";
                $params = $cursor_value ? [$viewer_id, $cursor_value, $fetch_limit] : [$viewer_id, $fetch_limit];
            } else {
                $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL"
                    . " AND (u.is_approved = 1 OR u.id = ?"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
                $params = $cursor_value ? [$viewer_id, $viewer_id, $cursor_value, $fetch_limit] : [$viewer_id, $viewer_id, $fetch_limit];
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
            $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL"
                    . " AND (u.is_approved = 1"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
            $params = $cursor_value ? [$cursor_value, $fetch_limit] : [$fetch_limit];

            $stmt = query("\n            SELECT p.*, u.username,\n                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,\n                0 as user_has_liked,\n                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count\n            FROM posts p\n            JOIN users u ON p.user_id = u.id\n            $where\n            ORDER BY p.id $sort\n            LIMIT ?\n        ", $params);
        }

        $results = $stmt->fetchAll();

        if (count($results) > $limit) {
            array_pop($results);
            $has_next = true;
        }

        if ($before) {
            $results = array_reverse($results);
            $has_next = true;
            $has_prev = false;
        } else {
            $has_prev = ($after !== null);
        }

        foreach ($results as &$r) {
            if (function_exists('get_poll_for_post')) { $r['poll'] = get_poll_for_post($r['id']); }
            if (function_exists('get_test_for_post')) { $r['test'] = get_test_for_post($r['id']); }
        }

        return [
            'posts' => $results,
            'has_next' => $has_next,
            'has_prev' => $has_prev,
            'first_id' => count($results) > 0 ? $results[0]['id'] : null,
            'last_id' => count($results) > 0 ? $results[count($results) - 1]['id'] : null
        ];
    }
}

if (!function_exists('get_relevant_posts')) {
    function get_relevant_posts($user_id = null, $limit = 40, $after = null) {
        if (!$user_id) {
            $cursor_condition = $after ? "AND p.id < ?" : "";
            $cursor_param = $after ? [$after, $limit + 1] : [$limit + 1];

            $stmt = query("\n            SELECT p.*, u.username, u.is_premium,\n                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,\n                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count,\n                0 as user_has_liked\n            FROM posts p\n            JOIN users u ON p.user_id = u.id\n            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL\n              AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW())\n              AND (u.is_approved = 1\n                   OR (u.role = 'rookie' AND (\n                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id\n                   ) <= 10)) $cursor_condition\n            ORDER BY p.created_at DESC\n            LIMIT ?\n        ", $cursor_param);

            return $stmt->fetchAll();
        }

        $followed_ids = function_exists('get_followed_user_ids') ? get_followed_user_ids($user_id) : [];
        $followed_str = !empty($followed_ids) ? implode(',', $followed_ids) : '0';
        $favorite_tags = function_exists('get_user_favorite_tags') ? get_user_favorite_tags($user_id, 5) : [];
        $cursor_condition = $after ? "AND p.id < ?" : "";
        $cursor_param = $after ? [$after] : [];

        $tag_conditions = "";
        if (count($favorite_tags) > 0) {
            $tag_likes = array_map(function($t) { return "p.content LIKE '%#" . addslashes($t) . "%'"; }, $favorite_tags);
            $tag_conditions = " OR (" . implode(" OR ", $tag_likes) . ")";
        }

        $stmt = query("\n        SELECT \n            p.*, \n            u.username, \n            u.is_premium,\n            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,\n            (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,\n            (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count,\n            (\n                CASE WHEN p.user_id IN ($followed_str) THEN 100 ELSE 0 END\n                + CASE WHEN p.content LIKE '%#%' $tag_conditions THEN 50 ELSE 0 END\n                + ((SELECT COUNT(*) FROM likes WHERE post_id = p.id) * 0.5)\n                + ((SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) * 1.0)\n                + (DATEDIFF(NOW(), p.created_at) * -2)\n            ) as relevance_score\n        FROM posts p\n        JOIN users u ON p.user_id = u.id\n        WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL\n            AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW() OR p.user_id = ?)\n            AND (u.is_approved = 1 OR u.id = ?\n                 OR (u.role = 'rookie' AND (\n                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id\n                    ) <= 10))\n        $cursor_condition\n        ORDER BY relevance_score DESC, p.created_at DESC\n        LIMIT ?\n    ", array_merge([$user_id, $user_id, $user_id], $cursor_param, [$limit + 1]));

        return $stmt->fetchAll();
    }
}

if (!function_exists('get_relevant_posts_paginated')) {
    function get_relevant_posts_paginated($user_id = null, $limit = 40, $after = null) {
        $all_posts = get_relevant_posts($user_id, $limit, $after);
        $has_next = false;
        if (count($all_posts) > $limit) {
            array_pop($all_posts);
            $has_next = true;
        }
        $posts = $all_posts;
        foreach ($posts as &$ppp) {
            if (function_exists('get_poll_for_post')) { $ppp['poll'] = get_poll_for_post($ppp['id']); }
            if (function_exists('get_test_for_post')) { $ppp['test'] = get_test_for_post($ppp['id']); }
        }
        return [
            'posts' => $posts,
            'has_next' => $has_next,
            'first_id' => count($posts) > 0 ? $posts[0]['id'] : null,
            'last_id' => count($posts) > 0 ? $posts[count($posts) - 1]['id'] : null,
        ];
    }
}

if (!function_exists('get_new_feed_count')) {
    function get_new_feed_count($viewer_id, $since) {
        if (!$viewer_id || !$since) { return 0; }
        $pdo = db_connect();
        $viewer = function_exists('get_user') ? get_user($viewer_id) : null;

        if ($viewer && !empty($viewer['role']) && $viewer['role'] === 'admin') {
            $post_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL AND p.created_at > ?");
            $post_stmt->execute([$since]);
        } else {
            $post_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
                AND (u.is_approved = 1 OR u.id = ?
                     OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                     ) <= 10 ) ) AND p.created_at > ?");
            $post_stmt->execute([$viewer_id, $since]);
        }
        $posts_new = (int)($post_stmt->fetch()['c'] ?? 0);

        $privacyFilter = ($viewer && !empty($viewer['role']) && $viewer['role'] === 'admin') ? '' : 'AND COALESCE(g.is_private,0) = 0';
        $group_stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM group_posts gp JOIN groups_table g ON gp.group_id = g.id WHERE 1=1 $privacyFilter AND gp.created_at > ?");
        $group_stmt->execute([$since]);
        $groups_new = (int)($group_stmt->fetch()['c'] ?? 0);

        return $posts_new + $groups_new;
    }
}

if (!function_exists('get_trending_tags')) {
    function get_trending_tags($limit = 10, $user_id = null) {
        try {
            $pdo = db_connect();
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

                // Simple hashtag extractor
                preg_match_all('/#([\p{L}\p{N}_-]+)/u', $content, $m);
                $tags = array_unique(array_map('strtolower', $m[1] ?? []));
                if (empty($tags)) continue;
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
                    'total_likes' => $meta['total_likes'],
                    'total_comments' => $meta['total_comments'],
                    'last_post_date' => $meta['last_post_date'],
                    'relevance_score' => $relevance
                ];
            }

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
}
