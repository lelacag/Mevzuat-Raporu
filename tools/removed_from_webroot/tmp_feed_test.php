<?php
require __DIR__ . '/includes/db.php';
$pdo = db_connect();
$pdo->beginTransaction();
$pdo->exec("INSERT INTO users (username,email,password_hash,role,accepted_terms,accepted_privacy,created_at) VALUES ('rook1','r1@example.test','x','rookie',1,1,NOW())");
$rookieId = $pdo->lastInsertId();
for ($i = 1; $i <= 12; $i++) {
    $pdo->exec("INSERT INTO posts (user_id,content,created_at) VALUES ($rookieId,'rpost$i',NOW())");
}
$pdo->exec("INSERT INTO users (username,email,password_hash,role,accepted_terms,accepted_privacy,created_at) VALUES ('mem1','m1@example.test','x','member',1,1,NOW())");
$memberId = $pdo->lastInsertId();

// run the feed SQL as constructed in get_posts (non-admin viewer)
$sql = "SELECT p.*, u.username,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as like_count,
                (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as user_has_liked,
                (SELECT COUNT(*) FROM posts WHERE parent_id = p.id) as comment_count
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.parent_id IS NULL AND p.deleted_at IS NULL AND u.deleted_at IS NULL
                AND (u.is_approved = 1 OR u.id = ?
                     OR (u.role = 'rookie' AND (
                        SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = u.id AND p2.parent_id IS NULL AND p2.deleted_at IS NULL AND p2.id <= p.id
                     ) <= 10 ) )
            ORDER BY p.created_at DESC LIMIT ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$memberId, $memberId, 50]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
