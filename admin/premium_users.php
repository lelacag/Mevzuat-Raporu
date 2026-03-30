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
$csrf_token = generate_csrf_token();

// Get all premium users
$db = db_connect();
$stmt = $db->query("
    SELECT u.id, u.username, u.email, u.is_premium, u.premium_until, u.created_at,
           ps.plan_type, ps.start_date, ps.end_date, ps.status
    FROM users u
    LEFT JOIN premium_subscriptions ps ON u.id = ps.user_id AND ps.status = 'active'
    WHERE u.is_premium = 1
    ORDER BY u.premium_until DESC
");
$premium_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending subscription requests
$pending_stmt = $db->query("
    SELECT ps.id, ps.user_id, u.username, u.email, ps.plan_type, ps.start_date, ps.created_at
    FROM premium_subscriptions ps
    JOIN users u ON ps.user_id = u.id
    WHERE ps.status = 'pending'
    ORDER BY ps.created_at DESC
");
$pending_requests = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback revenue calc from plan price settings (amount column not in schema)
$plan_prices = [
    'monthly' => (float) get_premium_setting('monthly_price', '5.00'),
    'yearly'  => (float) get_premium_setting('yearly_price', '50.00'),
    'lifetime'=> (float) get_premium_setting('lifetime_price', '150.00'),
];

$totalRevenue = 0.0;
foreach ($premium_users as $user) {
    if (!empty($user['plan_type']) && isset($plan_prices[$user['plan_type']])) {
        $totalRevenue += $plan_prices[$user['plan_type']];
    }
}

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

        <div class="admin-page">
            <h1 class="page-title">⭐ Premium Kullanıcı Yönetimi</h1>

            <div class="stats"
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
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }
        .badge-expired {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-monthly {
            background: #d1e7dd;
            color: #0f5132;
        }
        .badge-yearly {
            background: #d1e7dd;
            color: #0f5132;
        }
        .badge-lifetime {
            background: #fff3cd;
            color: #997404;
        }
        .btn {
            padding: 8px 14px !important;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px !important;
            font-weight: bold !important;
            text-decoration: none;
            display: inline-block !important;
            margin: 2px;
        }
        .btn-approve {
            background: linear-gradient(135deg, #28a745 0%, #20903b 100%) !important;
            color: white !important;
            border: 2px solid #1e7e34 !important;
        }
        .btn-reject {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
            color: white !important;
            border: 2px solid #bd2130 !important;
        }
        .btn-revoke {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%) !important;
            color: white !important;
            border: 2px solid #495057 !important;
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
        <h1>⭐ Premium Kullanıcı Yönetimi</h1>

            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($premium_users) ?></div>
                    <div class="stat-label">Aktif Premium Kullanıcı</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count($pending_requests) ?></div>
                    <div class="stat-label">Bekleyen İstek</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">$<?= number_format($totalRevenue, 2) ?></div>
                    <div class="stat-label">Toplam Gelir</div>
                </div>
            </div>

            <?php if (count($pending_requests) > 0): ?>
            <div class="section">
                <h2>🔔 Bekleyen İstekler</h2>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Email</th>
                            <th>Plan</th>
                            <th>Tutar</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_requests as $request): ?>
                        <?php $request_amount = $plan_prices[$request['plan_type']] ?? 0; ?>
                        <tr>
                            <td>@<?= htmlspecialchars($request['username']) ?></td>
                            <td><?= htmlspecialchars($request['email']) ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($request['plan_type']) ?>">
                                    <?= htmlspecialchars($request['plan_type']) ?>
                                </span>
                            </td>
                            <td>$<?= number_format($request_amount, 2) ?></td>
                            <td><?= date('d.m.Y', strtotime($request['created_at'])) ?></td>
                            <td class="admin-actions">
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/premium_users.php">
                                    <input type="hidden" name="subscription_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $request['user_id'] ?>">
                                    <input type="hidden" name="plan_type" value="<?= $request['plan_type'] ?>">
                                    <button type="submit" class="btn btn-approve">✓ Onayla</button>
                                </form>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_reject_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/premium_users.php">
                                    <input type="hidden" name="subscription_id" value="<?= $request['id'] ?>">
                                    <button type="submit" class="btn btn-revoke">✕ Reddet</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="section">
                <h2>👥 Aktif Premium Kullanıcılar</h2>
                <?php if (count($premium_users) > 0): ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Email</th>
                            <th>Plan</th>
                            <th>Başlangıç</th>
                            <th>Bitiş</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($premium_users as $user): ?>
                        <tr>
                            <td>
                                <a href="<?= profile_url($user['username']) ?>" target="_blank">
                                    @<?= htmlspecialchars($user['username']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <?php if ($user['plan_type']): ?>
                                <span class="badge badge-<?= strtolower($user['plan_type']) ?>">
                                    <?= htmlspecialchars($user['plan_type']) ?>
                                </span>
                                <?php else: ?>
                                <span class="badge badge-user">Manuel</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $user['start_date'] ? date('d.m.Y', strtotime($user['start_date'])) : '-' ?></td>
                            <td>
                                <?php if ($user['premium_until']): ?>
                                    <?php if ($user['plan_type'] === 'lifetime'): ?>
                                        <strong style="color: #ffd700;">♾️ Ömür Boyu</strong>
                                    <?php else: ?>
                                        <?= date('d.m.Y', strtotime($user['premium_until'])) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $is_expired = $user['premium_until'] && strtotime($user['premium_until']) < time() && $user['plan_type'] !== 'lifetime';
                                ?>
                                <?php if ($is_expired): ?>
                                    <span class="badge badge-rookie">Süresi Dolmuş</span>
                                <?php else: ?>
                                    <span class="badge badge-premium">✓ Aktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-actions">
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_revoke_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/premium_users.php">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-revoke">↓ İptal Et</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">Henüz premium kullanıcı yok</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
                alert('Premium iptal edildi');
                location.reload();
            } else {
                alert('Hata: ' + (data.error || 'Bilinmeyen hata'));
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
