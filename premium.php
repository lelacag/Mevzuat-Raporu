<?php
/**
 * Premium page (currently disabled)
 *
 * Premium sayfası (şu anda kullanıma kapalı)
 *
 * When we're ready to re-enable paid subscriptions this file will resume
 * showing plans; until then we redirect visitors back to the home page.
 */
require_once __DIR__ . '/includes/header.php';

// temporarily disable real premium page – change to true when payments active
$PREMIUM_PAGE_ENABLED = false;
if (!$PREMIUM_PAGE_ENABLED) {
    // redirect with flash so user knows why they ended up here
    if (isset($_SESSION)) {
        $_SESSION['flash'] = 'Premium sayfası şu anda kapalı.';
    }
    header('Location: ' . BASE_PATH . '/');
    exit;
}

$user_id = get_current_user_id();

// Get pricing
$monthly = get_premium_setting('monthly_price', '5.00');
$yearly = get_premium_setting('yearly_price', '50.00');
$lifetime = get_premium_setting('lifetime_price', '150.00');
$currency = get_premium_setting('currency', 'USD');

// Determine if request comes from app (WebView) to enable app-specific flows
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_in_app = preg_match('/WebView|wv|myapp|com\.example\.webviewwrapper|CFNetwork|CriOS|FxiOS/i', $ua);

// Determine current active plan for logged-in user (used to highlight plan cards)
$current_plan = null;
if ($user_id) {
    // Prefer most recent active or pending subscription record
    $stmt = query("SELECT * FROM premium_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$user_id]);
    $sub_rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sub_rec && in_array($sub_rec['status'], ['active','cancel_pending','cancelled','cancel_pending'])) {
        $current_plan = $sub_rec['plan_type'] ?? null;
    } else {
        // Fallback: if users.is_premium and no subscription row (e.g., one-time/lifetime), detect lifetime
        $u = get_user($user_id);
        if ($u && $u['is_premium'] && empty($u['premium_until'])) {
            $current_plan = 'lifetime';
        }
    }
}
?>

<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/premium.css">

<?php
// Confirmation flow for immediate cancel (no-JS friendly)
if (!empty($_GET['confirm_immediate']) && $user_id):
    $csrf = generate_csrf_token();
    ?>
    <div class="main-container single-column">
        <main class="content-area">
            <h1 class="section-title">Aboneliği Hemen İptal Et</h1>
            <div class="form-alert form-alert-warning">Aboneliğiniz hemen iptal edilecek. Devam etmek istediğinize emin misiniz?</div>
            <form method="POST" action="<?= BASE_PATH ?>/api/cancel_subscription.php">
                <input type="hidden" name="immediate" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="btn btn-warning">Evet, Hemen İptal Et</button>
                <a class="btn" href="<?= BASE_PATH ?>/premium.php">Iptal</a>
            </form>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
endif;
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">⭐ Premium Üyelik</h1>

        <div class="premium-page minimal">
            <?php if ($user_id && is_user_premium($user_id)): ?>
                <div class="premium-active minimal">
                    <h2>⭐ Premium Üyelik</h2>
                    <p>Hesabınız tüm premium özelliklere açık.</p>

                    <?php
                    // Show subscription details and cancel option if a subscription row exists
                    $stmt = query("SELECT * FROM premium_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$user_id]);
                    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($sub):
                        $plan = htmlspecialchars($sub['plan_type'] ?? '');
                        $status = htmlspecialchars($sub['status'] ?? '');
                        $end = $sub['end_date'] ? htmlspecialchars($sub['end_date']) : null;
                    ?>
                        <div class="premium-sub-info">
                            <p><strong>Plan:</strong> <?= $plan ?> &nbsp; <strong>Durum:</strong> <?= $status ?><?php if ($end): ?> &nbsp; <strong>Bitiş:</strong> <?= $end ?><?php endif; ?></p>

                            <?php if ($status === 'active' || $status === 'cancel_pending'): ?>
                                <form method="POST" action="<?= BASE_PATH ?>/api/cancel_subscription.php" style="display:inline-block; margin-right:8px;">
                                    <button type="submit" class="btn btn-cancel">Aboneliği İptal Et (Dönem Sonunda)</button>
                                </form>
                                <a class="btn btn-warning" href="?confirm_immediate=1">Hemen İptal Et</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php else: ?>
                <p class="lead">Daha fazla paylaşım esnekliği, özel rozetler ve öncelikli destek. Basit, şeffaf fiyatlandırma.</p>
            <?php endif; ?>

            <div class="premium-features minimal">
                <ul>
                    <li>♾️ Sınırsız gönderi uzunluğu</li>
                    <li>✅ Gönderi düzenleme ve gelişmiş araçlar</li>
                    <li>⭐ Premium rozet ve özel rozet oluşturma</li>
                    <li>🔔 Özel etkinlik güncellemelerine erişim</li>
                </ul>
                <!-- Compact plan cards -->
                <?php if (!file_exists(__DIR__ . '/vendor/autoload.php') || getenv('STRIPE_SECRET_KEY') === false || getenv('STRIPE_PUBLISHABLE_KEY') === false): ?>
                    <div style="margin-top:12px;padding:10px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:6px;">Stripe ödeme sistemi yapılandırılmamış. Gerçek ödemeler için STRIPE_* environment değişkenlerini ve `composer require stripe/stripe-php` kurulumu yapın.</div>
                <?php endif; ?>
                <div class="plan-card <?= ($current_plan === 'monthly') ? 'selected' : '' ?>">
                    <div class="plan-name">Aylık</div>
                    <?php if ($current_plan === 'monthly'): ?><div class="active-badge">Aktif</div><?php endif; ?>
                    <div class="price">$<?= htmlspecialchars($monthly) ?> <span>/ay</span></div>
                    <?php if ($user_id && !is_user_premium($user_id)): ?>
                        <a href="<?= BASE_PATH ?>/premium_payment.php?plan=monthly" class="select-plan-btn">Abone Ol</a>
                    <?php elseif ($is_in_app && !is_user_premium($user_id)): ?>
                        <a href="myapp://buy?plan=monthly" class="select-plan-btn">Abone Ol (Uygulamada)</a>
                    <?php endif; ?>
                </div>

                <div class="plan-card popular <?= ($current_plan === 'yearly') ? 'selected' : '' ?>">
                    <div class="plan-name">Yıllık</div>
                    <?php if ($current_plan === 'yearly'): ?><div class="active-badge">Aktif</div><?php endif; ?>
                    <div class="price">$<?= htmlspecialchars($yearly) ?> <span>/yıl</span></div>
                    <?php if ($user_id && !is_user_premium($user_id)): ?>
                        <a href="<?= BASE_PATH ?>/premium_payment.php?plan=yearly" class="select-plan-btn">Abone Ol</a>
                    <?php elseif ($is_in_app && !is_user_premium($user_id)): ?>
                        <a href="myapp://buy?plan=yearly" class="select-plan-btn">Abone Ol (Uygulamada)</a>
                    <?php endif; ?>
                </div>

                <div class="plan-card <?= ($current_plan === 'lifetime') ? 'selected' : '' ?>">
                    <div class="plan-name">Ömür Boyu</div>
                    <?php if ($current_plan === 'lifetime'): ?><div class="active-badge">Aktif</div><?php endif; ?>
                    <div class="price">$<?= htmlspecialchars($lifetime) ?> <span>tek seferlik</span></div>
                    <?php if ($user_id && !is_user_premium($user_id)): ?>
                        <a href="<?= BASE_PATH ?>/premium_payment.php?plan=lifetime" class="select-plan-btn">Satın Al</a>
                    <?php elseif ($is_in_app && !is_user_premium($user_id)): ?>
                        <a href="myapp://buy?plan=lifetime" class="select-plan-btn">Satın Al (Uygulamada)</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$user_id): ?>
                <div class="login-prompt minimal">
                    <?php if ($is_in_app): ?>
                        <p>Uygulama üzerinden satın almak isterseniz, lütfen uygulamadaki <strong>Premium</strong> butonuna dokunun. <em>(Giriş gerekmeyebilir.)</em></p>
                    <?php else: ?>
                        <p>Premium'a erişmek için <a href="<?= BASE_PATH ?>/giris">giriş yapın</a> veya <a href="<?= BASE_PATH ?>/kayit">kayıt olun</a>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
