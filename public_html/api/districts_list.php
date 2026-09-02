<?php
/**
 * API: List Districts
 * Returns available districts, optionally filtered by location
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/plain');

// Optional location-based filtering
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 50; // km

try {
    if ($lat && $lng) {
        // Calculate distance and filter nearby districts
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                COUNT(DISTINCT ud.user_id) as member_count,
                COUNT(DISTINCT dp.id) as post_count,
                (6371 * acos(cos(radians(?)) * cos(radians(d.latitude)) * 
                cos(radians(d.longitude) - radians(?)) + sin(radians(?)) * 
                sin(radians(d.latitude)))) AS distance_km
            FROM districts d
            LEFT JOIN user_districts ud ON d.district_id = ud.district_id AND ud.is_active = 1
            LEFT JOIN district_posts dp ON d.district_id = dp.district_id AND dp.is_deleted = 0
            WHERE d.is_active = 1
            GROUP BY d.district_id
            HAVING distance_km <= ?
            ORDER BY distance_km ASC
        ");
        $stmt->execute([$lat, $lng, $lat, $radius]);
    } else {
        // Return all active districts
        $stmt = $pdo->query("
            SELECT 
                d.*,
                COUNT(DISTINCT ud.user_id) as member_count,
                COUNT(DISTINCT dp.id) as post_count
            FROM districts d
            LEFT JOIN user_districts ud ON d.district_id = ud.district_id AND ud.is_active = 1
            LEFT JOIN district_posts dp ON d.district_id = dp.district_id AND dp.is_deleted = 0
            WHERE d.is_active = 1
            GROUP BY d.district_id
            ORDER BY d.created_at DESC
        ");
    }
    
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add user membership status if logged in
    if (is_logged_in()) {
        $user_id = $_SESSION['user_id'];
        foreach ($districts as &$district) {
            $membership = $pdo->prepare("
                SELECT role, joined_at 
                FROM user_districts 
                WHERE user_id = ? AND district_id = ? AND is_active = 1
            ");
            $membership->execute([$user_id, $district['district_id']]);
            $member = $membership->fetch(PDO::FETCH_ASSOC);
            
            $district['is_member'] = $member ? true : false;
            $district['member_role'] = $member ? $member['role'] : null;
        }
        unset($district);
    }
    
    echo http_build_query([
        'success' => '1',
        'districts' => $districts,
        'count' => count($districts),
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo http_build_query(['success' => '0', 'error' => 'Database error']);
}
