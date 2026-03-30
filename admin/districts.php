<?php /* EN + TR comments used. */
/**
 * Admin: District Management
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';

require_admin_perm('manage_districts');
$csrf_token = generate_csrf_token();

// Handle district creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_district'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Geçersiz istek (CSRF)';
    } else {
        $name = trim($_POST['district_name']);
    $code = strtoupper(trim($_POST['district_code']));
    $description = trim($_POST['description']);
    $lat = floatval($_POST['latitude']);
    $lng = floatval($_POST['longitude']);
    $radius = floatval($_POST['radius_km']);
    $max_members = intval($_POST['max_members']);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO districts (district_name, district_code, description, latitude, longitude, radius_km, max_members)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $code, $description, $lat, $lng, $radius, $max_members]);
        $success_msg = "District created successfully!";
    } catch (PDOException $e) {
        $error_msg = "Error creating district: " . $e->getMessage();
    }
}

// Handle online request approval
if (isset($_POST['approve_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error_msg = 'Geçersiz istek (CSRF)'; }
    else {
        $request_id = intval($_POST['request_id']);
        $stmt = $pdo->prepare(" 
            UPDATE district_online_requests 
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $request_id]);
        $success_msg = "Request approved!";
    }
}

// Handle online request rejection
if (isset($_POST['reject_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error_msg = 'Geçersiz istek (CSRF)'; }
    else {
        $request_id = intval($_POST['request_id']);
        $stmt = $pdo->prepare(" 
            UPDATE district_online_requests 
            SET status = 'rejected', approved_by = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $request_id]);
        $success_msg = "Request rejected!";
    }

// List all districts
$districts = $pdo->query("
    SELECT d.*, 
           COUNT(DISTINCT ud.user_id) as member_count,
           COUNT(DISTINCT dp.id) as post_count,
           MAX(dp.created_at_device) as last_activity
    FROM districts d
    LEFT JOIN user_districts ud ON d.district_id = ud.district_id AND ud.is_active = 1
    LEFT JOIN district_posts dp ON d.district_id = dp.district_id AND dp.is_deleted = 0
    GROUP BY d.district_id
    ORDER BY d.created_at DESC
")->fetchAll();

// Get pending online requests
$pending_requests = $pdo->query("
    SELECT dor.*, d.district_name, u.username
    FROM district_online_requests dor
    JOIN districts d ON dor.district_id = d.district_id
    JOIN users u ON dor.requested_by = u.id
    WHERE dor.status = 'pending'
    ORDER BY dor.created_at DESC
")->fetchAll();

include '_nav.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>District Management - Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .districts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .district-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            background: white;
        }
        
        .district-card.offline {
            border-left: 4px solid #f44336;
        }
        
        .district-card.online {
            border-left: 4px solid #4caf50;
        }
        
        .district-stats {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-online {
            background: #4caf50;
            color: white;
        }
        
        .status-offline {
            background: #666;
            color: white;
        }
        
        .request-card {
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            background: #fff9e6;
        }
        
        .form-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌐 District Management</h1>
        
        <?php if (isset($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_msg)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        
        <!-- Pending Online Requests -->
        <?php if (!empty($pending_requests)): ?>
        <div class="form-section">
            <h2>📡 Pending Online Connection Requests (<?= count($pending_requests) ?>)</h2>
            <?php foreach ($pending_requests as $request): ?>
            <div class="request-card">
                <h3><?= htmlspecialchars($request['district_name']) ?></h3>
                <p><strong>Requested by:</strong> <?= htmlspecialchars($request['username']) ?></p>
                <p><strong>Reason:</strong> <?= htmlspecialchars($request['request_reason']) ?></p>
                <p><strong>Requested:</strong> <?= date('Y-m-d H:i', strtotime($request['created_at'])) ?></p>
                
                <form method="post" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                    <button type="submit" name="approve_request" class="btn btn-success">Approve</button>
                    <button type="submit" name="reject_request" class="btn btn-danger">Reject</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Create New District -->
        <div class="form-section">
            <h2>➕ Create New District</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>District Name:</label>
                        <input type="text" name="district_name" required placeholder="Downtown Tech Hub">
                    </div>
                    
                    <div class="form-group">
                        <label>District Code:</label>
                        <input type="text" name="district_code" required placeholder="TECH001" pattern="[A-Z0-9]+" maxlength="20">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" rows="3" placeholder="Tech community in downtown area"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Latitude:</label>
                        <input type="number" name="latitude" step="0.000001" placeholder="40.7128">
                    </div>
                    
                    <div class="form-group">
                        <label>Longitude:</label>
                        <input type="number" name="longitude" step="0.000001" placeholder="-74.0060">
                    </div>
                    
                    <div class="form-group">
                        <label>Radius (km):</label>
                        <input type="number" name="radius_km" step="0.1" value="5.0" min="0.5" max="50">
                    </div>
                    
                    <div class="form-group">
                        <label>Max Members:</label>
                        <input type="number" name="max_members" value="1000" min="10" max="10000">
                    </div>
                </div>
                
                <button type="submit" name="create_district" class="btn btn-primary">Create District</button>
            </form>
        </div>
        
        <!-- Districts List -->
        <h2>📍 All Districts (<?= count($districts) ?>)</h2>
        <div class="districts-grid">
            <?php foreach ($districts as $district): ?>
            <div class="district-card <?= $district['is_active'] ? 'online' : 'offline' ?>">
                <h3><?= htmlspecialchars($district['district_name']) ?></h3>
                <p>
                    <strong>Code:</strong> <?= htmlspecialchars($district['district_code']) ?> 
                    <span class="status-badge status-<?= $district['is_active'] ? 'online' : 'offline' ?>">
                        <?= $district['is_active'] ? 'ACTIVE' : 'OFFLINE' ?>
                    </span>
                </p>
                
                <?php if ($district['description']): ?>
                <p><?= htmlspecialchars($district['description']) ?></p>
                <?php endif; ?>
                
                <div class="district-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $district['member_count'] ?></div>
                        <div class="stat-label">Members</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $district['post_count'] ?></div>
                        <div class="stat-label">Posts</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= number_format($district['radius_km'], 1) ?></div>
                        <div class="stat-label">Radius (km)</div>
                    </div>
                </div>
                
                <?php if ($district['latitude'] && $district['longitude']): ?>
                <p style="font-size: 12px; color: #666;">
                    📍 <?= number_format($district['latitude'], 6) ?>, <?= number_format($district['longitude'], 6) ?>
                </p>
                <?php endif; ?>
                
                <?php if ($district['last_activity']): ?>
                <p style="font-size: 12px; color: #666;">
                    Last activity: <?= date('Y-m-d H:i', strtotime($district['last_activity'])) ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
