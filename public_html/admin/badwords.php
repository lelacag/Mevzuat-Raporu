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
if (!is_admin() && !admin_has_perm(null, 'manage_bad_words')) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$bad_words = get_bad_words();

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
        <h1 class="page-title">Kötü Kelime Yönetimi</h1>

        <!-- Add New Word -->
        <section class="mb-30">
            <h2 class="section-title">Yeni Kelime Ekle</h2>
            <form method="POST" action="<?= BASE_PATH ?>/api/admin_add_bad_word.php" class="form-flex">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badwords.php">
                <input type="text" name="word" placeholder="Yasaklı kelime..." required class="flex-1 input">
                <button type="submit" class="btn btn-primary">Ekle</button>
            </form>
            
            <h3 class="section-subtitle">Veya Dosya Yükle (TXT/CSV)</h3>
            <form method="POST" action="<?= BASE_PATH ?>/api/admin_upload_bad_words.php" enctype="multipart/form-data" class="form-flex">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badwords.php">
                <input type="file" name="file" accept=".txt,.csv" required class="flex-1 input">
                <button type="submit" class="btn btn-primary btn-upload">Yükle</button>
            </form>
            <p class="muted mt-10">Her satırda bir kelime olacak şekilde TXT veya virgülle ayrılmış CSV dosyası yükleyebilirsiniz.</p>
        </section>

        <!-- Bad Words List -->
        <section>
            <h2 style="font-size: 18px; margin-bottom: 15px;">Yasaklı Kelimeler (<?= count($bad_words) ?>)</h2>
            
            <?php if (empty($bad_words)): ?>
                <div class="empty-state">Henüz yasaklı kelime eklenmemiş.</div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kelime</th>
                            <th>Eklenme Tarihi</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bad_words as $bw): ?>
                            <tr>
                                <td><?= $bw['id'] ?></td>
                                <td><strong><?= htmlspecialchars($bw['word']) ?></strong></td>
                                <td><?= date('d.m.Y H:i', strtotime($bw['created_at'])) ?></td>
                                <td>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_bad_word.php" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badwords.php">
                                        <input type="hidden" name="word_id" value="<?= $bw['id'] ?>">
                                        <button type="submit" class="btn btn-danger">Sil</button>
                                    </form>
                                </td> 
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Info Box -->
        <section class="info-panel">
            <h3 class="info-title">💡 Bilgi</h3>
            <ul class="info-list">
                <li>Yasaklı kelimeler tüm gönderilerde ve yanıtlarda otomatik olarak kontrol edilir.</li>
                <li>Büyük/küçük harf duyarlılığı yoktur (örn: "küfür" ve "KÜFÜR" aynı şekilde tespit edilir).</li>
                <li>Kelime herhangi bir metin içinde geçerse engellenir (örn: "test" kelimesi "testmessage" içinde bulunur).</li>
                <li>Kullanıcı yasaklı kelime içeren gönderi yapmaya çalışırsa "<?= t('post_badwords_error') ?>" hatası alır.</li>
            </ul>
        </section>

<?php include __DIR__ . '/_footer.php'; ?>
