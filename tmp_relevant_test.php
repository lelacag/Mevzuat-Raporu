<?php
// stub helpers that rely on web context
if (!function_exists('get_poll_for_post')) { function get_poll_for_post($id){ return null; } }
if (!function_exists('get_test_for_post')) { function get_test_for_post($id){ return null; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(){ return null; }}
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
$pdo = db_connect();
$pdo->beginTransaction();
$pdo->exec("INSERT INTO users (username,email,password_hash,role,accepted_terms,accepted_privacy,created_at) VALUES ('rook2','r2@example.test','x','rookie',1,1,NOW())");
$rookieId = $pdo->lastInsertId();
for ($i = 1; $i <= 12; $i++) {
    $pdo->exec("INSERT INTO posts (user_id,content,created_at) VALUES ($rookieId,'rp$i',NOW())");
}
$pdo->exec("INSERT INTO users (username,email,password_hash,role,accepted_terms,accepted_privacy,created_at) VALUES ('mem2','m2@example.test','x','member',1,1,NOW())");
$memberId = $pdo->lastInsertId();
// run relevant feed for member
$pagination = get_relevant_posts_paginated($memberId, 50, null);
$rows = $pagination['posts'];
echo "total rows: " . count($rows) . "\n";
$count = 0;
foreach ($rows as $r) {
    if ($r['user_id'] == $rookieId) {
        $count++;
        echo $r['content'] . "\n";
    }
}
echo "rookie count: " . $count . "\n";
$pdo->rollBack();
