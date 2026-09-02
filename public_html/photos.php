<?php
/**
 * photos.php — Tiled photo gallery for a user.
 *
 * Usage: /fotograf/{username}  or  /photos.php?username=xxx
 */

require_once __DIR__ . '/includes/header.php';

// Allow /fotograf/{username} via dev-router / clean-URL rewrite, falling back to ?username=
$username = $_GET['username'] ?? '';
$username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);

if (!$username) {
    http_response_code(404);
    exit('Not Found');
}

$pdo = db_connect();

// Fetch profile user
$pstmt = $pdo->prepare(
    "SELECT id, username, slug FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1"
);
$pstmt->execute([$username]);
$profile_user = $pstmt->fetch(PDO::FETCH_ASSOC);

if (!$profile_user) {
    http_response_code(404);
    exit('Kullanıcı bulunamadı.');
}

$profile_uid = (int)$profile_user['id'];
$is_own      = $current_user_id && (int)$current_user_id === $profile_uid;
$is_admin_usr = function_exists('is_admin') ? is_admin() : false;

// Premium / limit info for own gallery
$is_premium   = $is_own && function_exists('is_user_premium') ? is_user_premium($current_user_id) : false;

// Fetch photos (most recent first, soft-deleted excluded)
$photos_stmt = $pdo->prepare("
    SELECT id, filename, publish_date, tags, uploaded_at
    FROM user_images
    WHERE user_id = ? AND deleted_at IS NULL
    ORDER BY uploaded_at DESC
");
$photos_stmt->execute([$profile_uid]);
$photos = $photos_stmt->fetchAll(PDO::FETCH_ASSOC);

$photo_count = count($photos);
$at_limit    = $is_own && !$is_premium && !$is_admin_usr && $photo_count >= 10;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars('@' . $profile_user['username']) ?> — Fotoğraflar — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/photos.css">
</head>
<body>

<div class="page-container">
    <div class="gallery-container">

        <div class="gallery-header">
            <div class="gallery-header-left">
                <a href="<?= function_exists('profile_url') ? profile_url($profile_user['username']) : BASE_PATH . '/profil/' . rawurlencode($profile_user['username']) ?>" class="back-link">
                    ← @<?= htmlspecialchars($profile_user['username']) ?>
                </a>
                <h1 class="gallery-title">Fotoğraflar</h1>
                <span class="gallery-count"><?= $photo_count ?> fotoğraf</span>
            </div>

            <?php if ($is_own): ?>
                <div class="gallery-header-right">
                    <?php if ($at_limit): ?>
                        <span class="btn-small muted" title="10 fotoğraf limitine ulaştınız">📷 Yükle</span>
                    <?php else: ?>
                        <a href="<?= BASE_PATH ?>/fotograf-yukle?context=profile" class="btn-small">📷 Yeni Fotoğraf</a>
                    <?php endif; ?>
                    <?php if (!$is_premium && !$is_admin_usr): ?>
                        <span class="photo-limit-indicator"><?= $photo_count ?> / 10</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($at_limit): ?>
            <div class="photo-limit-notice">
                Ücretsiz hesap limitine ulaştınız (10/10).
                <a href="<?= BASE_PATH ?>/premium">Premium</a> ile sınırsız fotoğraf yükleyin.
            </div>
        <?php endif; ?>

        <?php if (empty($photos)): ?>
            <div class="gallery-empty">
                <p><?= $is_own ? 'Henüz hiç fotoğraf yüklemediniz.' : '@' . htmlspecialchars($profile_user['username']) . ' henüz fotoğraf paylaşmamış.' ?></p>
                <?php if ($is_own): ?>
                    <a href="<?= BASE_PATH ?>/fotograf-yukle?context=profile" class="btn-post">İlk fotoğrafını yükle</a>
                <?php endif; ?>
            </div>
        <?php else: ?>

        <div class="gallery-grid">
            <?php foreach ($photos as $photo): ?>
                <a href="<?= BASE_PATH ?>/foto/<?= (int)$photo['id'] ?>" class="gallery-tile">
                    <img src="<?= BASE_PATH ?>/photo_img.php?id=<?= (int)$photo['id'] ?>"
                         alt="Fotoğraf"
                         class="gallery-tile-img"
                         loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
