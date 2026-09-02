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

        <div class="policy-body">
            <h2 style="font-size:16px;color:#333;margin-bottom:10px;">Gizlilik Politikası</h2>
            <p><strong>Son Güncelleme:</strong> 18 Mayıs 2026</p>

            <h3>1. Kapsam</h3>
            <p>Bu gizlilik politikası, kullanıcıların Mevzuat Raporu platformu üzerindeki kişisel verilerinin nasıl toplandığını, kullanıldığını, depolandığını ve korunduğunu açıklar.</p>

            <h3>2. Toplanan Veriler</h3>
            <p>Aşağıdaki kişisel verileri işleriz:</p>
            <ul>
                <li>Kullanıcı adı</li>
                <li>E-posta adresi</li>
                <li>Şifre özetleri</li>
                <li>Profil ve hesap bilgileri</li>
                <li>Gönderiler, yorumlar, beğeniler ve etkileşim verileri</li>
                <li>Oturum ve güvenlik verileri (IP adresi, tarayıcı bilgisi, oturum çerezleri)</li>
            </ul>

            <h3>3. İşleme Amaçları</h3>
            <p>Verileriniz aşağıdaki amaçlarla işlenir:</p>
            <ul>
                <li>Hesabınızı yönetmek ve kimliğinizi doğrulamak</li>
                <li>Platform hizmetlerini sunmak ve kullanıcı deneyimini sağlamak</li>
                <li>Güvenlik, kötüye kullanım tespiti ve dolandırıcılık önleme</li>
                <li>İlgili yasal yükümlülüklere uymak</li>
            </ul>

            <h3>4. Hukuki Sebepler</h3>
            <p>Veri işleme işlemleri, kullanıcı sözleşmesine ve açık rızaya dayanmaktadır. Ayrıca, güvenlik sağlama ve yasal yükümlülükleri yerine getirme amaçlarıyla gerekli veriler işlenir.</p>

            <h3>5. Veri Paylaşımı</h3>
            <p>Kişisel verileriniz, açıkça izin vermedikçe üçüncü taraflarla paylaşılmaz. Yalnızca yasal gereklilikler veya güvenlik incelemeleri için yetkili makamlarla paylaşım yapılabilir.</p>

            <h3>6. Üçüncü Taraf Hizmet Sağlayıcıları</h3>
            <p>Platformumuz e-posta bildirimleri için <a href="https://www.google.com/gmail/" target="_blank" rel="noopener noreferrer">Gmail</a> ve <a href="https://www.titan.email/" target="_blank" rel="noopener noreferrer">Titan Email</a>, alan adı/DNS yönetimi için <a href="https://www.name.com/" target="_blank" rel="noopener noreferrer">Name.com</a> ve barındırma hizmetleri için <a href="https://www.hostinger.com/" target="_blank" rel="noopener noreferrer">Hostinger</a> kullanır. Bu hizmet sağlayıcılar, verilerinizi bizim adımıza işler; veriler satılmaz ve yalnızca platform hizmetlerinin sağlanması amacıyla kullanılır. Bu hizmet sağlayıcıların kendi saklama ve depolama politikaları olabilir.</p>

            <h3>7. Veri Saklama Süresi</h3>
            <p>Hesabınızı silme talebi sonrası verileriniz erişime kapatılır ve soft-delete olarak işaretlenir. Bu veriler en fazla <strong>90 gün</strong> süreyle saklanır. Yedekler içinde veriler <strong>180 güne</strong> kadar bulunabilir.</p>

            <h3>7. Kullanıcı Hakları</h3>
            <p>Kullanıcılar aşağıdaki haklara sahiptir:</p>
            <ul>
                <li>Verilerinin işlenip işlenmediğini öğrenme</li>
                <li>İşlenen verileri görme ve düzeltme</li>
                <li>Verilerin silinmesini isteme</li>
                <li>Çerez tercihlerini geri çekme veya reddetme</li>
            </ul>

            <h3>8. Çerezler</h3>
            <p>Bu site, oturum yönetimi ve güvenlik için zorunlu çerezler kullanır. <a href="<?= BASE_PATH ?>/cerezler">Çerez politikamızı</a> okuyarak çerezlerin nasıl kullanıldığını öğrenebilirsiniz.</p>

            <h3>9. Güvenlik</h3>
            <p>Verilerinizi korumak için uygun teknik ve idari önlemler uygulanmaktadır. Yetkisiz erişim, veri sızıntısı ve kötüye kullanım risklerini azaltmak için şifreleme ve erişim kontrolü kullanılmaktadır.</p>

            <h3>10. İletişim</h3>
            <p>Bu gizlilik politikasıyla ilgili sorularınız veya veri hak talepleriniz için lütfen iletişim kanallarımızı kullanın.</p>

            <p style="margin-top: 20px;"><a href="<?= BASE_PATH ?>/cerezler">Çerez Politikası</a> · <a href="<?= BASE_PATH ?>/kvkk">KVKK Aydınlatma Metni</a></p>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
