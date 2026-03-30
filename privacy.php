<?php
// Redirect legacy URL to clean URL (avoid duplicate content)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'privacy.php') !== false) {
    header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/gizlilik', true, 301);
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title"><?= t('privacy_title') ?></h1>
        
        <div style="padding: 20px; line-height: 1.7; font-size: 13px;">
            <h2 style="font-size:16px;color:#333;margin-bottom:10px;">Gizlilik Politikası</h2>
            <p><strong>Son Güncelleme:</strong> 14 Ocak 2026</p>
            
            <h3>1. Toplanan Bilgiler</h3>
            <p>Mevzuat Raporu platformunda kullanıcı hesabı oluştururken aşağıdaki bilgileri topluyoruz:</p>
            <ul>
                <li>Kullanıcı adı</li>
                <li>E-posta adresi (isteğe bağlı)</li>
                <li>Şifre (şifrelenmiş olarak saklanır)</li>
                <li>Paylaştığınız içerikler</li>
            </ul>
            
            <h3>2. Bilgilerin Kullanımı</h3>
            <p>Topladığımız bilgiler şu amaçlarla kullanılır:</p>
            <ul>
                <li>Hesabınızı oluşturmak ve yönetmek</li>
                <li>Platform özelliklerini sağlamak</li>
                <li>Kullanıcı deneyimini geliştirmek</li>
                <li>Güvenlik ve doğrulama</li>
            </ul>
            
            <h3>3. Bilgi Paylaşımı</h3>
            <p>Kişisel bilgileriniz üçüncü taraflarla paylaşılmaz. Kullanıcı adınız ve paylaştığınız içerikler platform içinde diğer kullanıcılar tarafından görülebilir.</p>
            
            <h3>4. Çerezler</h3>
            <p>Platformumuz, oturumunuzu yönetmek için çerezler kullanır.</p>
            
            <h3>5. Güvenlik</h3>
            <p>Bilgilerinizin güvenliğini sağlamak için uygun teknik ve organizasyonel önlemleri alıyoruz.</p>

            <h3>5.1. AI Tarama (Scraping) ve Bot Kuralları</h3>
            <p>Mevzuat Raporu platformunda otomatik veri toplama (web scraping) işlemleri, özellikle yapay zekâ/ML için içerik çekimi, açıkça yasaktır. Bu tür faaliyetler için önceden yazılı izin gereklidir.</p>
            <ul>
                <li>İzinsiz botlar ve veri toplama araçları tespit edilirse erişim engellenebilir.</li>
                <li>Rate limit ve API kısıtlamaları ihlal edilirse hukuki yaptırım uygulanabilir.</li>
                <li>Robots.txt kurallarına uymayan erişimler reddedilir.</li>
            </ul>
            <p>Bu kural tüm ziyaretçiler ve otomatik sistemler için geçerlidir. Kötü amaçlı tarama, hesap askıya alma veya kalıcı bloklamaya sebep olabilir.</p>

            <h3>6. Haklarınız</h3>
            <p>Kişisel verilerinize erişme, düzeltme veya silme hakkına sahipsiniz. Hesabınızı istediğiniz zaman silebilirsiniz.</p>
            
            <h3>7. İletişim</h3>
            <p>Gizlilik politikamız hakkında sorularınız varsa, lütfen bizimle iletişime geçin.</p>
            
            <p style="margin-top: 20px;">
                <a href="<?= BASE_PATH ?>/kayit">Kayıt sayfasına dön</a>
            </p>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
