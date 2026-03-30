<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$profile_user = get_user($current_user_id);
if (!$profile_user) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$errors = [];

$preview_badge_text = null;
$preview_badge_color = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Regenerate event code (premium users only)
    if (isset($_POST['regenerate_event_code']) && is_user_premium($current_user_id)) {
        try {
            $new = regenerate_event_code($current_user_id);
            $_SESSION['flash'] = 'Etkinlik kodunuz yenilendi: ' . htmlspecialchars($new);
        } catch (Exception $e) {
            $_SESSION['flash'] = 'Kod yenilenemedi: ' . $e->getMessage();
        }
        header('Location: ' . profile_url($profile_user['username']));
        exit;
    }

    // Preview badge request (no-JS, server-side)
    if (isset($_POST['preview_badge'])) {
        $preview_badge_text = trim($_POST['custom_badge'] ?? '');
        $preview_badge_color = $_POST['badge_color'] ?? '#2ecc71';
        if ($preview_badge_text === '') {
            $errors[] = 'Önizleme için rozet metni gerekli.';
        }
        // Do not proceed with saving; just render preview below
    } else {
        $bio = $_POST['bio'] ?? '';
        $notify_by_email = isset($_POST['notify_by_email']) ? 1 : 0;
        $notify_on_mention = isset($_POST['notify_on_mention']) ? 1 : 0;
        $notify_on_reply = isset($_POST['notify_on_reply']) ? 1 : 0;
        $notify_on_report = isset($_POST['notify_on_report']) ? 1 : 0;
        $notify_on_system = isset($_POST['notify_on_system']) ? 1 : 0;

        if (strlen($bio) > 500) {
            $errors[] = 'Biyografi 500 karakterden uzun olamaz.';
        } else {
            $bio = sanitize_input($bio);
            query("UPDATE users SET bio = ?, notify_by_email = ?, notify_on_mention = ?, notify_on_reply = ?, notify_on_report = ?, notify_on_system = ? WHERE id = ?", [$bio, $notify_by_email, $notify_on_mention, $notify_on_reply, $notify_on_report, $notify_on_system, $current_user_id]);
            
            // Handle custom badge for premium users
            if (is_user_premium($current_user_id)) {
                $custom_badge = trim($_POST['custom_badge'] ?? '');
                $badge_color = $_POST['badge_color'] ?? '#2ecc71';
                
                // Validate color is one of the allowed colors
                $allowed_colors = ['#2ecc71', '#3498db', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c', '#34495e', '#e67e22'];
                if (!in_array($badge_color, $allowed_colors)) {
                    $badge_color = '#2ecc71'; // fallback to green
                }
                
                if (!empty($custom_badge)) {
                    if (strlen($custom_badge) > 20) {
                        $errors[] = 'Rozet metni 20 karakterden uzun olamaz.';
                    } else {
                        // Delete old badge
                        query("DELETE FROM user_custom_badges WHERE user_id = ?", [$current_user_id]);
                        // Insert new badge (auto-approved for premium users) using new status enum
                        query("INSERT INTO user_custom_badges (user_id, badge_text, badge_color, status) VALUES (?, ?, ?, 'approved')", [$current_user_id, $custom_badge, $badge_color]);
                    }
                } else {
                    // Remove badge if empty
                    query("DELETE FROM user_custom_badges WHERE user_id = ?", [$current_user_id]);
                }
            }
            
            if (empty($errors)) {
                $_SESSION['flash'] = 'Profiliniz güncellendi.';
                header('Location: ' . profile_url($profile_user['username']));
                exit;
            }
        }
    }
}

// Get current custom badge if exists
$current_badge = null;
if (is_user_premium($current_user_id)) {
    $current_badge = get_user_custom_badge($current_user_id);
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="main-container single-column">
    <main class="content-area form-centered">
        <h1 class="section-title">Biyografiyi Düzenle</h1>

        <?php if (!empty($errors)): ?>
            <ul class="errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST">
            <div class="entry-form">
                <label class="form-label">Biyografi</label>
                <textarea name="bio" class="textarea-large" maxlength="500" placeholder="Kendinizi tanıtın..."><?= sanitize_input($profile_user['bio'] ?? '') ?></textarea>
                
                <?php if (is_user_premium($current_user_id)): ?>
                    <div class="premium-panel">
                        <h3 class="premium-title">⭐ Premium: Özel Rozet & Etkinlik Kodu</h3>

                        <div class="form-row">
                            <label class="form-label">Etkinlik Kodu</label>
                            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                <div style="font-family:monospace;font-weight:700;padding:8px 12px;background:#f5f5f5;border-radius:6px;border:1px solid #eee;">
                                    <?= htmlspecialchars($profile_user['event_code'] ?? get_or_create_event_code($current_user_id)) ?>
                                </div>
                                <form method="POST" style="display:inline;">
                                    <button type="submit" name="regenerate_event_code" value="1" class="btn-outline small">Kodu Yenile</button>
                                </form>
                                <div class="hint muted small">Bu kodu etkinliklerde kullanıcı adınızla birlikte temsil edebilirsiniz. İsterseniz yenileyebilirsiniz.</div>
                            </div>
                        </div>

                        <hr style="margin:14px 0;" />

                        <h3 class="premium-title small">Özel Rozet</h3>
                        <div class="form-row">
                            <label class="form-label">Rozet Metni (max 20 karakter)</label>
                            <input type="text" name="custom_badge" maxlength="20" 
                                   value="<?= htmlspecialchars($current_badge['badge_text'] ?? '') ?>"
                                   placeholder="Örn: Kod Sihirbazı"
                                   class="form-control input-full">
                        </div>
                        <div class="form-row mt-15">
                            <label class="form-label">Rozet Rengi (Seçin)</label>
                            <div class="color-grid">
                                <?php 
                                $colors = [
                                    '#2ecc71' => 'Yeşil',
                                    '#3498db' => 'Mavi', 
                                    '#e74c3c' => 'Kırmızı',
                                    '#f39c12' => 'Turuncu',
                                    '#9b59b6' => 'Mor',
                                    '#1abc9c' => 'Turkuaz',
                                    '#34495e' => 'Koyu Gri',
                                    '#e67e22' => 'Portakal'
                                ];
                                $selected_color = $current_badge['badge_color'] ?? '#2ecc71';
                                foreach ($colors as $value => $label): 
                                    $hex = ltrim($value, '#');
                                ?>
                                    <label class="color-option color-<?= $hex ?> <?= $selected_color === $value ? 'selected' : '' ?>">
                                        <input type="radio" name="badge_color" value="<?= $value ?>" <?= $selected_color === $value ? 'checked' : '' ?> required class="radio-small">
                                        <span class="swatch color-<?= $hex ?>"></span>
                                        <span class="swatch-label"><?= $label ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="hint muted small mt-8">💡 Yukarıdaki renklerden birini seçin</p>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="preview_badge" value="1" class="btn-outline" <?php if (trim($_POST['custom_badge'] ?? '') === '') echo 'disabled'; ?>>Önizleme</button>
                            <?php if (!empty($preview_badge_text) || !empty($current_badge['badge_text'])): ?>
                                <?php $display_text = $preview_badge_text ?? $current_badge['badge_text']; $display_color = $preview_badge_color ?? $current_badge['badge_color']; ?>
                                <div class="badge-preview">
                                    <strong>Önizleme:</strong>
                                    <?php $pill_hex = ltrim($display_color, '#'); ?>
                                    <span class="badge-pill color-<?= $pill_hex ?>">
                                        <?= htmlspecialchars($display_text) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="blue-panel">
                    <h3 class="section-subtitle">🔒 Şifre Değiştir</h3>
                    <p>Şifrenizi güncellemek için aşağıdaki butona tıklayın.</p>
                    <div class="form-row">
                        <a href="password_change.php" class="btn btn-post">Şifreyi Değiştir</a>
                    </div>
                </div>

                <h3 class="section-subtitle">📧 E-posta Bildirimleri</h3>
                <div class="form-row">
                    <label><input type="checkbox" name="notify_by_email" <?= !empty($profile_user['notify_by_email']) ? 'checked' : '' ?>> E-posta ile bildirim al</label>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="notify_on_mention" <?= !empty($profile_user['notify_on_mention']) ? 'checked' : '' ?>> Bahsedildiğimde</label>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="notify_on_reply" <?= !empty($profile_user['notify_on_reply']) ? 'checked' : '' ?>> Gönderilerime yanıt geldiğinde</label>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="notify_on_report" <?= !empty($profile_user['notify_on_report']) ? 'checked' : '' ?>> Gönderilerim rapor edildiğinde</label>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="notify_on_system" <?= !empty($profile_user['notify_on_system']) ? 'checked' : '' ?>> Sistem mesajları (ban/aktivasyon)</label>
                </div>
                
                <?php if (defined('SMS_MODULE_ENABLED') && SMS_MODULE_ENABLED): ?>
                <h3 class="section-subtitle">📱 SMS ile Gönderi Paylaşma</h3>
                <div class="info-panel sms-panel">
                    <?php if (!empty($profile_user['phone_verified'])): ?>
                        <p style="margin: 0 0 10px 0; color: #0c5460;">
                            ✓ <strong>Telefon Doğrulandı:</strong> <?= htmlspecialchars($profile_user['phone_number']) ?>
                        </p>
                        <small class="muted">
                            Artık <strong><?= defined('SMS_NUMBER') && SMS_NUMBER ? SMS_NUMBER : 'belirtilen numaraya' ?></strong> SMS göndererek profil paylaşımı yapabilirsiniz.
                        </small>
                    <?php else: ?>
                        <p style="margin: 0 0 10px 0; color: #0c5460;">
                            SMS ile gönderi paylaşmak için telefon numaranızı doğrulayın.
                        </p>
                        <a href="<?= BASE_PATH ?>/modules/sms/verify_phone.php" class="btn btn-primary mt-5">
                            Telefon Numaranızı Doğrulayın
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <h3 class="section-subtitle danger-subtitle">⚠️ Tehlikeli Bölge</h3>
                <div class="danger-panel">
                    
                    <div class="danger-section">
                        <h4 class="danger-heading">Hesabı Devre Dışı Bırak</h4>
                        <p class="muted">
                            Hesabınız geçici olarak devre dışı bırakılır. Profiliniz gizlenir ve giriş yapamazsınız.
                            Hesabınızı yeniden etkinleştirmek için tekrar giriş yapmanız yeterlidir.
                        </p>
                        <form method="POST" action="<?= BASE_PATH ?>/api/disable_account.php" class="form-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="btn-warning">
                                Hesabı Devre Dışı Bırak
                            </button>
                        </form> 
                    </div>
                    
                    <div>
                        <h4 class="danger-heading">Hesabı Kalıcı Olarak Sil</h4>
                        <p class="muted">
                            <strong>Dikkat:</strong> Bu işlem geri alınamaz! Hesabınız ve tüm verileriniz kalıcı olarak silinecektir.
                        </p>
                        <a href="<?= BASE_PATH ?>/delete_account_confirm.php" class="btn btn-danger">
                            Hesabı Kalıcı Olarak Sil
                        </a>
                    </div>
                    
                </div>
                
                <div class="form-footer">
                    <button type="submit" class="submit-btn">Değişiklikleri Kaydet</button>
                    <a href="<?= profile_url($profile_user['username']) ?>" class="back-link">İptal</a>
                </div>
            </div>
        </form>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>