<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Check admin access
if (!is_admin()) {
    header("Location: " . BASE_PATH . "/index.php");
    exit;
}

$db = db_connect();

// Get pending badge requests
$pending_stmt = $db->query("
    SELECT cb.id, cb.user_id, u.username, cb.badge_text, cb.badge_color, cb.created_at
    FROM user_custom_badges cb
    JOIN users u ON cb.user_id = u.id
    WHERE cb.is_approved = 0 AND cb.is_rejected = 0
    ORDER BY cb.created_at DESC
");
$pending_badges = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get approved badges
$approved_stmt = $db->query("
    SELECT cb.id, cb.user_id, u.username, cb.badge_text, cb.badge_color, cb.approved_at
    FROM user_custom_badges cb
    JOIN users u ON cb.user_id = u.id
    WHERE cb.is_approved = 1
    ORDER BY cb.approved_at DESC
");
$approved_badges = $approved_stmt->fetchAll(PDO::FETCH_ASSOC);

// Admin preview handler for badges (server-side, no JS)
$admin_preview_text = null;
$admin_preview_color = null;
$admin_preview_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_badge_admin'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $admin_preview_error = 'Geçersiz istek (CSRF)';
    } else {
        $admin_preview_text = trim($_POST['badge_text'] ?? '');
        $admin_preview_color = $_POST['badge_color'] ?? '#2ecc71';
        if ($admin_preview_text === '') {
            $admin_preview_error = 'Önizlemek için rozet metni gerekli.';
        }
    }
}

require_once __DIR__ . '/_nav.php';
$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Özel Rozetler - Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin-badges.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 30px;
            color: #333;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            margin-bottom: 20px;
            color: #555;
            font-size: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }
        tr:hover {
            background: #fafafa;
        }
        .empty {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎨 Özel Rozet Yönetimi</h1>

        <?php if (!empty($admin_preview_text) || !empty($admin_preview_error)): ?>
            <div style="margin-bottom:18px;padding:12px;background:#fff;border:1px solid #eee;border-radius:6px;">
                <div style="font-weight:600;margin-bottom:8px;">Önizleme</div>
                <?php if (!empty($admin_preview_error)): ?>
                    <div style="color:#c0392b;font-weight:600;"><?= htmlspecialchars($admin_preview_error) ?></div>
                <?php else: ?>
                    <span class="badge-preview" style="background-color: <?= htmlspecialchars($admin_preview_color) ?>; padding:6px 10px; color:#fff; border-radius:10px; font-weight:700; display:inline-block;"><?= htmlspecialchars($admin_preview_text) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= count($pending_badges) ?></div>
                <div class="stat-label">Bekleyen İstek</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= count($approved_badges) ?></div>
                <div class="stat-label">Onaylı Rozet</div>
            </div>
        </div>

        <?php if (count($pending_badges) > 0): ?>
        <div class="section">
            <h2>🔔 Bekleyen Rozet İstekleri</h2>
            <table>
                <thead>
                    <tr>
                        <th>Kullanıcı</th>
                        <th>Rozet Önizleme</th>
                        <th>Tarih</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_badges as $badge): ?>
                    <tr>
                        <td><?= htmlspecialchars($badge['username']) ?></td>
                        <td>
                            <span class="badge-preview" style="background-color: <?= htmlspecialchars($badge['badge_color']) ?>; padding:6px 10px; border-radius:10px; color:#fff; font-weight:700; display:inline-block;">
                                <?= htmlspecialchars($badge['badge_text']) ?>
                            </span>
                            <form method="POST" style="display:inline;margin-left:8px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="badge_text" value="<?= htmlspecialchars($badge['badge_text']) ?>">
                                <input type="hidden" name="badge_color" value="<?= htmlspecialchars($badge['badge_color']) ?>">
                                <button type="submit" name="preview_badge_admin" value="1" class="btn-small">Önizleme</button>
                            </form>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($badge['created_at'])) ?></td>
                        <td>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_badge.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                                <button type="submit" class="btn btn-approve">Onayla</button>
                            </form>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_reject_badge.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                                <button type="submit" class="btn btn-reject">Reddet</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="section">
            <h2>🔔 Bekleyen Rozet İstekleri</h2>
            <div class="empty">Bekleyen rozet isteği yok</div>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2>✅ Onaylı Rozetler</h2>
            <?php if (count($approved_badges) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Kullanıcı</th>
                        <th>Rozet</th>
                        <th>Onay Tarihi</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved_badges as $badge): ?>
                    <tr>
                        <td><?= htmlspecialchars($badge['username']) ?></td>
                        <td>
                            <span class="badge-preview" style="background-color: <?= htmlspecialchars($badge['badge_color']) ?>">
                                <?= htmlspecialchars($badge['badge_text']) ?>
                            </span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($badge['approved_at'])) ?></td>
                        <td>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_remove_badge.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                                <button type="submit" class="btn btn-remove">Kaldır</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">Henüz onaylı rozet yok</div>
            <?php endif; ?>
        </div>
    </div>

<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
