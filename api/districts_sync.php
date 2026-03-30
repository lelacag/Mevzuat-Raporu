<?php
/**
 * API: Sync District Data
 * Syncs offline district data to main server
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $_SESSION['user_id'];
$district_id = isset($data['district_id']) ? intval($data['district_id']) : 0;

if (!$district_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'District ID required']);
    exit;
}

// Verify user is member of district
$stmt = $pdo->prepare("
    SELECT role FROM user_districts 
    WHERE user_id = ? AND district_id = ? AND is_active = 1
");
$stmt->execute([$user_id, $district_id]);
$membership = $stmt->fetch();

if (!$membership) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not a member of this district']);
    exit;
}

$pdo->beginTransaction();
try {
    $synced_posts = [];
    $synced_actions = [];
    $errors = [];
    
    // Sync posts
    if (isset($data['posts']) && is_array($data['posts'])) {
        foreach ($data['posts'] as $post) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO district_posts 
                    (post_uuid, district_id, user_id, content, media_url, created_at_device, is_synced, synced_at)
                    VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        content = VALUES(content),
                        media_url = VALUES(media_url),
                        synced_at = NOW(),
                        is_synced = 1
                ");
                
                $stmt->execute([
                    $post['uuid'],
                    $district_id,
                    $user_id,
                    $post['content'],
                    $post['media_url'] ?? null,
                    $post['created_at'] / 1000
                ]);
                
                $post_id = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM district_posts WHERE post_uuid = '{$post['uuid']}'")->fetchColumn();
                
                $synced_posts[] = [
                    'uuid' => $post['uuid'],
                    'server_id' => $post_id,
                    'synced_at' => time()
                ];
            } catch (PDOException $e) {
                $errors[] = "Post {$post['uuid']}: " . $e->getMessage();
            }
        }
    }
    
    // Sync likes
    if (isset($data['likes']) && is_array($data['likes'])) {
        foreach ($data['likes'] as $like) {
            try {
                // Find local post ID by UUID
                $postStmt = $pdo->prepare("SELECT id FROM district_posts WHERE post_uuid = ?");
                $postStmt->execute([$like['post_uuid']]);
                $localPost = $postStmt->fetch();
                
                if ($localPost) {
                    $stmt = $pdo->prepare("
                        INSERT INTO district_likes 
                        (like_uuid, district_post_id, user_id, created_at_device, is_synced, synced_at)
                        VALUES (?, ?, ?, FROM_UNIXTIME(?), 1, NOW())
                        ON DUPLICATE KEY UPDATE synced_at = NOW()
                    ");
                    
                    $stmt->execute([
                        $like['uuid'],
                        $localPost['id'],
                        $user_id,
                        $like['created_at'] / 1000
                    ]);
                }
            } catch (PDOException $e) {
                $errors[] = "Like {$like['uuid']}: " . $e->getMessage();
            }
        }
    }
    
    // Sync comments
    if (isset($data['comments']) && is_array($data['comments'])) {
        foreach ($data['comments'] as $comment) {
            try {
                $postStmt = $pdo->prepare("SELECT id FROM district_posts WHERE post_uuid = ?");
                $postStmt->execute([$comment['post_uuid']]);
                $localPost = $postStmt->fetch();
                
                if ($localPost) {
                    $stmt = $pdo->prepare("
                        INSERT INTO district_comments 
                        (comment_uuid, district_post_id, user_id, content, created_at_device, is_synced, synced_at)
                        VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), 1, NOW())
                        ON DUPLICATE KEY UPDATE 
                            content = VALUES(content),
                            synced_at = NOW()
                    ");
                    
                    $stmt->execute([
                        $comment['uuid'],
                        $localPost['id'],
                        $user_id,
                        $comment['content'],
                        $comment['created_at'] / 1000
                    ]);
                }
            } catch (PDOException $e) {
                $errors[] = "Comment {$comment['uuid']}: " . $e->getMessage();
            }
        }
    }
    
    // Store actions in sync queue for processing
    if (isset($data['actions']) && is_array($data['actions'])) {
        foreach ($data['actions'] as $action) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO district_sync_queue 
                    (district_id, user_id, action_type, data_json, device_timestamp, synced, synced_at)
                    VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), 1, NOW())
                ");
                
                $stmt->execute([
                    $district_id,
                    $user_id,
                    $action['type'],
                    json_encode($action['data']),
                    $action['timestamp'] / 1000
                ]);
                
                $synced_actions[] = $action['type'];
            } catch (PDOException $e) {
                $errors[] = "Action {$action['type']}: " . $e->getMessage();
            }
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'synced' => [
            'posts' => count($synced_posts),
            'actions' => count($synced_actions)
        ],
        'posts' => $synced_posts,
        'errors' => $errors,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Sync failed: ' . $e->getMessage()
    ]);
}
