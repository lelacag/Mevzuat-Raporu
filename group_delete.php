<?php
/**
 * Delete Group - Confirmation page
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$group_id = (int)($_GET['id'] ?? 0);

if (!$group_id) {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Get group details
$stmt = $pdo->prepare("SELECT * FROM groups_table WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();

if (!$group) {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Check if user is admin of this group
$stmt = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group_id, $user_id]);
$member = $stmt->fetch();

if (!$member || $member['role'] !== 'admin') {
    $_SESSION['flash'] = 'Bu grubu silme yetkiniz yok.';
    header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($group['slug']));
    exit;
}

// Handle delete confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    require_csrf();
    
    try {
        $pdo->beginTransaction();
        
        // Delete all group post likes
        $stmt = $pdo->prepare("DELETE gpl FROM group_post_likes gpl 
            INNER JOIN group_posts gp ON gpl.post_id = gp.id 
            WHERE gp.group_id = ?");
        $stmt->execute([$group_id]);
        
        // Delete all group post comments
        $stmt = $pdo->prepare("DELETE gpc FROM group_post_comments gpc 
            INNER JOIN group_posts gp ON gpc.post_id = gp.id 
            WHERE gp.group_id = ?");
        $stmt->execute([$group_id]);
        
        // Delete all group posts
        $stmt = $pdo->prepare("DELETE FROM group_posts WHERE group_id = ?");
        $stmt->execute([$group_id]);
        
        // Delete all group members
        $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ?");
        $stmt->execute([$group_id]);
        
        // Delete the group
        $stmt = $pdo->prepare("DELETE FROM groups_table WHERE id = ?");
        $stmt->execute([$group_id]);
        
        $pdo->commit();
        
        $_SESSION['flash'] = 'Grup başarıyla silindi.';
        header('Location: ' . BASE_PATH . '/groups.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Group delete error: " . $e->getMessage());
        $_SESSION['flash'] = 'Grup silinirken bir hata oluştu.';
        header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($group['slug']));
        exit;
    }
}
?>

<div class="main-container single-column">
    <main class="content-area form-centered">
        <div class="section-title">Grubu Sil</div>
        
        <div style="padding:20px;background:#fff;">
            <div style="text-align:center;margin-bottom:20px;">
                <div style="width:60px;height:60px;background:#e74c3c;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 15px;">
                    ⚠
                </div>
                <h2 style="font-size:18px;color:#333;margin-bottom:10px;">
                    "<?= htmlspecialchars($group['name']) ?>" grubunu silmek istediğinize emin misiniz?
                </h2>
                <p style="font-size:13px;color:#666;line-height:1.5;">
                    Bu işlem geri alınamaz. Gruptaki tüm gönderiler, yorumlar ve üyelikler kalıcı olarak silinecektir.
                </p>
            </div>
            
            <form method="POST" style="display:flex;gap:10px;justify-content:center;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($group['slug']) ?>" 
                   style="padding:10px 25px;background:#ddd;color:#333;text-decoration:none;border-radius:3px;font-size:13px;">
                    Vazgeç
                </a>
                <button type="submit" name="confirm_delete" value="1" 
                        style="padding:10px 25px;background:#e74c3c;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:13px;">
                    Evet, Grubu Sil
                </button>
            </form>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
