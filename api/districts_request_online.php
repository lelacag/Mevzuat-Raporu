<?php
/**
 * API: Request District Online Connection
 * Allows district admin to request connecting district to main network
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $_SESSION['user_id'];
$district_id = isset($data['district_id']) ? intval($data['district_id']) : 0;
$reason = isset($data['reason']) ? trim($data['reason']) : '';

if (!$district_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'District ID required']);
    exit;
}

try {
    // Verify user is admin of district
    $stmt = $pdo->prepare("
        SELECT role FROM user_districts 
        WHERE user_id = ? AND district_id = ? AND is_active = 1
    ");
    $stmt->execute([$user_id, $district_id]);
    $membership = $stmt->fetch();
    
    if (!$membership || $membership['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only district admins can request online access']);
        exit;
    }
    
    // Check if there's already a pending or active request
    $check = $pdo->prepare("
        SELECT id, status FROM district_online_requests 
        WHERE district_id = ? AND status IN ('pending', 'approved', 'active')
        ORDER BY created_at DESC LIMIT 1
    ");
    $check->execute([$district_id]);
    $existing = $check->fetch();
    
    if ($existing) {
        echo json_encode([
            'success' => false,
            'error' => 'District already has a ' . $existing['status'] . ' online request'
        ]);
        exit;
    }
    
    // Create new request
    $insert = $pdo->prepare("
        INSERT INTO district_online_requests 
        (district_id, requested_by, status, request_reason)
        VALUES (?, ?, 'pending', ?)
    ");
    $insert->execute([$district_id, $user_id, $reason]);
    
    $request_id = $pdo->lastInsertId();

    // Notify platform admins about the new request.
    $district_stmt = $pdo->prepare("SELECT district_name FROM districts WHERE district_id = ? LIMIT 1");
    $district_stmt->execute([$district_id]);
    $district = $district_stmt->fetch();
    $district_name = $district['district_name'] ?? 'Bilinmeyen bölge';

    $user = get_user($user_id);
    $requester_username = $user['username'] ?? 'Bilinmeyen kullanıcı';

    notify_platform_admins_about_district_online_request($district_id, $district_name, $requester_username, $user_id, $reason, $request_id);

    echo json_encode([
        'success' => true,
        'message' => 'Online access request submitted',
        'request_id' => $request_id,
        'status' => 'pending'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
