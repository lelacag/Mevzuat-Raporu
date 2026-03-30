<?php /* EN + TR comments used. */
// Redirect legacy URL to clean URL (avoid duplicate content)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'kvkk.php') !== false) {
    header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/kvkk', true, 301);
    exit;
}
require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title"><?= t('kvkk_title') ?></h1>
        
        <div style="padding: 20px; line-height: 1.7; font-size: 13px;">
            <h2>KVKK Aydınlatma Metni</h2>
            <p><strong>Son Güncelleme:</strong> 14 Ocak 2026</p>
            
            <h3>1. Veri Sorumlusu</h3>
            <p>6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca, kişisel verileriniz Mahalle Raporu tarafından aşağıda açıklanan kapsamda işlenecektir.</p>
            
            <h3>2. Kişisel Verilerin İşlenme Amacı</h3>
            <p>Kişisel verileriniz aşağıdaki amaçlarla işlenmektedir:</p>
            <ul>
                <li>Kullanıcı hesabınızın oluşturulması ve yönetilmesi</li>
                <li>Platform hizmetlerinin sunulması</li>
                <li>Kullanıcı deneyiminin geliştirilmesi</li>
                <li>Yasal yükümlülüklerin yerine getirilmesi</li>
                <li>Güvenlik ve doğrulama süreçlerinin yürütülmesi</li>
            </ul>
            
            <h3>3. İşlenen Kişisel Veriler</h3>
            <ul>
                <li>Kimlik bilgisi: Kullanıcı adı</li>
                <li>İletişim bilgisi: E-posta adresi</li>
                <li>İşlem güvenliği bilgisi: Şifre (şifrelenmiş)</li>
                <li>Müşteri işlem bilgisi: Paylaşılan içerikler, beğeniler, yorumlar</li>
            </ul>
            
            <h3>4. Kişisel Verilerin Aktarımı</h3>
            <p>Kişisel verileriniz, KVKK'nın 8. ve 9. maddelerinde belirtilen şartlar dahilinde yurt içi ve yurt dışına aktarılabilir.</p>
            
            <h3>5. Kişisel Veri Toplamanın Yöntemi ve Hukuki Sebebi</h3>
            <p>Kişisel verileriniz, platform üzerinden elektronik ortamda, açık rızanız ile toplanmaktadır.</p>
            
            <h3>6. Haklarınız</h3>
            <p>KVKK'nın 11. maddesi uyarınca aşağıdaki haklara sahipsiniz:</p>
            <ul>
                <li>Kişisel verilerinizin işlenip işlenmediğini öğrenme</li>
                <li>Kişisel verileriniz işlenmişse buna ilişkin bilgi talep etme</li>
                <li>Kişisel verilerin işlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme</li>
                <li>Yurt içinde veya yurt dışında kişisel verilerin aktarıldığı üçüncü kişileri bilme</li>
                <li>Kişisel verilerin eksik veya yanlış işlenmiş olması hâlinde bunların düzeltilmesini isteme</li>
                <li>Kişisel verilerin silinmesini veya yok edilmesini isteme</li>
            </ul>
            
            <h3>7. İletişim</h3>
            <p>KVKK kapsamındaki haklarınızı kullanmak için bizimle iletişime geçebilirsiniz.</p>
            
            <p style="margin-top: 20px;">
                <a href="<?= full_url(invite_url()) ?>">Kayıt sayfasına dön</a>
            </p>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
