<?php
/**
 * Test script for timeline tabs functionality
 */

require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();

if (!$user_id) {
    die("Please log in to test the timeline tabs functionality.");
}

echo "<div style='max-width: 800px; margin: 0 auto; padding: 20px;'>";
echo "<h1>Timeline Tabs Test</h1>";

// Test get_followed_user_ids
if (function_exists('get_followed_user_ids')) {
    $followed_ids = get_followed_user_ids($user_id);
    echo "<h2>Followed Users</h2>";
    echo "<p>You are following " . count($followed_ids) . " users.</p>";
    echo "<p>Followed IDs: " . implode(', ', array_slice($followed_ids, 0, 10)) . (count($followed_ids) > 10 ? '...' : '') . "</p>";
} else {
    echo "<p>Error: get_followed_user_ids function not found</p>";
}

// Test get_followed_posts
echo "<h2>Followed Posts</h2>";
if (function_exists('get_followed_posts')) {
    $followed_posts = get_followed_posts($user_id, 5);
    echo "<p>Found " . count($followed_posts) . " posts from followed users.</p>";
    
    if (!empty($followed_posts)) {
        echo "<h3>Sample Posts:</h3>";
        echo "<ul>";
        foreach (array_slice($followed_posts, 0, 3) as $post) {
            echo "<li>";
            echo "Post ID: " . htmlspecialchars($post['id']) . " by ";
            echo "<strong>" . htmlspecialchars($post['username']) . "</strong> - ";
            echo htmlspecialchars(mb_substr($post['content'], 0, 50)) . "...";
            echo "</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p>Error: get_followed_posts function not found</p>";
}

// Test get_new_posts_count_for_feed
echo "<h2>New Posts Count</h2>";
if (function_exists('get_new_posts_count_for_feed')) {
    // Test with a time 1 hour ago
    $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    $general_new = get_new_posts_count_for_feed($user_id, 'general', $one_hour_ago);
    $followed_new = get_new_posts_count_for_feed($user_id, 'followed', $one_hour_ago);
    
    echo "<p>New general posts in last hour: " . $general_new . "</p>";
    echo "<p>New followed posts in last hour: " . $followed_new . "</p>";
} else {
    echo "<p>Error: get_new_posts_count_for_feed function not found</p>";
}

// Test session tracking
echo "<h2>Session Tracking</h2>";
$general_last_seen = $_SESSION['last_feed_seen_at'] ?? 'Not set';
$followed_last_seen = $_SESSION['last_followed_feed_seen_at'] ?? 'Not set';

echo "<p>Last general feed seen: " . htmlspecialchars($general_last_seen) . "</p>";
echo "<p>Last followed feed seen: " . htmlspecialchars($followed_last_seen) . "</p>";

echo "<h2>Test Complete</h2>";
echo "<p><a href='index.php'>Back to homepage</a></p>";
echo "<p><a href='index.php?feed=general'>View General Feed</a></p>";
echo "<p><a href='index.php?feed=followed'>View Followed Feed</a></p>";

echo "</div>";

require_once __DIR__ . '/includes/footer.php';