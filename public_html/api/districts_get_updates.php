<?php
/**
 * API: Get District Updates
 * Returns new content from server for offline sync
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/plain');

if (!is_logged_in()) {
    http_response_code(401);
    echo http_build_query(['success' => '0', 'error' => 'Authentication required']);
    exit;
}

$user_id = $_SESSION['user_id'];
$district_id = isset($_GET['district_id']) ? intval($_GET['district_id']) : 0;
$last_sync = isset($_GET['last_sync']) ? intval($_GET['last_sync']) : 0;

if (!$district_id) {
    http_response_code(400);
    echo http_build_query(['success' => '0', 'error' => 'District ID required']);
    exit;
}

// Verify membership
$stmt = $pdo->prepare("
    SELECT 1 FROM user_districts 
    WHERE user_id = ? AND district_id = ? AND is_active = 1
");
$stmt->execute([$user_id, $district_id]);

if (!$stmt->fetch()) {
    http_response_code(403);
    echo http_build_query(['success' => '0', 'error' => 'Not a member of this district']);
    exit;
}

try {
    // Get new posts since last sync
    $posts_stmt = $pdo->prepare("
        SELECT 
            dp.*,
            u.username,
            u.profile_image,
            COUNT(DISTINCT dl.id) as like_count,
            COUNT(DISTINCT dc.id) as comment_count
        FROM district_posts dp
        JOIN users u ON dp.user_id = u.id
        LEFT JOIN district_likes dl ON dp.id = dl.district_post_id
        LEFT JOIN district_comments dc ON dp.id = dc.district_post_id
        WHERE dp.district_id = ? 
        AND dp.is_deleted = 0
        AND UNIX_TIMESTAMP(dp.synced_at) > ?
        GROUP BY dp.id
        ORDER BY dp.created_at_device DESC
        LIMIT 100
    ");
    $posts_stmt->execute([$district_id, $last_sync]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get new likes
    $likes_stmt = $pdo->prepare("
        SELECT dl.*, dp.post_uuid, u.username
        FROM district_likes dl
        JOIN district_posts dp ON dl.district_post_id = dp.id
        JOIN users u ON dl.user_id = u.id
        WHERE dp.district_id = ?
        AND UNIX_TIMESTAMP(dl.synced_at) > ?
        LIMIT 500
    ");
    $likes_stmt->execute([$district_id, $last_sync]);
    $likes = $likes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get new comments
    $comments_stmt = $pdo->prepare("
        SELECT dc.*, dp.post_uuid, u.username, u.profile_image
        FROM district_comments dc
        JOIN district_posts dp ON dc.district_post_id = dp.id
        JOIN users u ON dc.user_id = u.id
        WHERE dp.district_id = ?
        AND UNIX_TIMESTAMP(dc.synced_at) > ?
        ORDER BY dc.created_at_device DESC
        LIMIT 200
    ");
    $comments_stmt->execute([$district_id, $last_sync]);
    $comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo http_build_query([
        'success' => '1',
        'posts_count' => count($posts),
        'likes_count' => count($likes),
        'comments_count' => count($comments),
        'server_timestamp' => time(),
        'posts' => $posts,
        'likes' => $likes,
        'comments' => $comments,
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo http_build_query(['success' => '0', 'error' => 'Database error']);
}
