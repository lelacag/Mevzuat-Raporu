<?php
/**
 * District Dashboard - User's districts and feed
 */
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user's districts
$districts = $pdo->prepare("
    SELECT d.*, ud.role, ud.joined_at,
           COUNT(DISTINCT ud2.user_id) as member_count,
           COUNT(DISTINCT dp.id) as post_count,
           MAX(dp.created_at_device) as last_activity,
           (SELECT COUNT(*) FROM district_posts dp2 
            WHERE dp2.district_id = d.district_id 
            AND dp2.created_at_device > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as posts_today
    FROM user_districts ud
    JOIN districts d ON ud.district_id = d.district_id
    LEFT JOIN user_districts ud2 ON d.district_id = ud2.district_id AND ud2.is_active = 1
    LEFT JOIN district_posts dp ON d.district_id = dp.district_id AND dp.is_deleted = 0
    WHERE ud.user_id = ? AND ud.is_active = 1
    GROUP BY d.district_id
    ORDER BY last_activity DESC
");
$districts->execute([$user_id]);
$my_districts = $districts->fetchAll();

// Get available districts to join
$available = $pdo->prepare("
    SELECT d.*,
           COUNT(DISTINCT ud.user_id) as member_count
    FROM districts d
    LEFT JOIN user_districts ud ON d.district_id = ud.district_id AND ud.is_active = 1
    WHERE d.is_active = 1
    AND d.district_id NOT IN (
        SELECT district_id FROM user_districts 
        WHERE user_id = ? AND is_active = 1
    )
    GROUP BY d.district_id
    HAVING member_count < d.max_members
    ORDER BY d.created_at DESC
    LIMIT 10
");
$available->execute([$user_id]);
$available_districts = $available->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Districts</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .districts-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .district-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .district-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .district-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .district-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .district-code {
            background: #007bff;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .district-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 15px 0;
        }
        
        .stat-box {
            text-align: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        
        .district-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-online {
            background: #28a745;
        }
        
        .status-offline {
            background: #dc3545;
        }
        
        .role-badge {
            background: #ffc107;
            color: #000;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .role-badge.admin {
            background: #dc3545;
            color: white;
        }
        
        .role-badge.moderator {
            background: #17a2b8;
            color: white;
        }
        
        .available-districts {
            margin-top: 40px;
        }
        
        .app-download-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="districts-container">
        <h1>🌐 My Districts</h1>
        
        <!-- Android App Download Banner -->
        <div class="app-download-banner">
            <h2>📱 Download the Android App for Offline Mesh Networking!</h2>
            <p>Connect with your district even without internet using our mesh networking technology</p>
            <a href="#" class="btn btn-light">Download Android App</a>
        </div>
        
        <?php if (empty($my_districts)): ?>
        <div class="district-card">
            <p>You haven't joined any districts yet. Join one below to get started!</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($my_districts as $district): ?>
        <div class="district-card">
            <div class="district-header">
                <div>
                    <div class="district-name">
                        <span class="status-indicator status-<?= $district['is_active'] ? 'online' : 'offline' ?>"></span>
                        <?= htmlspecialchars($district['district_name']) ?>
                    </div>
                    <div class="mt-5">
                        <span class="district-code"><?= htmlspecialchars($district['district_code']) ?></span>
                        <span class="role-badge <?= $district['role'] ?>"><?= $district['role'] ?></span>
                    </div>
                </div>
            </div>
            
            <?php if ($district['description']): ?>
            <p class="muted mt-10">
                <?= htmlspecialchars($district['description']) ?>
            </p>
            <?php endif; ?>
            
            <div class="district-stats">
                <div class="stat-box">
                    <div class="stat-value"><?= $district['member_count'] ?></div>
                    <div class="stat-label">Members</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $district['post_count'] ?></div>
                    <div class="stat-label">Total Posts</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $district['posts_today'] ?></div>
                    <div class="stat-label">Posts Today</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= number_format($district['radius_km'], 1) ?> km</div>
                    <div class="stat-label">Coverage</div>
                </div>
            </div>
            
            <div class="district-actions">
                <a href="district_feed.php?id=<?= $district['district_id'] ?>" class="btn btn-primary">
                    📰 View Feed
                </a>
                
                <a href="district_members.php?id=<?= $district['district_id'] ?>" class="btn btn-secondary">
                    👥 Members
                </a>
                
                <?php if ($district['role'] === 'admin'): ?>
                <a href="<?= BASE_PATH ?>/districts_request_online.php?district_id=<?= $district['district_id'] ?>" class="btn btn-success">🌐 Connect to Main Network</a>
                <?php endif; ?>
            </div>
            
            <div class="meta muted">
                Joined: <?= date('M d, Y', strtotime($district['joined_at'])) ?>
                <?php if ($district['last_activity']): ?>
                | Last activity: <?= date('M d, Y H:i', strtotime($district['last_activity'])) ?>
                <?php endif; ?>
            </div> 
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Available Districts -->
        <?php if (!empty($available_districts)): ?>
        <div class="available-districts">
            <h2>📍 Available Districts to Join</h2>
            <?php foreach ($available_districts as $district): ?>
            <div class="district-card">
                <div class="district-header">
                    <div>
                        <div class="district-name"><?= htmlspecialchars($district['district_name']) ?></div>
                        <span class="district-code"><?= htmlspecialchars($district['district_code']) ?></span>
                    </div>
                </div>
                
                <?php if ($district['description']): ?>
                <p class="muted"><?= htmlspecialchars($district['description']) ?></p>
                <?php endif; ?> 
                
                <div class="meta mt-10">
                    <strong>Members:</strong> <?= $district['member_count'] ?> / <?= $district['max_members'] ?>
                    <?php if ($district['latitude'] && $district['longitude']): ?>
                    <br><strong>Location:</strong> <?= number_format($district['latitude'], 4) ?>, <?= number_format($district['longitude'], 4) ?>
                    <br><strong>Radius:</strong> <?= number_format($district['radius_km'], 1) ?> km
                    <?php endif; ?>
                </div>
                
                <form method="POST" action="<?= BASE_PATH ?>/districts_join.php" class="form-inline">
                    <input type="hidden" name="district_id" value="<?= $district['district_id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <button type="submit" class="btn btn-primary">Join District</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
