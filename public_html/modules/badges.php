<?php
/**
 * Module: badges.php — Badge CRUD, user badges, likes-based sync
 */

if (!function_exists('create_badge')) {
function create_badge($name, $slug, $description = null, $min_likes = 0) {
    query("INSERT INTO badges (name, slug, description, min_likes) VALUES (?, ?, ?, ?)", [$name, $slug, $description, $min_likes]);
    return insert_id();
}
}

if (!function_exists('update_badge')) {
function update_badge($id, $name, $slug, $description, $min_likes) {
    query("UPDATE badges SET name = ?, slug = ?, description = ?, min_likes = ? WHERE id = ?", [$name, $slug, $description, $min_likes, $id]);
}
}

if (!function_exists('delete_badge')) {
function delete_badge($id) { query("DELETE FROM badges WHERE id = ?", [$id]); }
}

if (!function_exists('get_badges')) {
function get_badges($limit = 100) {
    $stmt = query("SELECT * FROM badges ORDER BY min_likes ASC LIMIT ?", [$limit]);
    return $stmt->fetchAll();
}
}

if (!function_exists('get_badge')) {
function get_badge($id) { return query("SELECT * FROM badges WHERE id = ?", [$id])->fetch(); }
}

if (!function_exists('get_user_badges')) {
function get_user_badges($user_id) {
    $stmt = query("SELECT b.* FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ? ORDER BY b.min_likes ASC", [$user_id]);
    return $stmt->fetchAll();
}
}

if (!function_exists('assign_badge_to_user')) {
function assign_badge_to_user($user_id, $badge_id, $assigned_by = null) {
    try { query("INSERT INTO user_badges (user_id, badge_id, assigned_by) VALUES (?, ?, ?)", [$user_id, $badge_id, $assigned_by]); }
    catch (PDOException $e) { /* ignore duplicates */ }
}
}

if (!function_exists('remove_badge_from_user')) {
function remove_badge_from_user($user_id, $badge_id) {
    query("DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?", [$user_id, $badge_id]);
}
}

if (!function_exists('get_likes_received')) {
function get_likes_received($user_id) {
    $stmt = query("SELECT COALESCE(SUM(likes_count), 0) as c FROM posts WHERE user_id = ? AND deleted_at IS NULL", [$user_id]);
    return (int)($stmt->fetch()['c'] ?? 0);
}
}

if (!function_exists('sync_user_badges_by_likes')) {
function sync_user_badges_by_likes($user_id) {
    // Unapproved (rookie) users must not receive tier badges — only 'yeni-gelen' applies until admin approval
    $user_row = query("SELECT is_approved FROM users WHERE id = ? LIMIT 1", [$user_id])->fetch();
    if (!$user_row || (int)$user_row['is_approved'] === 0) {
        return;
    }

    $likes = get_likes_received($user_id);
    // Only sync tier badges — never touch the lifecycle 'yeni-gelen' badge
    $stmt = query("SELECT * FROM badges WHERE slug != 'yeni-gelen' ORDER BY min_likes ASC LIMIT 1000");
    $badges = $stmt->fetchAll();
    foreach ($badges as $b) {
        if ($likes >= $b['min_likes']) {
            assign_badge_to_user($user_id, $b['id']);
        } else {
            remove_badge_from_user($user_id, $b['id']);
        }
    }
}
}

if (!function_exists('maybe_sync_badges_after_like')) {
function maybe_sync_badges_after_like($post_id) {
    $post = get_post($post_id);
    if ($post) { sync_user_badges_by_likes($post['user_id']); }
}
}

/**
 * Returns the single highest-tier earned badge for a user (excludes yeni-gelen).
 * Returns null if the user has no tier badges.
 */
if (!function_exists('get_user_best_badge')) {
function get_user_best_badge($user_id) {
    $stmt = query(
        "SELECT b.* FROM user_badges ub
         JOIN badges b ON ub.badge_id = b.id
         WHERE ub.user_id = ? AND b.slug != 'yeni-gelen'
         ORDER BY b.min_likes DESC LIMIT 1",
        [$user_id]
    );
    return $stmt->fetch() ?: null;
}
}

/**
 * Seeds the four default tier badges using INSERT IGNORE (safe to call repeatedly).
 */
if (!function_exists('seed_default_badges')) {
function seed_default_badges() {
    $defaults = [
        ['name' => 'Çiçeği Burnunda', 'slug' => 'cicegi-burnunda', 'description' => '1 veya daha fazla beğeni aldı.',  'min_likes' => 1],
        ['name' => 'Kalem Ustası',    'slug' => 'kalem-ustasi',    'description' => '50 veya daha fazla beğeni aldı.', 'min_likes' => 50],
        ['name' => 'Söz Ustası',      'slug' => 'soz-ustasi',      'description' => '200 veya daha fazla beğeni aldı.','min_likes' => 200],
        ['name' => 'Parmak İzi',      'slug' => 'parmak-izi',      'description' => '1000 veya daha fazla beğeni aldı.','min_likes' => 1000],
    ];
    foreach ($defaults as $d) {
        query(
            "INSERT IGNORE INTO badges (name, slug, description, min_likes) VALUES (?, ?, ?, ?)",
            [$d['name'], $d['slug'], $d['description'], $d['min_likes']]
        );
    }
}
}
