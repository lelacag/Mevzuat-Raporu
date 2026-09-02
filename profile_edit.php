<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

$profile_user = get_user($current_user_id);
if (!$profile_user) {
    header('Location: ' . home_url());
    exit;
}

$errors = [];

$preview_badge_text = null;
$preview_badge_color = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    // Regenerate event code (premium users only)
    if (isset($_POST['regenerate_event_code']) && is_user_premium($current_user_id)) {
        try {
            $new = regenerate_event_code($current_user_id);
            $_SESSION['flash'] = 'Etkinlik kodunuz yenilendi: ' . htmlspecialchars($new);
        } catch (Exception $e) {
            $_SESSION['flash'] = 'Kod yenilenemedi: ' . $e->getMessage();
        }
        header('Location: ' . BASE_PATH . '/profil/duzenle');
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
            $bio = trim($bio);
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

// Preserve form values across POST round-trips (e.g. preview_badge POST should not wipe edits)
$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$display_bio = $is_post ? ($_POST['bio'] ?? '') : html_entity_decode($profile_user['bio'] ?? '', ENT_QUOTES, 'UTF-8');
$display_notify_email  = $is_post ? isset($_POST['notify_by_email'])   : !empty($profile_user['notify_by_email']);
$display_notify_mention = $is_post ? isset($_POST['notify_on_mention']) : !empty($profile_user['notify_on_mention']);
$display_notify_reply  = $is_post ? isset($_POST['notify_on_reply'])   : !empty($profile_user['notify_on_reply']);
$display_notify_report = $is_post ? isset($_POST['notify_on_report'])  : !empty($profile_user['notify_on_report']);
$display_notify_system = $is_post ? isset($_POST['notify_on_system'])  : !empty($profile_user['notify_on_system']);
$display_badge_text    = $is_post ? trim($_POST['custom_badge'] ?? '') : ($current_badge['badge_text'] ?? '');
$display_badge_color   = $is_post ? ($_POST['badge_color'] ?? ($current_badge['badge_color'] ?? '#2ecc71')) : ($current_badge['badge_color'] ?? '#2ecc71');

require_once __DIR__ . '/includes/header.php';
?>
<div class="main-container single-column">
    <main class="content-area form-centered">

        <div class="settings-page-header">
            <a href="<?= profile_url($profile_user['username']) ?>" class="back-link">← Profilime Dön</a>
            <h1 class="section-title">Profili Düzenle</h1>
        </div>

        <?php if (!empty($errors)): ?>
            <ul class="errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <!-- ── Biyografi ── -->
            <section class="settings-card">
                <h2 class="settings-card-title">👤 Biyografi</h2>
                <div class="form-row">
                    <label class="form-label">Kendinizi kısaca tanıtın <span class="muted">(maks. 500 karakter)</span></label>
                    <textarea name="bio" class="textarea-large" maxlength="500" placeholder="Kendinizi tanıtın..."><?= htmlspecialchars($display_bio) ?></textarea>
                </div>
            </section>

            <?php if (is_user_premium($current_user_id)): ?>

            <!-- ── Özel Rozet ── -->
            <section class="settings-card premium-card">
                <h2 class="settings-card-title">⭐ Özel Rozet</h2>

                <div class="form-row">
                    <label class="form-label">Rozet Metni <span class="muted">(maks. 20 karakter)</span></label>
                    <input type="text" name="custom_badge" maxlength="20"
                           value="<?= htmlspecialchars($display_badge_text) ?>"
                           placeholder="Örn: Kod Sihirbazı"
                           class="form-control input-full">
                </div>

                <div class="form-row mt-15">
                    <label class="form-label">Rozet Rengi</label>
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
                            '#e67e22' => 'Portakal',
                        ];
                        foreach ($colors as $value => $label):
                            $hex = ltrim($value, '#');
                            $is_selected = ($display_badge_color === $value);
                        ?>
                            <label class="color-option color-<?= $hex ?><?= $is_selected ? ' selected' : '' ?>">
                                <input type="radio" name="badge_color" value="<?= $value ?>"
                                       <?= $is_selected ? 'checked' : '' ?> class="radio-small">
                                <span class="swatch color-<?= $hex ?>"></span>
                                <span class="swatch-label"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint muted small mt-8">💡 Yukarıdaki renklerden birini seçin</p>
                </div>

                <?php if ($display_badge_text !== ''): ?>
                <div class="badge-preview-row">
                    <span class="badge-preview-label">Önizleme:</span>
                    <span class="badge-pill color-<?= ltrim($display_badge_color, '#') ?>">
                        <?= htmlspecialchars($display_badge_text) ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="form-actions mt-15">
                    <button type="submit" name="preview_badge" value="1" class="btn-outline"
                            <?= (trim($display_badge_text) === '') ? 'disabled' : '' ?>>Önizleme</button>
                    <span class="hint muted small">Kaydetmeden önce rozeti önizleyin.</span>
                </div>
            </section>

            <!-- ── Etkinlik Kodu ── -->
            <section class="settings-card">
                <h2 class="settings-card-title">🎟️ Etkinlik Kodu</h2>
                <div class="event-code-row">
                    <code class="event-code-display">
                        <?= htmlspecialchars($profile_user['event_code'] ?? get_or_create_event_code($current_user_id)) ?>
                    </code>
                    <button type="submit" name="regenerate_event_code" value="1" class="btn-outline small">Kodu Yenile</button>
                </div>
                <p class="hint muted small mt-8">Bu kodu etkinliklerde kullanıcı adınızla birlikte temsil edebilirsiniz. İsterseniz yenileyebilirsiniz.</p>
            </section>

            <?php endif; ?>

            <!-- ── E-posta Bildirimleri ── -->
            <section class="settings-card">
                <h2 class="settings-card-title">📧 E-posta Bildirimleri</h2>
                <div class="checkbox-list">
                    <label class="checkbox-row">
                        <input type="checkbox" name="notify_by_email" <?= $display_notify_email ? 'checked' : '' ?>>
                        <span>E-posta ile bildirim al</span>
                    </label>
                    <div class="checkbox-sublist">
                        <label class="checkbox-row">
                            <input type="checkbox" name="notify_on_mention" <?= $display_notify_mention ? 'checked' : '' ?>>
                            <span>Bahsedildiğimde</span>
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="notify_on_reply" <?= $display_notify_reply ? 'checked' : '' ?>>
                            <span>Gönderilerime yanıt geldiğinde</span>
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="notify_on_report" <?= $display_notify_report ? 'checked' : '' ?>>
                            <span>Gönderilerim rapor edildiğinde</span>
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="notify_on_system" <?= $display_notify_system ? 'checked' : '' ?>>
                            <span>Sistem mesajları (ban / aktivasyon)</span>
                        </label>
                    </div>
                </div>
            </section>

            <!-- ── Kaydet ── -->
            <div class="form-footer">
                <button type="submit" class="submit-btn">Değişiklikleri Kaydet</button>
                <a href="<?= profile_url($profile_user['username']) ?>" class="back-link">İptal</a>
            </div>

        </form>

        <!-- ── Veri İndir ── -->
        <section class="settings-card mt-20">
            <h2 class="settings-card-title">📦 Veri İndir</h2>
            <p class="muted">Hesabınıza ait profil bilgileriniz, gönderileriniz ve ayarlarınız dahil tüm verilerinizi indirin. Bu işlem hesabınızı silmez.</p>
            <a href="<?= BASE_PATH ?>/download_user_data.php" class="btn btn-primary mt-12">Verilerimi İndir</a>
            <?php if (!empty($_SESSION['user_data_exported_at'])): ?>
                <p class="hint muted small mt-8">Son dışa aktarım: <?= htmlspecialchars($_SESSION['user_data_exported_at']) ?></p>
            <?php endif; ?>
        </section>

        <!-- ── Güvenlik ── (separate, no submit inside main form) -->
        <section class="settings-card mt-20">
            <h2 class="settings-card-title">🔒 Güvenlik</h2>
            <p class="muted">Şifrenizi değiştirmek için aşağıdaki butona tıklayın.</p>
            <a href="<?= BASE_PATH ?>/profil/sifre-degistir" class="btn btn-post">Şifreyi Değiştir</a>
        </section>

        <?php if (defined('SMS_MODULE_ENABLED') && SMS_MODULE_ENABLED): ?>
        <!-- ── SMS ── -->
        <section class="settings-card mt-20">
            <h2 class="settings-card-title">📱 SMS ile Gönderi Paylaşma</h2>
            <?php if (!empty($profile_user['phone_verified'])): ?>
                <p class="muted">✓ <strong>Telefon Doğrulandı:</strong> <?= htmlspecialchars($profile_user['phone_number']) ?></p>
                <p class="hint muted small">Artık <strong><?= defined('SMS_NUMBER') && SMS_NUMBER ? SMS_NUMBER : 'belirtilen numaraya' ?></strong> SMS göndererek paylaşım yapabilirsiniz.</p>
            <?php else: ?>
                <p class="muted">SMS ile gönderi paylaşmak için telefon numaranızı doğrulayın.</p>
                <a href="<?= BASE_PATH ?>/modules/sms/verify_phone.php" class="btn btn-primary mt-5">Telefon Numaranızı Doğrulayın</a>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ── Tehlikeli Bölge ── (own forms, outside main form) -->
        <section class="settings-card danger-card mt-20">
            <h2 class="settings-card-title">⚠️ Tehlikeli Bölge</h2>

            <div class="danger-section">
                <h4 class="danger-heading">Hesabı Devre Dışı Bırak</h4>
                <p class="muted">Hesabınız geçici olarak devre dışı bırakılır. Profiliniz gizlenir ve giriş yapamazsınız. Yeniden etkinleştirmek için tekrar giriş yapmanız yeterlidir.</p>
                <form method="POST" action="<?= BASE_PATH ?>/api/disable_account.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn-warning">Hesabı Devre Dışı Bırak</button>
                </form>
            </div>

            <hr class="danger-divider">

            <div>
                <h4 class="danger-heading">Hesabı Kalıcı Olarak Sil</h4>
                <p class="muted"><strong>Dikkat:</strong> Bu işlem geri alınamaz! Hesabınız ve tüm verileriniz kalıcı olarak silinecektir.</p>
                <a href="<?= BASE_PATH ?>/delete_account_confirm.php" class="btn btn-danger">Hesabı Kalıcı Olarak Sil</a>
            </div>
        </section>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>