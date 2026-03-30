<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Check admin access
$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_billing');

$db = db_connect();

// Get current settings
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM premium_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

<style>
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #555;
        font-size: 14px;
    }
    input[type="text"],
    input[type="number"] {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    input:focus {
        outline: none;
        border-color: #2ecc71;
    }
    .helper-text {
        font-size: 12px;
        color: #999;
        margin-top: 5px;
    }
    .btn-save {
        padding: 12px 30px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
        color: white;
    }
    .btn-save:hover {
        background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
    }
</style>

<div class="main-container">
    <div class="content-wrapper">
        <div class="admin-page">
            <h1 class="page-title">⚙️ Premium Ayarları</h1>

            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash flash-success">
                    <?= htmlspecialchars($_SESSION['flash_success']) ?>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

        <form method="POST" action="<?= BASE_PATH ?>/api/admin_update_premium_settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="section">
                <h2>💰 Fiyatlandırma</h2>
                <div class="grid">
                    <div class="form-group">
                        <label for="monthly_price">Aylık Fiyat ($)</label>
                        <input type="number" id="monthly_price" name="monthly_price" 
                               value="<?= htmlspecialchars($settings['monthly_price'] ?? '5.00') ?>" 
                               step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="yearly_price">Yıllık Fiyat ($)</label>
                        <input type="number" id="yearly_price" name="yearly_price" 
                               value="<?= htmlspecialchars($settings['yearly_price'] ?? '50.00') ?>" 
                               step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="lifetime_price">Ömür Boyu Fiyat ($)</label>
                        <input type="number" id="lifetime_price" name="lifetime_price" 
                               value="<?= htmlspecialchars($settings['lifetime_price'] ?? '150.00') ?>" 
                               step="0.01" min="0" required>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>🎨 Rozet Ayarları</h2>
                <div class="form-group">
                    <label for="default_premium_badge">Varsayılan Premium Rozeti</label>
                    <input type="text" id="default_premium_badge" name="default_premium_badge" 
                           value="<?= htmlspecialchars($settings['default_premium_badge'] ?? '⭐ Premium') ?>" 
                           required>
                    <div class="helper-text">Özel rozet olmayan premium kullanıcılar için</div>
                </div>
                <div class="helper-text helper-note">
                    <strong>ℹ️ Not:</strong> Premium kullanıcılar sınırsız karakter ve sınırsız düzenleme süresine sahiptir.
                </div>
            </div>

            <div class="section">
                <h2>💳 Ödeme Bilgileri</h2>
                <div class="form-group">
                    <label for="currency">Para Birimi</label>
                    <input type="text" id="currency" name="currency" 
                           value="<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>" 
                           required>
                </div>
                <div class="form-group">
                    <label for="payment_email">Ödeme Email Adresi</label>
                    <input type="text" id="payment_email" name="payment_email" 
                           value="<?= htmlspecialchars($settings['payment_email'] ?? 'admin@example.com') ?>" 
                           required>
                    <div class="helper-text">Kullanıcıların ödeme için iletişime geçecekleri email</div>
                </div>
            </div>

            <div class="section">
                <h2>🔒 URL Session (Private Mode)</h2>
                <div class="form-group">
                    <label for="url_session_ttl">URL Session TTL (seconds)</label>
                    <input type="number" id="url_session_ttl" name="url_session_ttl"
                           value="<?= htmlspecialchars($settings['url_session_ttl'] ?? (defined('URL_SESSION_TTL') ? URL_SESSION_TTL : 3600)) ?>"
                           min="60" step="60">
                    <div class="helper-text">How long URL-based sessions remain valid. Shorter is more secure. Default 3600 (1 hour).</div>
                </div>
            </div>

            <button type="submit" class="btn-save">Ayarları Kaydet</button>
        </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
