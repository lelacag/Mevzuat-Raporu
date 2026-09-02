<?php /* EN + TR comments used. */
// Redirect legacy URL to clean URL (avoid duplicate content)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'kvkk.php') !== false) {
    header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/kvkk/', true, 301);
    exit;
}
require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title"><?= t('kvkk_title') ?></h1>

        <div class="policy-body">
            <h2>KVKK Aydınlatma Metni</h2>
            <p><strong>Son Güncelleme:</strong> 18 Mayıs 2026</p>

            <h3>1. Veri Sorumlusu</h3>
            <p>6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca, kişisel verileriniz Mevzuat Raporu tarafından aşağıda belirtilen kapsamda işlenecektir.</p>

            <h3>2. İşleme Amaçları</h3>
            <ul>
                <li>Kullanıcı hesabının oluşturulması, yönetilmesi ve kimlik doğrulaması</li>
                <li>Platform hizmetlerinin sağlanması ve kullanıcı deneyiminin geliştirilmesi</li>
                <li>Güvenlik, kötüye kullanım engelleme ve hukuki yükümlülüklerin yerine getirilmesi</li>
            </ul>

            <h3>3. İşlenen Kişisel Veriler</h3>
            <ul>
                <li>Kullanıcı adı</li>
                <li>E-posta adresi</li>
                <li>Şifre özetleri</li>
                <li>Profil ve hesap bilgileri</li>
                <li>Gönderiler, yorumlar ve beğeniler</li>
                <li>Oturum ve güvenlik bilgileri</li>
            </ul>

            <h3>4. Aktarım</h3>
            <p>Verileriniz, KVKK'nın 8. ve 9. maddelerinde belirtilen şartlara uygun şekilde yurt içi ve yurt dışı aktarılabilir. Veri aktarımı yalnızca gerekli olduğunda ve hukuki zemine uygun olarak yapılır.</p>

            <h3>5. Üçüncü Taraf Hizmet Sağlayıcıları</h3>
            <p>Platformumuz e-posta gönderimi için <a href="https://www.google.com/gmail/" target="_blank" rel="noopener noreferrer">Gmail</a> ve <a href="https://www.titan.email/" target="_blank" rel="noopener noreferrer">Titan Email</a>, alan adı/DNS altyapısı için <a href="https://www.name.com/" target="_blank" rel="noopener noreferrer">Name.com</a> ve barındırma hizmetleri için <a href="https://www.hostinger.com/" target="_blank" rel="noopener noreferrer">Hostinger</a> kullanır. Bu hizmet sağlayıcılar verilerinizi bizim adımıza işler; veriler satılmaz ve yalnızca hizmet sunumu amaçlı kullanılmaktadır. Sağlayıcıların kendi saklama politikaları olabilir ve bu süreçler platformun kontrolü dışında kalabilir.</p>

            <h3>6. Hukuki Sebep</h3>
            <p>Veri işleme işlemi, tarafınıza hizmet sunmak ve platformun güvenliğini sağlamak amacıyla yapılmaktadır. Ayrıca, kullanıcı sözleşmesi ve açık rızaya dayanır.</p>

            <h3>6. Haklarınız</h3>
            <p>KVKK'nın 11. maddesi uyarınca haklarınız şunlardır:</p>
            <ul>
                <li>Kişisel verilerinizin işlenip işlenmediğini öğrenme</li>
                <li>İşlenmişse bilgi talep etme</li>
                <li>İşlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme</li>
                <li>Yurt içinde/yurt dışında aktarım yapılıp yapılmadığını öğrenme</li>
                <li>Verilerin düzeltilmesini isteme</li>
                <li>Verilerin silinmesini veya yok edilmesini isteme</li>
            </ul>

            <h3>7. Saklama Süreleri</h3>
            <p>Hesabınız silindiğinde verileriniz erişime kapatılır ve soft-delete olarak en fazla <strong>90 gün</strong> boyunca saklanır. Yedeklerde bu süre <strong>180 güne</strong> kadar uzayabilir.</p>

            <h3>8. Verilerin Silinmesi</h3>
            <p>Silme talepleri eyleme alınacak ve veriler soft-delete edilerek erişime kapatılacaktır. Kalıcı silme, yedekleme süreçlerinin tamamlanmasının ardından veya yazılı talep doğrultusunda başlatılabilir.</p>

            <h3>9. İletişim</h3>
            <p>KVKK kapsamındaki haklarınızı kullanmak için lütfen bizimle site içi iletişim yolları aracılığıyla iletişime geçin.</p>

            <p style="margin-top: 20px;"><a href="<?= BASE_PATH ?>/gizlilik">Gizlilik Politikası</a> · <a href="<?= BASE_PATH ?>/cerezler">Çerez Politikası</a></p>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
