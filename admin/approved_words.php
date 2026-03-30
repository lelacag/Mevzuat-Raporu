<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_bad_words');

include __DIR__ . '/_header.php';

$approved_words = get_approved_words();
$csrf_token = generate_csrf_token();
?>

<?php include __DIR__ . '/_nav.php'; ?>

<!-- content starts -->
        <h1 style="font-size: 24px; margin-bottom: 20px;">✅ Onaylanmış Kelimeler (Beyaz Liste)</h1>
        
        <div style="background: #d4edda; border: 1px solid #28a745; padding: 12px; margin-bottom: 20px; border-radius: 4px;">
            <strong>ℹ️ Beyaz Liste Nedir?</strong><br>
            <small>Bu listedeki kelimeler yasaklı kelimelere benzese bile otomatik olarak onaylanır ve inceleme gerektirmez.</small>
        </div>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="flash" style="margin-bottom: 20px;">
                <?= htmlspecialchars($_SESSION['flash']) ?>
            </div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <?php if (empty($approved_words)): ?>
            <div style="text-align: center; padding: 40px 20px; background: #f8f9fa; border-radius: 8px;">
                <p style="color: #666;">Henüz onaylanmış kelime yok.</p>
            </div>
        <?php else: ?>
            <div style="margin-bottom: 15px;">
                <strong><?= count($approved_words) ?></strong> kelime beyaz listede.
            </div>

            <div class="admin-table-wrapper">
                <table class="table table-full">
                    <thead>
                        <tr class="table-headers">
                            <th>Kelime</th>
                            <th>Onaylayan</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead> 
                    <tbody>
                    <?php foreach ($approved_words as $word): ?>
                        <tr style="border-bottom: 1px solid #dee2e6;">
                            <td style="padding: 12px; font-family: monospace; font-size: 14px; font-weight: bold;">
                                <?= htmlspecialchars($word['word']) ?>
                            </td>
                            <td style="padding: 12px;">
                                @<?= htmlspecialchars($word['approved_by_name'] ?? 'Unknown') ?>
                            </td>
                            <td style="padding: 12px; color: #666; font-size: 13px;">
                                <?= date('d M Y H:i', strtotime($word['approved_at'])) ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_approved_word.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/approved_words.php">
                                    <input type="hidden" name="word_id" value="<?= $word['id'] ?>">
                                    <button type="submit" 
                                            style="background: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        Çıkar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
