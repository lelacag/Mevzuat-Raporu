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
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG: get_user(", var_export($current_user_id, true), ") returned false.\n";
    echo "Session: ", var_export($_SESSION, true), "\n";
    echo "Cookies: ", var_export($_COOKIE, true), "\n";
    // Uncomment the next line to redirect after debugging:
    // header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($new_password_confirm)) {
        $errors[] = 'Lütfen tüm şifre alanlarını doldurun.';
    } elseif (!password_verify($current_password, $profile_user['password_hash'])) {
        $errors[] = 'Mevcut şifre yanlış.';
    } elseif ($new_password !== $new_password_confirm) {
        $errors[] = 'Yeni şifre ile onayı eşleşmiyor.';
    } elseif (strlen($new_password) < 8) {
        $errors[] = 'Yeni şifre en az 8 karakter olmalıdır.';
    } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $errors[] = 'Yeni şifre en az bir büyük harf, bir küçük harf ve bir rakam içermelidir.';
    } elseif (password_verify($new_password, $profile_user['password_hash'])) {
        $errors[] = 'Yeni şifre önceki şifre ile aynı olmamalıdır.';
    }

    if (empty($errors)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        query('UPDATE users SET password_hash = ? WHERE id = ?', [$new_hash, $current_user_id]);
        $_SESSION['flash'] = 'Şifreniz başarıyla değiştirildi.';
        header('Location: ' . profile_url($profile_user['username']));
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="main-container single-column">
    <main class="content-area form-centered" style="max-width:600px; min-width:320px; margin: 40px auto 50px auto; box-shadow:0 2px 16px 0 rgba(0,0,0,0.07); border-radius:12px; background:#fff; padding:36px 28px 32px 28px;">
        <h1 class="section-title" style="text-align:center;">Şifre Değiştir</h1>

        <?php if (!empty($errors)): ?>
            <ul class="errors">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" class="entry-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-row">
                <label class="form-label">Mevcut Şifre</label>
                <input type="password" name="current_password" class="form-control input-full" required>
            </div>
            <div class="form-row">
                <label class="form-label">Yeni Şifre</label>
                <input type="password" name="new_password" class="form-control input-full" required>
            </div>
            <div class="form-row">
                <label class="form-label">Yeni Şifre (Tekrar)</label>
                <input type="password" name="new_password_confirm" class="form-control input-full" required>
            </div>
            <div class="form-row">
                <button type="submit" class="btn btn-post">Şifreyi Güncelle</button>
                <a href="<?= profile_url($profile_user['username']) ?>" class="btn btn-outline">İptal</a>
            </div>
        </form>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
