<?php
/**
 * Module: groups.php — Group comments, group posts, group URL helpers, activity tracking
 */

if (!function_exists('get_group_comments')) {
function get_group_comments($post_id, $viewer_id = null, $parent_id = null, $depth = 0, $max_depth = 5) {
    if ($depth >= $max_depth) return [];
    try {
        // Include soft-deleted comments so nested threads stay visible with a deleted indicator.
        if ($parent_id === null) {
            $stmt = query("SELECT c.*, u.username,
                (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id) as likes_count,
                (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id AND user_id = ?) as user_liked
                FROM group_post_comments c JOIN users u ON c.user_id = u.id
                WHERE c.post_id = ? AND c.parent_id IS NULL
                  AND (u.deleted_at IS NULL OR c.deleted_at IS NOT NULL)
                ORDER BY c.created_at ASC",
                [$viewer_id ?: 0, $post_id]);
        } else {
            $stmt = query("SELECT c.*, u.username,
                (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id) as likes_count,
                (SELECT COUNT(*) FROM group_comment_likes WHERE comment_id = c.id AND user_id = ?) as user_liked
                FROM group_post_comments c JOIN users u ON c.user_id = u.id
                WHERE c.post_id = ? AND c.parent_id = ?
                  AND (u.deleted_at IS NULL OR c.deleted_at IS NOT NULL)
                ORDER BY c.created_at ASC",
                [$viewer_id ?: 0, $post_id, $parent_id]);
        }
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($comments as &$comment) {
            $comment['depth'] = $depth;
            $comment['post_id'] = $post_id;
            $comment['replies'] = get_group_comments($post_id, $viewer_id, $comment['id'], $depth + 1, $max_depth);
        }
        unset($comment);
        return $comments;
    } catch (Exception $e) { return []; }
}
}

if (!function_exists('group_comment_has_active_replies')) {
function group_comment_has_active_replies($comment_id) {
    try {
        $stmt = query(
            "SELECT COUNT(*) FROM group_post_comments WHERE parent_id = ? AND deleted_at IS NULL",
            [(int)$comment_id]
        );
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('count_group_comments')) {
function count_group_comments($post_id) {
    try {
        // Count every comment node including soft-deleted placeholders so nested threads stay consistent.
        $stmt = query("SELECT COUNT(*) as cnt FROM group_post_comments WHERE post_id = ?", [$post_id]);
        return (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) { return 0; }
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
    $rows = $stmt->fetchAll();
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

if (!function_exists('group_url')) {
function group_url($slug) {
    $normalized = preg_replace('/[^a-z0-9_-]+/i', '-', trim(strtolower($slug)));
    $normalized = trim($normalized, '-');
    $slug_for_url = USE_CLEAN_URLS ? urlencode($normalized) : urlencode($slug);
    if (USE_CLEAN_URLS) return BASE_PATH . '/g/' . $slug_for_url;
    return BASE_PATH . '/group.php?slug=' . $slug_for_url;
}
}

if (!function_exists('group_post_url')) {
function group_post_url($slug, $post_id) {
    return BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post_id;
}
}

if (!function_exists('announcement_url')) {
function announcement_url($slug, $id, $created_at = null) {
    if (USE_CLEAN_URLS && !empty($slug)) {
        $date_part = $created_at ? date('Y-m-d', strtotime($created_at)) : '';
        $url_slug = $date_part ? $slug . '-' . $date_part : $slug;
        return BASE_PATH . '/duyuru/' . urlencode($url_slug);
    }
    return BASE_PATH . '/announcement.php?id=' . (int)$id;
}
}

if (!function_exists('user_url')) {
function user_url($username) { return profile_url($username); }
}

if (!function_exists('track_user_activity')) {
function track_user_activity($user_id) {
    if (!$user_id) return;
    try { query("UPDATE users SET is_online = 1, last_activity = NOW() WHERE id = ?", [$user_id]); }
    catch (Exception $e) { /* silently fail */ }
}
}
