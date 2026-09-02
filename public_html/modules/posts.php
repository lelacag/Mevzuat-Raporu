<?php
// posts module

// Batch-load recursive comment counts for multiple posts (single CTE query)
// Replaces per-post count_replies_recursive(get_replies(...)) N+1 pattern
if (!function_exists('batch_get_recursive_comment_counts')) {
    function batch_get_recursive_comment_counts(array $post_ids) {
        if (empty($post_ids)) return [];
        $pdo = db_connect();
        $ids = array_map('intval', $post_ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // One recursive CTE fetches all descendant replies, then PHP walks parent chains
        try {
            $stmt = $pdo->prepare("
                WITH RECURSIVE reply_tree AS (
                    SELECT id, parent_id
                    FROM posts
                    WHERE parent_id IN ($ph) AND deleted_at IS NULL
                    UNION ALL
                    SELECT p.id, p.parent_id
                    FROM posts p
                    JOIN reply_tree rt ON p.parent_id = rt.id
                    WHERE p.deleted_at IS NULL
                )
                SELECT id, parent_id FROM reply_tree
            ");
            $stmt->execute(array_values($ids));
            $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('batch_get_recursive_comment_counts error: ' . $e->getMessage());
            return [];
        }
        if (empty($all_rows)) return array_fill_keys($ids, 0);
        // Build parent lookup and trace each node to its root post
        $parent_map = [];
        foreach ($all_rows as $row) {
            $parent_map[(int)$row['id']] = (int)$row['parent_id'];
        }
        $root_set = array_flip($ids); // set of root post IDs
        $counts = array_fill_keys($ids, 0);
        $root_cache = [];
        foreach ($parent_map as $node_id => $pid) {
            $cur = $pid;
            $chain = [$node_id];
            while ($cur && !isset($root_set[$cur])) {
                if (isset($root_cache[$cur])) { $cur = $root_cache[$cur]; break; }
                $chain[] = $cur;
                $cur = $parent_map[$cur] ?? null;
            }
            if ($cur && isset($root_set[$cur])) {
                $counts[$cur]++;
                foreach ($chain as $c) { $root_cache[$c] = $cur; }
            }
        }
        return $counts;
    }
}

// Batch-load polls for multiple posts at once (avoids N+1 queries in feed)
if (!function_exists('batch_get_polls_for_posts')) {
    function batch_get_polls_for_posts(array $post_ids) {
        if (empty($post_ids)) return [];
        $pdo = db_connect();
        $result = [];
        try {
            $ids = array_map('intval', $post_ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Fetch all polls for these posts
            $stmt = $pdo->prepare("SELECT * FROM polls WHERE post_id IN ($ph)");
            $stmt->execute($ids);
            $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($polls)) return [];
            $poll_ids = array_column($polls, 'id');
            $polls_by_id = array_column($polls, null, 'id');
            $polls_by_post = array_column($polls, null, 'post_id');
            // Fetch all options for these polls
            $oph = implode(',', array_fill(0, count($poll_ids), '?'));
            $opts_stmt = $pdo->prepare("SELECT id, poll_id, text, votes_count FROM poll_options WHERE poll_id IN ($oph) ORDER BY id ASC");
            $opts_stmt->execute($poll_ids);
            $all_opts = $opts_stmt->fetchAll(PDO::FETCH_ASSOC);
            $opts_by_poll = [];
            foreach ($all_opts as $o) { $opts_by_poll[$o['poll_id']][] = $o; }
            // Fetch user votes if logged in
            $user_id = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $votes_by_poll = [];
            if ($user_id) {
                $v_stmt = $pdo->prepare("SELECT poll_id, option_id FROM poll_votes WHERE poll_id IN ($oph) AND user_id = ?");
                $v_stmt->execute(array_merge($poll_ids, [$user_id]));
                foreach ($v_stmt->fetchAll(PDO::FETCH_ASSOC) as $v) { $votes_by_poll[$v['poll_id']] = (int)$v['option_id']; }
            }
            // Assemble results keyed by post_id
            foreach ($polls_by_post as $post_id => $poll) {
                $poll['options'] = $opts_by_poll[$poll['id']] ?? [];
                $poll['user_vote'] = $votes_by_poll[$poll['id']] ?? null;
                $result[$post_id] = $poll;
            }
        } catch (PDOException $e) {
            // Tables may not exist; fail gracefully
            error_log('batch_get_polls_for_posts error: ' . $e->getMessage());
        }
        return $result;
    }
}

// Batch-load tests for multiple posts at once (avoids N+1 queries in feed)
if (!function_exists('batch_get_tests_for_posts')) {
    function batch_get_tests_for_posts(array $post_ids) {
        if (empty($post_ids)) return [];
        $pdo = db_connect();
        $result = [];
        try {
            $ids = array_map('intval', $post_ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT pt.post_id, t.id as test_id FROM post_tests pt JOIN tests t ON pt.test_id = t.id WHERE pt.post_id IN ($ph)");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (function_exists('get_test_by_id')) {
                    $result[$row['post_id']] = get_test_by_id((int)$row['test_id']);
                }
            }
        } catch (PDOException $e) {
            // Tables may not exist; fail gracefully
            error_log('batch_get_tests_for_posts error: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('get_post_by_id')) {
    function get_post_by_id($post_id) {
        return query("SELECT * FROM posts WHERE id = ? LIMIT 1", [$post_id])->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_user_group_posts')) {
    function get_user_group_posts($user_id, $limit = 50, $viewer_id = null) {
        $stmt = query("
            SELECT gp.id, gp.group_id, gp.user_id, gp.content, gp.created_at,
                   u.username, u.is_premium, gt.name as group_name, gt.slug,
                   ui.id as image_id,
                   ui.filename as image_filename,
                   ui.publish_date as image_publish_date,
                   ui.tags as image_tags,
                   ui.user_id as image_user_id,
                   (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) as like_count,
                   (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) as user_has_liked,
                   (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) as comment_count
            FROM group_posts gp
            JOIN users u ON gp.user_id = u.id
            JOIN groups_table gt ON gp.group_id = gt.id
            LEFT JOIN user_images ui ON gp.image_id = ui.id
            WHERE gp.user_id = ?
            ORDER BY gp.created_at DESC
            LIMIT ?
        ", [$viewer_id ?? 0, $user_id, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            if (function_exists('get_poll_for_group_post')) {
                $r['poll'] = get_poll_for_group_post($r['id']);
            }
            if (!empty($r['image_id'])) {
                $r['image'] = [
                    'id' => $r['image_id'],
                    'filename' => $r['image_filename'],
                    'publish_date' => $r['image_publish_date'],
                    'tags' => $r['image_tags'],
                    'user_id' => $r['image_user_id']
                ];
            }
        }
        unset($r);
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
                    . " AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW() OR p.user_id = ?)"
                    . " AND (u.is_approved = 1 OR u.id = ?"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
                $params = $cursor_value ? [$viewer_id, $viewer_id, $viewer_id, $cursor_value, $fetch_limit] : [$viewer_id, $viewer_id, $viewer_id, $fetch_limit];
            }

            $query_str = "
            SELECT p.*, u.username,
                EXISTS(SELECT 1 FROM post_edits WHERE post_id = p.id) AS has_edits,
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
                    . " AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW())"
                    . " AND (u.is_approved = 1"
                    . " OR (u.role = 'rookie' AND ("
                    . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                    . ") <= 10 ))"
                    . " $cursor_condition";
            $params = $cursor_value ? [$cursor_value, $fetch_limit] : [$fetch_limit];

            $stmt = query("\n            SELECT p.*, u.username,\n                EXISTS(SELECT 1 FROM post_edits WHERE post_id = p.id) AS has_edits,\n                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,\n                0 as user_has_liked,\n                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count\n            FROM posts p\n            JOIN users u ON p.user_id = u.id\n            $where\n            ORDER BY p.id $sort\n            LIMIT ?\n        ", $params);
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

        // Batch-load polls and tests (avoids N+1 per-post queries)
        $post_ids = array_column($results, 'id');
        $polls_map = function_exists('batch_get_polls_for_posts') ? batch_get_polls_for_posts($post_ids) : [];
        $tests_map = function_exists('batch_get_tests_for_posts') ? batch_get_tests_for_posts($post_ids) : [];
        $cc_map = function_exists('batch_get_recursive_comment_counts') ? batch_get_recursive_comment_counts($post_ids) : [];
        foreach ($results as &$r) {
            $r['poll'] = $polls_map[$r['id']] ?? null;
            $r['test'] = $tests_map[$r['id']] ?? null;
            if (isset($cc_map[$r['id']])) { $r['comment_count'] = $cc_map[$r['id']]; }
        }
        unset($r);

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
        // Parameterized IN clause (avoid raw interpolation — SQLi fix)
        if (!empty($followed_ids)) {
            $followed_ids = array_map('intval', $followed_ids);
            $followed_ph = implode(',', array_fill(0, count($followed_ids), '?'));
            $followed_params = array_values($followed_ids);
        } else {
            $followed_ph = '0';
            $followed_params = [];
        }
        $favorite_tags = function_exists('get_user_favorite_tags') ? get_user_favorite_tags($user_id, 5) : [];
        $cursor_condition = $after ? "AND p.id < ?" : "";
        $cursor_param = $after ? [$after] : [];

        // Parameterized LIKE conditions for tags (avoid addslashes — SQLi fix)
        $tag_conditions = "";
        $tag_params = [];
        if (count($favorite_tags) > 0) {
            $tag_likes = array_map(function($t) { return "p.content LIKE ?"; }, $favorite_tags);
            $tag_params = array_map(function($t) { return '%#' . $t . '%'; }, $favorite_tags);
            $tag_conditions = " OR (" . implode(" OR ", $tag_likes) . ")";
        }

        $stmt = query("\n        SELECT \n            p.*, \n            u.username, \n            u.is_premium,\n            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,\n            (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,\n            (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count,\n            (\n                CASE WHEN p.user_id = ? THEN 50 ELSE 0 END\n                + CASE WHEN p.user_id IN ($followed_ph) THEN 30 ELSE 0 END\n                + (TIMESTAMPDIFF(SECOND, p.created_at, NOW()) * -1)\n            ) as relevance_score\n        FROM posts p\n        JOIN users u ON p.user_id = u.id\n        WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL\n            AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW() OR p.user_id = ?)\n            AND (u.is_approved = 1 OR u.id = ?\n                 OR (u.role = 'rookie' AND (\n                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id\n                    ) <= 10))\n        $cursor_condition\n        ORDER BY relevance_score DESC, p.created_at DESC\n        LIMIT ?\n    ", array_merge([$user_id, $user_id], $followed_params, [$user_id, $user_id], $cursor_param, [$limit + 1]));

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
        // Batch-load polls, tests, images (avoids N+1 per-post queries)
        $post_ids = array_column($posts, 'id');
        $polls_map  = function_exists('batch_get_polls_for_posts')  ? batch_get_polls_for_posts($post_ids)  : [];
        $tests_map  = function_exists('batch_get_tests_for_posts')  ? batch_get_tests_for_posts($post_ids)  : [];
        $cc_map     = function_exists('batch_get_recursive_comment_counts') ? batch_get_recursive_comment_counts($post_ids) : [];
        $images_map = function_exists('batch_get_images_for_posts') ? batch_get_images_for_posts($post_ids) : [];
        foreach ($posts as &$ppp) {
            $ppp['poll']  = $polls_map[$ppp['id']]  ?? null;
            $ppp['test']  = $tests_map[$ppp['id']]  ?? null;
            $ppp['image'] = $images_map[$ppp['id']] ?? null;
            if (isset($cc_map[$ppp['id']])) { $ppp['comment_count'] = $cc_map[$ppp['id']]; }
        }
        unset($ppp);
        return [
            'posts' => $posts,
            'has_next' => $has_next,
            'first_id' => count($posts) > 0 ? $posts[0]['id'] : null,
            'last_id' => count($posts) > 0 ? $posts[count($posts) - 1]['id'] : null,
        ];
    }
}

if (!function_exists('get_followed_posts_paginated')) {
    function get_followed_posts_paginated($viewer_id, $limit = 40, $after = null, $before = null) {
        $has_next = false;
        $has_prev = false;

        if (!$viewer_id) {
            return ['posts' => [], 'has_next' => false, 'has_prev' => false, 'first_id' => null, 'last_id' => null];
        }

        $followed_ids = function_exists('get_followed_user_ids') ? get_followed_user_ids($viewer_id) : [];
        if (empty($followed_ids)) {
            return ['posts' => [], 'has_next' => false, 'has_prev' => false, 'first_id' => null, 'last_id' => null];
        }

        $followed_ids = array_map('intval', $followed_ids);
        $followed_ph = implode(',', array_fill(0, count($followed_ids), '?'));
        $cursor_condition = '';
        $cursor_value = null;
        $sort = 'DESC';

        if ($after) {
            $cursor_condition = 'AND p.id < ?';
            $cursor_value = $after;
            $sort = 'DESC';
        } elseif ($before) {
            $cursor_condition = 'AND p.id > ?';
            $cursor_value = $before;
            $sort = 'ASC';
        }

        $fetch_limit = $limit + 1;
        $viewer = function_exists('get_user') ? get_user($viewer_id) : null;
        $is_admin = $viewer && !empty($viewer['role']) && $viewer['role'] === 'admin';

        if ($is_admin) {
            $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL AND p.user_id IN ($followed_ph) $cursor_condition";
            if ($cursor_value) {
                $params = array_merge([$viewer_id], $followed_ids, [$cursor_value, $fetch_limit]);
            } else {
                $params = array_merge([$viewer_id], $followed_ids, [$fetch_limit]);
            }

            $stmt = query(
                "SELECT p.*, u.username, u.is_premium, EXISTS(SELECT 1 FROM post_edits WHERE post_id = p.id) AS has_edits, (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count, (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked, (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count FROM posts p JOIN users u ON p.user_id = u.id $where ORDER BY p.id $sort LIMIT ?",
                $params
            );
        } else {
            $where = "WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL"
                . " AND p.user_id IN ($followed_ph)"
                . " AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW() OR p.user_id = ? )"
                . " AND (u.is_approved = 1 OR u.id = ?"
                . " OR (u.role = 'rookie' AND ("
                . "SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id"
                . ") <= 10 ) ) $cursor_condition";

            if ($cursor_value) {
                $params = array_merge([$viewer_id], $followed_ids, [$viewer_id, $cursor_value, $fetch_limit]);
            } else {
                $params = array_merge([$viewer_id], $followed_ids, [$viewer_id, $viewer_id, $fetch_limit]);
            }

            $stmt = query(
                "SELECT p.*, u.username, u.is_premium, EXISTS(SELECT 1 FROM post_edits WHERE post_id = p.id) AS has_edits, (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count, (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked, (SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) as comment_count FROM posts p JOIN users u ON p.user_id = u.id $where ORDER BY p.id $sort LIMIT ?",
                $params
            );
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

        $post_ids = array_column($results, 'id');
        $polls_map  = function_exists('batch_get_polls_for_posts')  ? batch_get_polls_for_posts($post_ids)  : [];
        $tests_map  = function_exists('batch_get_tests_for_posts')  ? batch_get_tests_for_posts($post_ids)  : [];
        $cc_map     = function_exists('batch_get_recursive_comment_counts') ? batch_get_recursive_comment_counts($post_ids) : [];
        $images_map = function_exists('batch_get_images_for_posts') ? batch_get_images_for_posts($post_ids) : [];
        foreach ($results as &$ppp) {
            $ppp['poll']  = $polls_map[$ppp['id']]  ?? null;
            $ppp['test']  = $tests_map[$ppp['id']]  ?? null;
            $ppp['image'] = $images_map[$ppp['id']] ?? null;
            if (isset($cc_map[$ppp['id']])) { $ppp['comment_count'] = $cc_map[$ppp['id']]; }
        }
        unset($ppp);

        return [
            'posts' => $results,
            'has_next' => $has_next,
            'has_prev' => $has_prev,
            'first_id' => count($results) > 0 ? $results[0]['id'] : null,
            'last_id' => count($results) > 0 ? $results[count($results) - 1]['id'] : null,
        ];
    }
}

if (!function_exists('get_public_group_posts_for_landing')) {
    function get_public_group_posts_for_landing($limit = 15, $viewer_id = null) {
        $user_id = $viewer_id ?: 0;
        $stmt = query(
            "SELECT gp.id, gp.group_id, gp.user_id, gp.content, gp.created_at, gp.updated_at, gp.scheduled_at, 
                    u.username, u.is_premium, gt.name AS group_name, gt.slug,
                    ui.id AS image_id, ui.filename AS image_filename, ui.publish_date AS image_publish_date, ui.tags AS image_tags, ui.user_id AS image_user_id,
                    (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) AS like_count,
                    (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) AS user_has_liked,
                    (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) AS comment_count
             FROM group_posts gp
             JOIN users u ON gp.user_id = u.id
             JOIN groups_table gt ON gp.group_id = gt.id
             LEFT JOIN user_images ui ON gp.image_id = ui.id AND ui.deleted_at IS NULL
             WHERE gp.deleted_at IS NULL AND gt.is_private = 0 AND u.deleted_at IS NULL
               AND (gp.scheduled_at IS NULL OR gp.scheduled_at <= NOW())
               AND (u.is_approved = 1 OR (u.role = 'rookie' AND (
                    SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL
               ) <= 10))
             ORDER BY gp.created_at DESC
             LIMIT ?",
            [$user_id, $limit]
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['type'] = 'group_post';
            if (!empty($row['image_id'])) {
                $row['image'] = [
                    'id' => $row['image_id'],
                    'filename' => $row['image_filename'],
                    'publish_date' => $row['image_publish_date'],
                    'tags' => $row['image_tags'],
                    'user_id' => $row['image_user_id'],
                ];
            } else {
                $row['image'] = null;
            }
            if (function_exists('get_poll_for_group_post')) {
                $row['poll'] = get_poll_for_group_post($row['id']);
            }
            if (function_exists('get_test_for_group_post')) {
                $row['test'] = get_test_for_group_post($row['id']);
            }
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('get_landing_feed_paginated')) {
    function get_landing_feed_paginated($limit = 15, $after = null, $before = null) {
        $posts_pagination = get_posts_paginated($limit, null, $after, $before);

        if ($after || $before) {
            // For paginated landing page navigation, use standard posts only.
            foreach ($posts_pagination['posts'] as &$post) {
                $post['type'] = 'post';
            }
            unset($post);
            return $posts_pagination;
        }

        $group_posts = get_public_group_posts_for_landing($limit, null);
        $combined = array_merge(
            array_map(function($post) { $post['type'] = 'post'; return $post; }, $posts_pagination['posts']),
            $group_posts
        );
        usort($combined, function($a, $b) {
            $timeA = strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
            $timeB = strtotime($b['created_at'] ?? '1970-01-01 00:00:00');
            if ($timeA === $timeB) {
                if ($a['type'] === $b['type']) {
                    return ($a['id'] <=> $b['id']) * -1;
                }
                return $a['type'] === 'post' ? -1 : 1;
            }
            return $timeB <=> $timeA;
        });

        $limited = array_slice($combined, 0, $limit);
        foreach ($limited as &$item) {
            if (!isset($item['type'])) {
                $item['type'] = 'post';
            }
        }
        unset($item);

        return [
            'posts' => $limited,
            'has_next' => $posts_pagination['has_next'],
            'has_prev' => false,
            'first_id' => $posts_pagination['first_id'],
            'last_id' => $posts_pagination['last_id'],
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

if (!function_exists('get_new_posts_count_for_feed')) {
    function get_new_posts_count_for_feed($viewer_id, $feed, $since) {
        if (!$viewer_id || !$since) {
            return 0;
        }

        if ($feed === 'followed') {
            $followed_ids = function_exists('get_followed_user_ids') ? get_followed_user_ids($viewer_id) : [];
            if (empty($followed_ids)) {
                return 0;
            }

            $followed_ids = array_map('intval', $followed_ids);
            $followed_ph = implode(',', array_fill(0, count($followed_ids), '?'));
            $pdo = db_connect();
            $viewer = function_exists('get_user') ? get_user($viewer_id) : null;
            $params = [];

            if ($viewer && !empty($viewer['role']) && $viewer['role'] === 'admin') {
                $query = "SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL AND p.user_id IN ($followed_ph) AND p.created_at > ?";
                $params = array_merge($followed_ids, [$since]);
            } else {
                $query = "SELECT COUNT(*) AS c FROM posts p JOIN users u ON p.user_id = u.id WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL AND p.user_id IN ($followed_ph) AND (p.scheduled_at IS NULL OR p.scheduled_at <= NOW()) AND (u.is_approved = 1 OR u.id = ? OR (u.role = 'rookie' AND (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id) <= 10)) AND p.created_at > ?";
                $params = array_merge($followed_ids, [$viewer_id, $since]);
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return (int)($stmt->fetch()['c'] ?? 0);
        }

        return get_new_feed_count($viewer_id, $since);
    }
}

if (!function_exists('get_trending_tags')) {
    function get_trending_tags($limit = 10, $user_id = null) {
        try {
            $pdo = db_connect();
            $posts = [];

            $stmt = $pdo->prepare("SELECT p.id, p.content, p.created_at,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
            (SELECT COUNT(*) FROM posts c WHERE c.parent_id = p.id AND c.deleted_at IS NULL) as comments_count
            FROM posts p
            WHERE p.deleted_at IS NULL AND p.parent_id IS NULL
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY p.created_at DESC
            LIMIT 1000");
            $stmt->execute();
            $posts = array_merge($posts, $stmt->fetchAll(PDO::FETCH_ASSOC));

            try {
                $stmt = $pdo->prepare("SELECT gp.id, gp.content, gp.created_at,
                (SELECT COUNT(*) FROM group_post_likes l WHERE l.post_id = gp.id) as likes_count,
                (SELECT COUNT(*) FROM group_post_comments c WHERE c.post_id = gp.id) as comments_count
                FROM group_posts gp
                JOIN groups_table g ON gp.group_id = g.id
                WHERE gp.deleted_at IS NULL AND g.is_private = 0
                  AND gp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY gp.created_at DESC
                LIMIT 1000");
                $stmt->execute();
                $posts = array_merge($posts, $stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                error_log("get_trending_tags group posts query failed: " . $e->getMessage());
            }

            $acc = [];
            foreach ($posts as $p) {
                $content = $p['content'] ?? '';
                $likes = (int)($p['likes_count'] ?? 0);
                $comments = (int)($p['comments_count'] ?? 0);
                $created_at = $p['created_at'] ?? null;

                $tags = extract_hashtags_from_text($content);
                if (empty($tags)) continue;
                foreach (array_unique($tags) as $t) {
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
                $freshness_bonus = max(0, 8 - $days);
                $decay = exp(-0.10 * $days);
                $relevance = (
                    ($meta['total_likes'] * 0.6)
                    + ($meta['total_comments'] * 1.0)
                    + ($meta['post_count'] * 0.8)
                    + $freshness_bonus
                ) * $decay;
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

            if (empty($rows) && function_exists('get_top_tags')) {
                try {
                    return get_top_tags($limit);
                } catch (Exception $e) {
                    error_log("get_trending_tags fallback to get_top_tags failed: " . $e->getMessage());
                }
            }

            return array_slice($rows, 0, $limit);
        } catch (Exception $e) {
            error_log("Error getting trending tags: " . $e->getMessage());
            if (function_exists('get_top_tags')) {
                try {
                    return get_top_tags($limit);
                } catch (Exception $e2) {
                    error_log("get_trending_tags fallback to get_top_tags failed: " . $e2->getMessage());
                }
            }
            return [];
        }
    }
}
