<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Check admin access
if (!is_admin() && !admin_has_perm(null, 'manage_billing') && !admin_has_perm(null, 'view_billing_reports')) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$csrf_token = generate_csrf_token();
$page_self = BASE_PATH . '/admin/premium_users.php';
$db = db_connect();

// Active premium users (flag on users + optional active subscription)
$premium_users = $db->query("
    SELECT u.id, u.username, u.email, u.is_premium, u.premium_until, u.created_at,
           ps.id AS subscription_id, ps.plan_type, ps.start_date, ps.end_date, ps.status
    FROM users u
    LEFT JOIN premium_subscriptions ps ON u.id = ps.user_id AND ps.status = 'active'
    WHERE u.is_premium = 1
    ORDER BY u.premium_until DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Pending subscription requests
$pending_requests = $db->query("
    SELECT ps.id, ps.user_id, u.username, u.email, ps.plan_type, ps.start_date, ps.created_at
    FROM premium_subscriptions ps
    JOIN users u ON ps.user_id = u.id
    WHERE ps.status = 'pending'
    ORDER BY ps.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Full subscription history (Stripe / IAP / manual)
$subscriptions = $db->query("
    SELECT ps.*, u.username, u.email
    FROM premium_subscriptions ps
    JOIN users u ON ps.user_id = u.id
    ORDER BY ps.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fallback revenue calc from plan price settings
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

$active_count = count($premium_users);
$pending_count = count($pending_requests);
$subscription_count = count($subscriptions);

$fmt_date = static function ($value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime((string) $value);
    if ($ts === false) {
        return htmlspecialchars((string) $value);
    }
    return date('d.m.Y H:i', $ts);
};

$short_id = static function (?string $value, int $keep = 10): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen')) {
        $len = mb_strlen($value);
        if ($len <= ($keep * 2 + 1)) {
            return $value;
        }
        return mb_substr($value, 0, $keep) . '…' . mb_substr($value, -$keep);
    }
    $len = strlen($value);
    if ($len <= ($keep * 2 + 1)) {
        return $value;
    }
    return substr($value, 0, $keep) . '…' . substr($value, -$keep);
};

$payload_preview = static function (?string $raw): array {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return ['empty' => true, 'text' => '', 'full' => ''];
    }

    $decoded = $raw;
    $b64 = base64_decode($raw, true);
    if ($b64 !== false && $b64 !== '') {
        $maybe = @unserialize($b64, ['allowed_classes' => false]);
        if (is_array($maybe) || is_object($maybe)) {
            $decoded = json_encode($maybe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($decoded === false) {
                $decoded = print_r($maybe, true);
            }
        } elseif ($b64 !== $raw && preg_match('/^[\x09\x0A\x0D\x20-\x7E]+$/', $b64)) {
            $decoded = $b64;
        }
    } else {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }

    $decoded = trim((string) $decoded);
    $preview = $decoded;
    if (function_exists('mb_strlen') && mb_strlen($preview) > 180) {
        $preview = mb_substr($preview, 0, 180) . '…';
    } elseif (strlen($preview) > 180) {
        $preview = substr($preview, 0, 180) . '…';
    }

    return ['empty' => false, 'text' => $preview, 'full' => $decoded];
};


$status_badge = static function (string $status): string {
    $map = [
        'active' => 'badge-premium',
        'pending' => 'badge-rookie',
        'cancelled' => 'badge-free',
        'canceled' => 'badge-free',
        'expired' => 'badge-free',
        'rejected' => 'badge-free',
    ];
    $class = $map[strtolower($status)] ?? 'badge-user';
    return '<span class="badge ' . htmlspecialchars($class) . '">' . htmlspecialchars($status) . '</span>';
};

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

<div class="admin-page">
    <h1 class="page-title">⭐ Premium Yönetimi</h1>
    <p class="muted" style="margin-top:-8px;margin-bottom:18px;">
        Aktif premium kullanıcılar, bekleyen istekler, abonelik kayıtları ve mutabakat işlemleri tek sayfada.
    </p>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="card-box padded admin-note-success">
            <?= htmlspecialchars($_SESSION['flash']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="card-box padded admin-note-error">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= (int) $active_count ?></div>
            <div class="stat-label">Aktif Premium Kullanıcı</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= (int) $pending_count ?></div>
            <div class="stat-label">Bekleyen İstek</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= (int) $subscription_count ?></div>
            <div class="stat-label">Toplam Abonelik Kaydı</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">$<?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-label">Tahmini Aktif Gelir</div>
        </div>
    </div>

    <div class="section premium-jumpbar" style="margin-bottom:18px;">
        <div class="admin-actions">
            <a href="#pending-requests" class="btn btn-compact">Bekleyen İstekler</a>
            <a href="#active-premium" class="btn btn-compact">Aktif Kullanıcılar</a>
            <a href="#subscriptions" class="btn btn-compact">Abonelikler</a>
            <a href="#reconcile" class="btn btn-compact">Mutabakat</a>
            <a href="<?= BASE_PATH ?>/admin/premium_settings.php" class="btn btn-compact">Premium Ayarları</a>
            <form method="POST" action="<?= BASE_PATH ?>/admin/reverify_all.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                <button class="btn btn-save" type="submit">Bekleyen IAP'leri Yeniden Doğrula</button>
            </form>
        </div>
    </div>

    <?php if ($pending_count > 0): ?>
    <div class="section" id="pending-requests">
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
                            <td>
                                <?php if (function_exists('profile_url')): ?>
                                    <a href="<?= profile_url($request['username']) ?>" target="_blank">@<?= htmlspecialchars($request['username']) ?></a>
                                <?php else: ?>
                                    <a href="<?= BASE_PATH ?>/profile.php?u=<?= urlencode($request['username']) ?>">@<?= htmlspecialchars($request['username']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($request['email']) ?></td>
                            <td><span class="badge badge-user"><?= htmlspecialchars($request['plan_type']) ?></span></td>
                            <td>$<?= number_format((float) $request_amount, 2) ?></td>
                            <td><?= $request['created_at'] ? date('d.m.Y H:i', strtotime($request['created_at'])) : '-' ?></td>
                            <td class="admin-actions">
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= htmlspecialchars($page_self) ?>">
                                    <input type="hidden" name="subscription_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $request['user_id'] ?>">
                                    <input type="hidden" name="plan_type" value="<?= htmlspecialchars($request['plan_type']) ?>">
                                    <button type="submit" class="btn btn-save">✓ Onayla</button>
                                </form>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_reject_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= htmlspecialchars($page_self) ?>">
                                    <input type="hidden" name="subscription_id" value="<?= (int) $request['id'] ?>">
                                    <button type="submit" class="btn btn-warning-compact">✕ Reddet</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="section" id="pending-requests">
        <h2>🔔 Bekleyen İstekler</h2>
        <div class="empty-state">Bekleyen premium isteği yok</div>
    </div>
    <?php endif; ?>

    <div class="section" id="active-premium">
        <h2>👥 Aktif Premium Kullanıcılar</h2>
        <?php if ($active_count > 0): ?>
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
                        <?php
                        $is_expired = !empty($user['premium_until'])
                            && strtotime($user['premium_until']) < time()
                            && ($user['plan_type'] ?? '') !== 'lifetime';
                        ?>
                        <tr>
                            <td>
                                <?php if (function_exists('profile_url')): ?>
                                    <a href="<?= profile_url($user['username']) ?>" target="_blank">@<?= htmlspecialchars($user['username']) ?></a>
                                <?php else: ?>
                                    <a href="<?= BASE_PATH ?>/profile.php?u=<?= urlencode($user['username']) ?>">@<?= htmlspecialchars($user['username']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <?php if (!empty($user['plan_type'])): ?>
                                    <span class="badge badge-user"><?= htmlspecialchars($user['plan_type']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-user">Manuel</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($user['start_date']) ? date('d.m.Y', strtotime($user['start_date'])) : '-' ?></td>
                            <td>
                                <?php if (!empty($user['premium_until'])): ?>
                                    <?php if (($user['plan_type'] ?? '') === 'lifetime'): ?>
                                        <strong>♾️ Ömür Boyu</strong>
                                    <?php else: ?>
                                        <?= date('d.m.Y', strtotime($user['premium_until'])) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_expired): ?>
                                    <span class="badge badge-rookie">Süresi Dolmuş</span>
                                <?php else: ?>
                                    <span class="badge badge-premium">✓ Aktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-actions">
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_revoke_premium.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= htmlspecialchars($page_self) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <button type="submit" class="btn btn-warning-compact">↓ İptal Et</button>
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

    <div class="section premium-section" id="subscriptions">
        <h2>📋 Premium Abonelikler</h2>
        <?php if ($subscription_count > 0): ?>
        <div class="premium-card-list">
            <?php foreach ($subscriptions as $r): ?>
                <?php
                    $status = (string) ($r['status'] ?? '');
                    $cust = (string) ($r['stripe_customer_id'] ?? '');
                    $sub_id = (string) ($r['stripe_subscription_id'] ?? '');
                    $pay_method = (string) ($r['payment_method'] ?? '');
                ?>
                <article class="premium-card">
                    <div class="premium-card-top">
                        <div class="premium-card-identity">
                            <div class="premium-card-title-row">
                                <span class="premium-id-pill">#<?= (int) $r['id'] ?></span>
                                <a class="premium-user-link" href="<?= BASE_PATH ?>/profile.php?u=<?= urlencode($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a>
                                <?= $status_badge($status) ?>
                                <?php if (!empty($r['plan_type'])): ?>
                                    <span class="badge badge-user"><?= htmlspecialchars((string) $r['plan_type']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="premium-meta-line"><?= htmlspecialchars((string) $r['email']) ?></div>
                        </div>
                        <div class="premium-card-actions admin-actions">
                            <?php if ($status !== 'active'): ?>
                                <form method="POST" action="<?= BASE_PATH ?>/admin/premium_actions.php">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-save">Aktifleştir</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="<?= BASE_PATH ?>/admin/premium_actions.php">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-warning-compact">İptal</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="premium-kv-grid">
                        <div class="premium-kv">
                            <span class="premium-kv-label">Başlangıç</span>
                            <span class="premium-kv-value"><?= $fmt_date($r['start_date'] ?? null) ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Bitiş</span>
                            <span class="premium-kv-value"><?= $fmt_date($r['end_date'] ?? null) ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Ödeme</span>
                            <span class="premium-kv-value"><?= $pay_method !== '' ? htmlspecialchars($pay_method) : '—' ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Oluşturulma</span>
                            <span class="premium-kv-value"><?= $fmt_date($r['created_at'] ?? null) ?></span>
                        </div>
                    </div>

                    <div class="premium-id-block">
                        <div class="premium-id-row">
                            <span class="premium-kv-label">Stripe Customer</span>
                            <?php if ($cust !== ''): ?>
                                <a class="premium-mono-link" href="https://dashboard.stripe.com/customers/<?= htmlspecialchars($cust) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($cust) ?>">
                                    <code><?= htmlspecialchars($short_id($cust)) ?></code>
                                </a>
                                <input type="text" class="premium-copy-field" readonly value="<?= htmlspecialchars($cust) ?>" aria-label="Stripe Customer ID">
                            <?php else: ?>
                                <span class="premium-empty">—</span>
                            <?php endif; ?>
                        </div>
                        <div class="premium-id-row">
                            <span class="premium-kv-label">Stripe Subscription</span>
                            <?php if ($sub_id !== ''): ?>
                                <a class="premium-mono-link" href="https://dashboard.stripe.com/subscriptions/<?= htmlspecialchars($sub_id) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($sub_id) ?>">
                                    <code><?= htmlspecialchars($short_id($sub_id)) ?></code>
                                </a>
                                <input type="text" class="premium-copy-field" readonly value="<?= htmlspecialchars($sub_id) ?>" aria-label="Stripe Subscription ID">
                            <?php else: ?>
                                <span class="premium-empty">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Abonelik kaydı yok</div>
        <?php endif; ?>
    </div>

    <div class="section premium-section" id="reconcile">
        <h2>🔁 Premium Mutabakat</h2>
        <p class="premium-section-note">
            Satıcı (IAP/Stripe) işlem kimlikleri ve payload üzerinden abonelik doğrulama.
        </p>
        <?php if ($subscription_count > 0): ?>
        <div class="premium-card-list">
            <?php foreach ($subscriptions as $r): ?>
                <?php
                    $status = (string) ($r['status'] ?? '');
                    $platform = trim((string) ($r['platform'] ?? ''));
                    $vendor_tx = trim((string) ($r['vendor_transaction_id'] ?? ''));
                    $vendor_token = trim((string) ($r['vendor_purchase_token'] ?? ''));
                    $vendor_show = $vendor_tx !== '' ? $vendor_tx : $vendor_token;
                    $payload = $payload_preview($r['vendor_payload'] ?? '');
                    $vendor_status = trim((string) ($r['vendor_status'] ?? ''));
                ?>
                <article class="premium-card premium-card-reconcile">
                    <div class="premium-card-top">
                        <div class="premium-card-identity">
                            <div class="premium-card-title-row">
                                <span class="premium-id-pill">#<?= (int) $r['id'] ?></span>
                                <a class="premium-user-link" href="<?= BASE_PATH ?>/profile.php?u=<?= urlencode($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a>
                                <?= $status_badge($status) ?>
                                <?php if (!empty($r['plan_type'])): ?>
                                    <span class="badge badge-user"><?= htmlspecialchars((string) $r['plan_type']) ?></span>
                                <?php endif; ?>
                                <?php if ($platform !== ''): ?>
                                    <span class="badge badge-free"><?= htmlspecialchars($platform) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="premium-meta-line"><?= htmlspecialchars((string) $r['email']) ?></div>
                        </div>
                        <div class="premium-card-actions admin-actions">
                            <form method="POST" action="<?= BASE_PATH ?>/admin/admin_reverify.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-compact">Yeniden Doğrula</button>
                            </form>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_revoke_premium.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="referer" value="<?= htmlspecialchars($page_self . '#reconcile') ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                                <button type="submit" class="btn btn-warning-compact">İptal Et</button>
                            </form>
                        </div>
                    </div>

                    <div class="premium-kv-grid">
                        <div class="premium-kv">
                            <span class="premium-kv-label">Platform</span>
                            <span class="premium-kv-value"><?= $platform !== '' ? htmlspecialchars($platform) : '—' ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Vendor Status</span>
                            <span class="premium-kv-value"><?= $vendor_status !== '' ? htmlspecialchars($vendor_status) : '—' ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Doğrulama</span>
                            <span class="premium-kv-value"><?= $fmt_date($r['validated_at'] ?? null) ?></span>
                        </div>
                        <div class="premium-kv">
                            <span class="premium-kv-label">Bitiş</span>
                            <span class="premium-kv-value"><?= $fmt_date($r['end_date'] ?? null) ?></span>
                        </div>
                    </div>

                    <div class="premium-id-block">
                        <div class="premium-id-row">
                            <span class="premium-kv-label">Vendor Tx / Token</span>
                            <?php if ($vendor_show !== ''): ?>
                                <code class="premium-mono" title="<?= htmlspecialchars($vendor_show) ?>"><?= htmlspecialchars($short_id($vendor_show, 14)) ?></code>
                                <input type="text" class="premium-copy-field" readonly value="<?= htmlspecialchars($vendor_show) ?>" aria-label="Vendor transaction or token">
                            <?php else: ?>
                                <span class="premium-empty">—</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <details class="premium-payload">
                        <summary>Vendor Payload <?= $payload['empty'] ? '(yok)' : '' ?></summary>
                        <?php if ($payload['empty']): ?>
                            <div class="premium-empty">Payload kaydı yok</div>
                        <?php else: ?>
                            <pre class="premium-payload-body"><?= htmlspecialchars($payload['full']) ?></pre>
                        <?php endif; ?>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Mutabakat için kayıt yok</div>
        <?php endif; ?>
    </div>

</div>


<?php include __DIR__ . '/_footer.php'; ?>
