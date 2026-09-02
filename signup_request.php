<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/captcha.php';

$ip = get_client_ip();
$country = get_country_by_ip($ip);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF optional (support cookie-less flow) - if present verify
    if (!empty($_POST['csrf_token']) && !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Geçersiz istek (CSRF).';
    }

    // CAPTCHA verify (defense against mass automated requests)
    $captcha_ok = false;
    try {
        $captcha_input = get_captcha_input_from_post();
        $captcha_token = $_POST['captcha_token'] ?? '';
        $vc = verify_captcha($captcha_input, $captcha_token);
        if (!empty($vc['valid'])) $captcha_ok = true;
        else $errors[] = 'CAPTCHA doğrulaması başarısız.';
    } catch (Exception $e) {
        error_log('[CAPTCHA] signup_request verify error: ' . $e->getMessage());
        $errors[] = 'CAPTCHA doğrulama hatası.';
    }

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Geçerli bir e-posta girin.';

    if (empty($errors) && $captcha_ok) {
        $res = create_signup_request($email, $ip, $country, $_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($res['success']) {
            $success = true;
        } else {
            if ($res['error'] === 'rate_limit_ip') $errors[] = 'Bu IP için çok fazla istek var. Lütfen sonra tekrar deneyin.';
            elseif ($res['error'] === 'already_requested') $errors[] = 'Bu e-posta ile zaten bir talep gönderilmiş.';
            else $errors[] = 'İstek oluşturulamadı. Lütfen daha sonra tekrar deneyin.';
        }
    }
}
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">Kayıt Talebi</h1>

        <?php if ($success): ?>
            <div class="form-alert form-alert-success">Talebiniz alındı. Lütfen e-posta adresinizi kontrol edip doğrulama bağlantısına tıklayın.</div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="form-alert form-alert-error">
                    <?php foreach ($errors as $e): ?>
                        ✗ <?= htmlspecialchars($e) ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p>Şu anda yalnızca Türkiye'den doğrudan kayıt açılmıştır. Eğer Türkiye dışında iseniz, buradan e-posta ile kayıt talebi oluşturabilirsiniz. Bulunduğunuz ülke: <strong><?= htmlspecialchars($country) ?></strong></p>

            <form method="POST">
                <?php if (session_status() === PHP_SESSION_ACTIVE): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" required class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <?php render_captcha(); ?>

                <div style="margin-top:12px;">
                    <button class="btn btn-approve" type="submit">Talep Gönder</button>
                    <a class="btn btn-cancel" href="<?= BASE_PATH ?>">Geri</a>
                </div>
            </form>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>