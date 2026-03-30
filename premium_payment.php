<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/stripe.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}

// Check if already premium
if (is_user_premium($user_id)) {
    header("Location: " . BASE_PATH . "/premium.php");
    exit;
}

$plan = $_GET['plan'] ?? 'yearly';
$valid_plans = ['monthly', 'yearly', 'lifetime'];
if (!in_array($plan, $valid_plans)) {
    $plan = 'yearly';
}

// Get pricing
$prices = [
    'monthly' => get_premium_setting('monthly_price', '5.00'),
    'yearly' => get_premium_setting('yearly_price', '50.00'),
    'lifetime' => get_premium_setting('lifetime_price', '150.00')
];

$plan_names = [
    'monthly' => 'Aylık',
    'yearly' => 'Yıllık',
    'lifetime' => 'Ömür Boyu'
];

$selected_price = $prices[$plan];
$selected_name = $plan_names[$plan];
$user = get_user($user_id);
?>

<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/premium.css">

<div class="main-container single-column">
    <main class="content-area">
        <div class="payment-page">
            <div class="back-link">
                <a href="<?= BASE_PATH ?>/premium.php">← Planlara Geri Dön</a>
            </div>

            <h1 class="section-title">💳 Premium Ödeme</h1>

            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash flash-success">
                    <?= htmlspecialchars($_SESSION['flash_success']) ?>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <div class="payment-container">
                <div class="order-summary">
                    <h2>📋 Sipariş Özeti</h2>
                    <div class="summary-item">
                        <span>Plan:</span>
                        <strong><?= $selected_name ?></strong>
                    </div>
                    <div class="summary-item">
                        <span>Fiyat:</span>
                        <strong class="price-highlight">$<?= $selected_price ?></strong>
                    </div>
                </div>

                <div class="stripe-payment-simple">
                    <h2>💳 Kart ile Öde (Stripe)</h2>

                    <?php
                        // Show demo card block if in test mode, or for admins, or when explicitly requested via ?demo_cards=1
                        $show_demo = stripe_is_test_mode() || (function_exists('is_admin') && is_admin()) || (isset($_GET['demo_cards']) && $_GET['demo_cards'] == '1');
                    ?>
                    <?php if ($show_demo): ?>
                        <?php if (stripe_is_test_mode()): ?>
                            <div class="flash flash-info">Stripe test modu etkin (test anahtarları kullanılıyor). Bu işlem gerçek bir tahsilat gerçekleştirmez.</div>
                        <?php else: ?>
                            <div class="flash flash-warning">Demo mod: test kartları gösterilmektedir. Gerçek ödeme için Stripe Checkout kullanılacaktır.</div>
                        <?php endif; ?> 

                        <div class="demo-card-box">
                            <strong>Demo Kart Girişi (Sadece Gösterim)</strong>
                            <p class="muted small">Aşağıdaki alanlar yalnızca gösterim amaçlıdır. Kart bilgilerini sahada girmek yerine <em>"Kart ile Öde"</em> butonuna tıkladığınızda sizi Stripe checkout sayfasına yönlendireceğiz; gerçek kart bilgileri doğrudan Stripe tarafından alınır.</p>

                            <div class="flex-row" style="flex-wrap:wrap;">
                                <div style="flex:1;min-width:220px;">
                                    <label class="form-label">Kart Numarası</label>
                                    <input type="text" value="4242 4242 4242 4242" disabled class="input-full muted-bg">
                                </div>
                                <div style="width:120px;">
                                    <label class="form-label">Son Kullanma</label>
                                    <input type="text" value="12/34" disabled class="input-full muted-bg">
                                </div>
                                <div style="width:100px;">
                                    <label class="form-label">CVC</label>
                                    <input type="text" value="123" disabled class="input-full muted-bg">
                                </div>
                            </div>

                            <div class="muted mt-12">
                                Örnek test kartları:
                                <ul class="list-compact">
                                    <li>Visa (başarılı): <code>4242 4242 4242 4242</code></li>
                                    <li>3D Secure (gerektirir): <code>4000 0025 0000 3155</code></li>
                                    <li>Decline (card declined): <code>4000 0000 0000 9995</code></li>
                                </ul>
                            </div>
                        </div> 

                    <?php endif; ?>

                    <?php if (!is_stripe_configured()): ?>
                        <div class="flash flash-error">Stripe yapılandırılmamış. Test anahtarlarını ayarlamak için <code>STRIPE_TEST_SECRET_KEY</code> ve <code>STRIPE_TEST_PUBLISHABLE_KEY</code> çevre değişkenlerini ayarlayın.</div>
                    <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/stripe_create_session.php" class="payment-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="plan_type" value="<?= $plan ?>">

                            <div class="form-group">
                                <label for="name">Ad Soyad</label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['username']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email Adresiniz</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                                <small>Fatura/ödeme bilgileri bu adrese gönderilecek</small>
                            </div>

                            <div class="form-group">
                                <label for="company">Firma (isteğe bağlı)</label>
                                <input type="text" id="company" name="company" value="" placeholder="Firma adı (fatura için)">
                                <small>Ödeme için fatura bilgisi sağlamak istiyorsanız firma adını girin.</small>
                            </div>

                            <div class="form-group">
                                <label for="tax_id">VKN / T.C. Kimlik No (isteğe bağlı)</label>
                                <input type="text" id="tax_id" name="tax_id" value="" placeholder="Vergi No (sadece rakamlar)">
                                <small>Vergi numarası (şirketler için VKN, kişiler için T.C. Kimlik No). Fatura gereksinimi için gerekli olabilir.</small>
                            </div>

                            <div class="form-group">
                                <label for="address_line1">Adres (isteğe bağlı)</label>
                                <input type="text" id="address_line1" name="address_line1" value="" placeholder="Adres satırı 1">
                            </div>

                            <div class="form-group">
                                <label for="address_city">Şehir / Posta Kodu (isteğe bağlı)</label>
                                <input type="text" id="address_city" name="address_city" value="" placeholder="İl / İlçe / Posta Kodu">
                            </div>

                            <div class="form-group">
                                <label for="country">Ülke</label>
                                <input type="text" id="country" name="country" value="TR" placeholder="Ülke (ISO kodu)" required>
                                <small>Fatura için gerekli olan ülke kodu (ör. TR).</small>
                            </div>

                            <div class="form-group">
                                <label for="password">Parolanızı Girin (Onay)</label>
                                <input type="password" id="password" name="password" placeholder="Mevcut parolanız" autocomplete="current-password">
                                <small>Güvenlik için parolanızın girilmesi önerilir. (Opsiyonel)</small>
                            </div>

                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="terms" required>
                                    <span>Ödeme şartlarını ve koşullarını kabul ediyorum</span>
                                </label>
                            </div>

                            <div class="form-actions justify-end">
                                <a href="<?= BASE_PATH ?>/premium.php" class="btn btn-cancel">Geri</a>
                                <button type="submit" class="btn btn-submit">Kart ile Öde</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="payment-note">
                    <p><strong>ℹ️ Not:</strong> Kart bilgileri güvenli şekilde Stripe tarafından alınır; sunucumuz kart bilgilerini saklamaz. Fatura için lütfen gerçek adınızı ve email adresinizi kullanın.</p>
                </div>
            </div>
        </div>
    </main>
</div>

<style>
.payment-page {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.back-link {
    margin-bottom: 20px;
}

.back-link a {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
}

.payment-container {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.order-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.order-summary h2 {
    margin: 0 0 20px;
    color: #333;
    font-size: 18px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #dee2e6;
}

.summary-item:last-child {
    border-bottom: none;
}

.price-highlight {
    color: #667eea;
    font-size: 20px;
}

.payment-methods {
    margin-bottom: 30px;
}

.payment-methods h2 {
    margin-bottom: 20px;
    color: #333;
    font-size: 18px;
}

.payment-method-card {
    margin-bottom: 15px;
}

.payment-method-card input[type="radio"] {
    display: none;
}

.payment-method-card label {
    display: flex;
    align-items: center;
    padding: 20px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.payment-method-card input[type="radio"]:checked + label {
    border-color: #667eea;
    background: #f8f9ff;
}

.method-icon {
    font-size: 32px;
    margin-right: 15px;
}

.method-details h3 {
    margin: 0 0 5px;
    font-size: 16px;
    color: #333;
}

.method-details p {
    margin: 0;
    font-size: 13px;
    color: #666;
}

.payment-instructions {
    margin-bottom: 30px;
}

.payment-instructions h2 {
    margin-bottom: 20px;
    color: #333;
    font-size: 18px;
}

.instruction-step {
    display: flex;
    margin-bottom: 20px;
}

.step-number {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 18px;
    margin-right: 15px;
    flex-shrink: 0;
}

.step-content h3 {
    margin: 0 0 5px;
    font-size: 15px;
    color: #333;
}

.step-content p {
    margin: 0;
    font-size: 14px;
    color: #666;
    line-height: 1.6;
}

.payment-form {
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input[type="email"] {
    width: 100%;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
}

.form-group input[type="email"]:focus {
    outline: none;
    border-color: #667eea;
}

.form-group small {
    display: block;
    margin-top: 5px;
    font-size: 12px;
    color: #888;
}

.form-group input[type="checkbox"] {
    margin-right: 8px;
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
}

.btn {
    padding: 12px 30px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-cancel {
    background: #f5f5f5;
    color: #666;
}

.btn-submit {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.payment-note {
    padding: 15px;
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    border-radius: 4px;
    font-size: 13px;
    color: #856404;
}

.flash {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
}

.flash-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
