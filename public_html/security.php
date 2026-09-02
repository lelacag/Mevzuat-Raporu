<?php
require_once __DIR__ . '/includes/header.php';
?>
<style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
.sec-content{padding:20px;line-height:1.7;font-size:13px}
.sec-intro{color:#555;margin-bottom:28px;font-size:14px}
.sec-block{border-left:3px solid #4a90d9;padding:14px 16px;margin-bottom:22px;background:#f7fafd;border-radius:0 4px 4px 0}
.sec-block h3{margin:0 0 8px;font-size:15px;color:#1a3a5c}
.sec-block p{margin:0 0 8px}
.sec-block p:last-child{margin-bottom:0}
.sec-block--green{border-left-color:#27ae60;background:#f4fdf6}
.sec-block--green h3{color:#1a4a2c}
.sec-list{margin:0 0 0 18px;padding:0;line-height:2}
.sec-list-tight{margin:8px 0 8px 18px;padding:0}
.sec-table{border-collapse:collapse;width:100%;font-size:12px}
.sec-table th{text-align:left;padding:6px 10px;border:1px solid #c8dff0}
.sec-table td{padding:6px 10px;border:1px solid #c8dff0}
.sec-table thead tr{background:#ddeeff}
.sec-row-alt{background:#f0f7ff}
.sec-block-footer{margin-top:10px;margin-bottom:0}
.sec-footer-note{margin-top:28px;font-size:12px;color:#888}
.sec-back-link{margin-top:16px}
</style>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">🔒 Güvenlik Merkezi</h1>

        <div class="sec-content">

            <p class="sec-intro">
                Verileriniz ve gizliliğiniz bizim için önceliktir. Platformumuzu nasıl güvenli kıldığımızı şeffaf bir şekilde açıklıyoruz.
            </p>

            <!-- Section 1: No JS -->
            <div class="sec-block">
                <h3>🛡️ 1. JavaScript Kullanmıyoruz — XSS Sıfırlama</h3>
                <p>
                    Platformumuz kasıtlı olarak istemci tarafı JavaScript içermez. XSS (Cross-Site Scripting) saldırıları
                    tarayıcıda çalışan JavaScript koduna dayanır; bu kod tamamen yoksa saldırı yüzeyi sıfıra iner.
                </p>
                <p>
                    Kullanıcı tarafından girilen hiçbir veri komut olarak <em>yorumlanmaz</em> — yalnızca güvenli HTML metni
                    olarak gösterilir. Bir saldırgan ne kadar kötü amaçlı içerik gönderirse göndersin, tarayıcıda herhangi
                    bir kod çalışamaz.
                </p>
                <p>
                    <a href="<?= BASE_PATH ?>/javascript-kullanmiyoruz">JavaScript politikamız hakkında daha fazla bilgi →</a>
                </p>
            </div>

            <!-- Section 2: HTTPS & Security Headers -->
            <div class="sec-block">
                <h3>🔐 2. HTTPS, HSTS ve Güvenlik Başlıkları</h3>
                <p>
                    Tüm bağlantılar şifreli HTTPS üzerinden gerçekleşir. Tarayıcınız bir kez sitemizi ziyaret ettikten
                    sonra bir daha şifresiz bağlantı denemez; bu kuralı sunucu tarafında kalıcı olarak zorunlu kılıyoruz.
                </p>
                <ul class="sec-list">
                    <li><strong>Bağlantı her zaman şifreli:</strong> Platformumuza yapılan tüm istekler HTTPS üzerinden iletilir; araya giren biri trafiği okuyamaz veya değiştiremez.</li>
                    <li><strong>Çerçeveye alınamaz:</strong> Sayfalarımız başka sitelerin içine gömülemez; bu sayede sizi kandırmaya yönelik sahte arayüz saldırıları engellenir.</li>
                    <li><strong>İçerik türü tahmini kapalı:</strong> Tarayıcı, sunucunun belirttiği dışında bir içerik türü olarak dosyaları işleyemez; kötü amaçlı dosyaların farklı yorumlanması önlenir.</li>
                    <li><strong>Sizi izleyen bağlantı bilgisi paylaşılmaz:</strong> Başka bir siteye geçtiğinizde hangi sayfadan geldiğiniz bilgisi o siteyle paylaşılmaz.</li>
                    <li><strong>Kamera, mikrofon ve konum erişimi yok:</strong> Tarayıcı izinleri sunucu tarafında tamamen kapatılmıştır; hiçbir reklam takip mekanizması çalışamaz.</li>
                    <li><strong>Dış kaynak yüklenmiyor:</strong> Sayfalar yalnızca kendi sunucumuzdan kaynak yükler; üçüncü taraf betik veya içerik enjeksiyonuna izin verilmez.</li>
                </ul>
            </div>

            <!-- Section 3: CSRF -->
            <div class="sec-block">
                <h3>🔁 3. CSRF Koruması — Tek Kullanımlık Token Havuzu</h3>
                <p>
                    CSRF (Cross-Site Request Forgery) saldırısında saldırgan, oturumunuzu açık olan başka bir
                    siteden sizin adınıza işlem yaptırmaya çalışır. Bunu engellemek için her form gönderiminde
                    doğrulanabilen benzersiz bir token kullanıyoruz.
                </p>
                <ul class="sec-list-tight">
                    <li><strong>Kriptografik rastgelelik:</strong> Her token <code>random_bytes(32)</code> ile üretilir — tahmin edilemez.</li>
                    <li><strong>Dönen havuz:</strong> Aynı anda en fazla 10 token aktif tutulur; eskiler FIFO sırasıyla atılır.</li>
                    <li><strong>Tek kullanım:</strong> Token doğrulandıktan hemen sonra havuzdan silinir — aynı token tekrar kullanılamaz.</li>
                    <li><strong>Zamanlama güvenli karşılaştırma:</strong> Doğrulama <code>hash_equals()</code> ile yapılır; timing saldırılarına karşı koruma sağlar.</li>
                </ul>
            </div>

            <!-- Section 4: Password Security -->
            <div class="sec-block">
                <h3>🔑 4. Şifre Güvenliği</h3>
                <p>
                    Şifreler asla düz metin olarak saklanmaz. Veritabanında yalnızca şifrenizin
                    <strong>bcrypt</strong> ile üretilmiş karma (hash) değeri tutulur.
                </p>
                <ul class="sec-list-tight">
                    <li><strong>bcrypt:</strong> Kasıtlı olarak yavaş çalışan bir algoritma — brute-force saldırılarını hesaplama maliyetiyle engeller.</li>
                    <li><strong>Tuz (salt):</strong> Her hash'e özgü rastgele tuz; gökkuşağı tablosu (rainbow table) saldırılarını geçersiz kılar.</li>
                    <li><strong>Kayıt yok:</strong> Şifreler hiçbir log dosyasına, hata raporuna veya veritabanının başka bir alanına yazılmaz.</li>
                    <li><strong>Sıfırlama bağlantıları:</strong> Şifre sıfırlama tokenları tek kullanımlık ve süreli — kötüye kullanılamaz.</li>
                </ul>
            </div>

            <!-- Section 5: Data Retention -->
            <div class="sec-block">
                <h3>🗂️ 5. Veri Saklama ve Silme</h3>
                <p>
                    Verilerini silmek isteyen kullanıcılar için net ve denetlenebilir bir süreç uygulanmaktadır:
                </p>
                <table class="sec-table">
                    <thead>
                        <tr>
                            <th>Aşama</th>
                            <th>Süre</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Yumuşak silme</td>
                            <td>Anlık</td>
                            <td>Hesap/içerik herkese görünmez olur; hesabınızı bu süre içinde geri alabilirsiniz</td>
                        </tr>
                        <tr class="sec-row-alt">
                            <td>Kalıcı silme</td>
                            <td>90 gün</td>
                            <td>Canlı veritabanından tamamen kaldırılır</td>
                        </tr>
                        <tr>
                            <td>Yedek temizleme</td>
                            <td>180 gün</td>
                            <td>Döngüsel yedekler silindiğinde verileriniz yedeklerden de çıkmış olur</td>
                        </tr>
                        <tr class="sec-row-alt">
                            <td>Silme talebine yanıt</td>
                            <td>En geç 30 gün</td>
                            <td>KVKK kapsamındaki resmi taleplere yasal süre içinde yanıt verilir</td>
                        </tr>
                    </tbody>
                </table>
                <p class="sec-block-footer">
                    Daha fazlası için:
                    <a href="<?= privacy_url() ?>">Gizlilik Politikası</a> ·
                    <a href="<?= kvkk_url() ?>">KVKK Aydınlatma Metni</a>
                </p>
            </div>

            <!-- Section 6: Responsible Disclosure -->
            <div class="sec-block sec-block--green">
                <h3>📩 6. Güvenlik Açığı Bildirimi</h3>
                <p>
                    Platformumuzda bir güvenlik açığı keşfettiyseniz lütfen bize bildirin.
                    Raporunuzu ciddiye alır, en kısa sürede yanıtlar ve sorumlu açıklama
                    (responsible disclosure) ilkesine uygun davranırız.
                </p>
                <ul class="sec-list-tight">
                    <li>Açığı kamuoyuyla paylaşmadan önce bize bildirin.</li>
                    <li>Gerçek kullanıcı verilerine erişmekten veya zarar vermekten kaçının.</li>
                    <li>Raporunuzu detaylı biçimde açıklayın: etki kapsamı, yeniden üretme adımları.</li>
                </ul>
                <p>
                    <strong>İletişim:</strong>
                    <a href="mailto:admin@mevzuatraporu.com">admin@mevzuatraporu.com</a>
                </p>
            </div>

            <p class="sec-footer-note">
                Bu sayfa en son <?= date('d.m.Y') ?> tarihinde güncellenmiştir.
            </p>

            <p class="sec-back-link"><a href="<?= BASE_PATH ?>/">&larr; Ana sayfaya dön</a></p>

        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
