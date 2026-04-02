<?php /* EN + TR comments used. */
/**
 * Create Group Page
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();

if (!$user_id) {
    $_SESSION['flash_error'] = 'Topluluk oluşturmak için giriş yapmalısınız';
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Geçersiz istek';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // Validation
        if (empty($name)) {
            $errors[] = 'Grup adı gereklidir';
        } elseif (mb_strlen($name) < 3) {
            $errors[] = 'Grup adı en az 3 karakter olmalıdır';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Grup adı en fazla 100 karakter olabilir';
        }
        
        if (empty($description)) {
            $errors[] = 'Grup açıklaması gereklidir';
        } elseif (mb_strlen($description) > 500) {
            $errors[] = 'Grup açıklaması en fazla 500 karakter olabilir';
        }
        
        // Generate slug
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
        $slug = trim($slug, '-');
        
        // Check if slug already exists
        if (empty($errors)) {
            $stmt = query("SELECT id FROM groups_table WHERE slug = ?", [$slug]);
            if ($stmt->fetch()) {
                $errors[] = 'Bu isimde bir grup zaten mevcut';
            }
        }
        
        // Censor bad words
        if (empty($errors)) {
            $censored_name = censor_bad_words($name);
            $censored_desc = censor_bad_words($description);
            $name = $censored_name['clean'];
            $description = $censored_desc['clean'];
        }
        
        // Create group
        if (empty($errors)) {
            try {
                // Insert group
                $stmt = query("INSERT INTO groups_table (name, slug, description, created_by, created_at) VALUES (?, ?, ?, ?, NOW())", 
                              [$name, $slug, $description, $user_id]);
                
                // Get the new group ID
                global $pdo;
                $group_id = $pdo->lastInsertId();
                
                // Add creator as admin member
                query("INSERT INTO group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'admin', NOW())", 
                      [$group_id, $user_id]);
                
                $_SESSION['flash'] = 'Grup başarıyla oluşturuldu!';
                header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($slug));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Topluluk oluşturulurken bir hata oluştu';
            }
        }
    }
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/groups.php">← Gruplara Dön</a></li>
                <li><a href="<?= BASE_PATH ?>/index.php">Ana Sayfa</a></li>
            </ul>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">Bilgi</div>
            <div class="sidebar-note padded">
                <p class="mb-8">🎉 Kendi grubunuzu oluşturun!</p>
                <p class="mb-8">👥 Otomatik olarak yönetici olursunuz</p>
                <p>📝 Grup açıklaması net olsun</p>
            </div>
        </div>
    </aside>

    <main class="content-area">
        <h1 class="section-title">+ Yeni Topluluk Oluştur</h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="post-form-container">
            <form method="POST" class="post-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="form-row">
                    <label class="form-label">Grup Adı *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="Örn: Yazılımcılar Kulübü" required maxlength="100" class="input-full">
                    <small class="muted small">3-100 karakter arası</small>
                </div>
                
                <div class="form-row">
                    <label class="form-label">Açıklama *</label>
                    <textarea name="description" placeholder="Grubunuz hakkında kısa bir açıklama yazın..." required maxlength="500" rows="4" class="input-full textarea-large"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <small class="muted small">Maksimum 500 karakter</small>
                </div>
                
                <div class="post-form-actions">
                    <a href="<?= BASE_PATH ?>/groups.php" class="btn-cancel">İptal</a>
                    <button type="submit" class="btn-post">🎉 Topluluk Oluştur</button>
                </div>
            </form>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">📌 Kurallar</div>
            <div class="sidebar-note padded">
                <ul style="margin:0;padding-left:20px;line-height:1.8;">
                    <li>Uygunsuz isimler yasaktır</li>
                    <li>Spam gruplar silinir</li>
                    <li>Grup yöneticisi sorumludur</li>
                    <li>Kötü kelimeler sansürlenir</li>
                </ul>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
