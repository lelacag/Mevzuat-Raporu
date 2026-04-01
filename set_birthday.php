<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_user_id = get_current_user_id();

if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$user = get_user($current_user_id);
$errors = [];
$success = false;

// If user already has birthday, redirect to index
if (!empty($user['birthday'])) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $birthday = trim($_POST['birthday'] ?? '');
    
    if (empty($birthday)) {
        $errors[] = 'Doğum tarihinizi girmeniz zorunludur.';
    } else {
        $birth_date = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        
        if ($age < 16) {
            // Save birthday even if underage, then redirect to underage page
            try {
                query("UPDATE users SET birthday = ? WHERE id = ?", [$birthday, $current_user_id]);
                // Verify save
                $updated_user = get_user($current_user_id);
                error_log("Birthday saved for user {$current_user_id}: " . $updated_user['birthday']);
            } catch (Exception $e) {
                error_log("Failed to save birthday: " . $e->getMessage());
                $errors[] = 'Doğum tarihi kaydedilemedi. Lütfen tekrar deneyin.';
            }
            if (empty($errors)) {
                header('Location: ' . BASE_PATH . '/underage.php');
                exit;
            }
        } else {
            // Save birthday
            try {
                query("UPDATE users SET birthday = ? WHERE id = ?", [$birthday, $current_user_id]);
                // Verify save
                $updated_user = get_user($current_user_id);
                error_log("Birthday saved for user {$current_user_id}: " . $updated_user['birthday']);
                $success = true;
                $_SESSION['flash'] = 'Doğum tarihiniz kaydedildi!';
                header('Location: ' . BASE_PATH . '/index.php');
                exit;
            } catch (Exception $e) {
                error_log("Failed to save birthday: " . $e->getMessage());
                $errors[] = 'Doğum tarihi kaydedilemedi. Lütfen tekrar deneyin.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doğum Tarihi - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/birthday.css">
</head>
<body>
<div class="birthday-container">
    <div class="birthday-icon">🎂</div>
    <h1 class="birthday-title">Doğum Tarihinizi Girin</h1>
    
    <div class="birthday-description">
        Siteyi kullanmaya devam etmek için doğum tarihinizi belirtmeniz gerekmektedir.
        <strong>⚠️ 16 yaşından küçükseniz siteyi kullanamazsınız.</strong>
    </div>

    <?php if (!empty($errors)): ?>
        <ul class="errors">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="birthday-form-group">
            <label for="birthday">📅 Doğum Tarihiniz</label>
            <input 
                type="date" 
                id="birthday" 
                name="birthday" 
                required
                max="<?= date('Y-m-d', strtotime('-16 years')) ?>"
                value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>"
            >
            <small>En az 16 yaşında olmalısınız (<?= date('d.m.Y', strtotime('-16 years')) ?> veya daha önce doğmuş olmalısınız)</small>
        </div>

        <button type="submit" class="birthday-submit">✓ Devam Et</button>
    </form>

    <div class="birthday-footer">
        <a href="<?= BASE_PATH ?>/logout.php">← Çıkış Yap</a>
    </div>
</div>
</body>
</html>
