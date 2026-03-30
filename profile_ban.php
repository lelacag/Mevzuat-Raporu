<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$me = get_user($current_user_id);
if (!$me || $me['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$username = $_GET['username'] ?? '';
$profile_user = $username ? get_user_by_username($username) : null;
if (!$profile_user) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target_id = intval($_POST['user_id'] ?? 0);
    if ($action === 'ban' && $target_id) {
        $days = intval($_POST['days'] ?? 0);
        if ($days <= 0) {
            $errors[] = 'Lütfen geçerli bir süre seçin.';
        } else {
            admin_suspend_user($current_user_id, $target_id, $days);
            header('Location: ' . profile_url($profile_user['username']));
            exit;
        }
    } elseif ($action === 'delete' && $target_id) {
        admin_delete_user($current_user_id, $target_id);
        header('Location: ' . BASE_PATH . '/admin/users.php');
        exit;
    }
}

// Render a small form to pick duration
?>
<div class="main-container">
    <div class="content-wrapper">
        <h1 class="section-title">Kullanıcıyı Banla: @<?= htmlspecialchars($profile_user['username']) ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <?php foreach ($errors as $e): ?>
                    <div class="error-item"><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="user_id" value="<?= $profile_user['id'] ?>">
            <div class="form-row">
                <label>
                    <input type="radio" name="days" value="7" checked> 1 Hafta
                </label>
            </div>
            <div class="form-row">
                <label>
                    <input type="radio" name="days" value="30"> 1 Ay
                </label>
            </div>
            <div class="form-row">
                <label>
                    <input type="radio" name="days" value="90"> 3 Ay
                </label>
            </div>
            <div class="form-row">
                <label>
                    <input type="radio" name="days" value="365"> 1 Yil
                </label>
            </div>

            <div class="form-row">
                <button type="submit" name="action" value="ban" class="btn btn-warning"><?= t('profile_ban_btn') ?></button>
                <button type="submit" name="action" value="delete" class="btn btn-danger">Kullanıcıyı Sil</button>
                <a href="<?= profile_url($profile_user['username']) ?>" class="btn">Iptal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>