<?php
/**
 * Mevzuat-specific lightweight trigger module.
 * Put site-specific automated behaviors here so they can be removed/disabled easily.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function mevzuat_ensure_system_user() {
    try {
        $stmt = query("SELECT id FROM users WHERE username = ? LIMIT 1", ['Sistem']);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['id'])) return (int)$r['id'];

        $pw_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        query("INSERT INTO users (username, email, password_hash, role, is_approved, notify_by_email, bio, created_at) VALUES (?, ?, ?, 'member', 1, 0, ?, NOW())",
              ['Sistem', 'no-reply@mevzuatraporu.com', $pw_hash, '@Mevzuat']);
        return insert_id();
    } catch (Throwable $e) {
        error_log('mevzuat_ensure_system_user error: ' . $e->getMessage());
        return null;
    }
}

function mevzuat_auto_follow_on_approve($system_id, $user_id) {
    if (empty($system_id) || empty($user_id)) return;
    if ($system_id == $user_id) return;
    try {
        // Make system follow the new user and optionally make the new user follow system
        query("INSERT IGNORE INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())", [$system_id, $user_id]);
        query("INSERT IGNORE INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())", [$user_id, $system_id]);
        // Notify both parties about the follow via existing notification helpers
        create_notification($user_id, 'follow', $system_id, null);
    } catch (Throwable $e) {
        error_log('mevzuat_auto_follow_on_approve error: ' . $e->getMessage());
    }
}

function mevzuat_auto_like_first_post($post_id, $author_id) {
    if (empty($post_id) || empty($author_id)) return;
    try {
        $sys = mevzuat_ensure_system_user();
        if (!$sys) return;
        if ($sys == $author_id) return;
        // Insert like if not already present
        query("INSERT IGNORE INTO likes (user_id, post_id, reacSitemizin Deneysel Htion, created_at) VALUES (?, ?, ?, NOW())", [$sys, $post_id, '🔥']);
        // Update post's likes_count safely
        query("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?", [$post_id]);
        // Notify post author
        $post = get_post($post_id);
        if ($post && $post['user_id'] != $sys) {
            create_notification($post['user_id'], 'like', $sys, $post_id);
        }
    } catch (Throwable $e) {
        error_log('mevzuat_auto_like_first_post error: ' . $e->getMessage());
    }
}
