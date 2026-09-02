<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_bad_words');

include __DIR__ . '/_header.php';

$approved_words = get_approved_words();
$csrf_token = generate_csrf_token();
?>

<?php include __DIR__ . '/_nav.php'; ?>

        <h1 class="page-title">✅ Onaylanmış Kelimeler (Beyaz Liste)</h1>

        <!-- Info Box -->
        <section class="info-panel mb-30">
            <h3 class="info-title">ℹ️ Beyaz Liste Nedir?</h3>
            <p style="margin:0; font-size:14px; color:#444;">Bu listedeki kelimeler yasaklı kelimelere benzese bile otomatik olarak onaylanır ve inceleme gerektirmez.</p>
        </section>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="flash mb-30">
                <?= htmlspecialchars($_SESSION['flash']) ?>
            </div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <!-- Word List -->
        <section>
            <h2 style="font-size: 18px; margin-bottom: 15px;">Beyaz Listedeki Kelimeler (<?= count($approved_words) ?>)</h2>

            <?php if (empty($approved_words)): ?>
                <div class="empty-state">Henüz onaylanmış kelime yok.</div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Kelime</th>
                                <th>Onaylayan</th>
                                <th>Tarih</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($approved_words as $word): ?>
                            <tr>
                                <td><strong style="font-family: monospace;"><?= htmlspecialchars($word['word']) ?></strong></td>
                                <td>@<?= htmlspecialchars($word['approved_by_name'] ?? 'Unknown') ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($word['approved_at'])) ?></td>
                                <td>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_approved_word.php" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/approved_words.php">
                                        <input type="hidden" name="word_id" value="<?= $word['id'] ?>">
                                        <button type="submit" class="btn btn-danger">Çıkar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

<?php include __DIR__ . '/_footer.php'; ?>
