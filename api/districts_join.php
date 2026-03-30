<?php
/**
 * API: Join District
 * Allows user to join a district
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

try {
    // Check if district exists and is active
    $stmt = $pdo->prepare("
        SELECT district_id, district_name, district_code, max_members 
        FROM districts 
        WHERE district_id = ? AND is_active = 1
    ");
    $stmt->execute([$district_id]);
    $district = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$district) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'District not found']);
        exit;
    }
    
    // Check if already a member
    $check = $pdo->prepare("
        SELECT id FROM user_districts 
        WHERE user_id = ? AND district_id = ? AND is_active = 1
    ");
    $check->execute([$user_id, $district_id]);
    
    if ($check->fetch()) {
        echo json_encode([
            'success' => false, 
            'error' => 'Already a member of this district'
        ]);
        exit;
    }
    
    // Check member limit
    $count = $pdo->prepare("
        SELECT COUNT(*) as count FROM user_districts 
        WHERE district_id = ? AND is_active = 1
    ");
    $count->execute([$district_id]);
    $current = $count->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($current >= $district['max_members']) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'District has reached maximum member capacity'
        ]);
        exit;
    }
    
    // Add user to district
    $insert = $pdo->prepare("
        INSERT INTO user_districts (user_id, district_id, role, is_active)
        VALUES (?, ?, 'member', 1)
    ");
    $insert->execute([$user_id, $district_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Successfully joined district',
        'district' => [
            'id' => $district['district_id'],
            'name' => $district['district_name'],
            'code' => $district['district_code']
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
