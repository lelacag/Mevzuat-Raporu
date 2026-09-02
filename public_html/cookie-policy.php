<?php /* EN + TR comments used. */
// Redirect legacy URL to clean URL (avoid duplicate content)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'cookie-policy.php') !== false) {
    header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/cerezler', true, 301);
    exit;
}

require_once __DIR__ . '/includes/header.php';

$page_title = $lang['cookie_policy_title'] ?? 'Çerez Politikası';
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title"><?= t('cookie_policy_title') ?></h1>

        <div class="policy-body">
            <p class="policy-updated"><strong>Son Güncelleme:</strong> 18 Mayıs 2026</p>

            <h3>1. Amaç</h3>
            <p>Bu çerez politikası, Mevzuat Raporu platformunun hangi çerezleri kullandığını, bu çerezlerin neden gerekli olduğunu ve kullanıcıların hangi durumlarda çerez tercihlerini değiştirebileceklerini açıklar.</p>

            <h3>2. Kullanılan Çerezler</h3>
            <ul>
                <li><strong>PHPSESSID</strong> — oturum tanımlayıcısı; kullanıcı girişi, oturum yönetimi ve güvenlik için gereklidir.</li>
                <li><strong>cookie_notice_accepted</strong> — çerez tercihinizi hatırlamak için kullanılır ve bannerın tekrar gösterilmesini engeller. Bu çerez, kullanıcı seçimlerinin saklanması için gerekli bir tercihtir.</li>
                <li><strong>sid</strong> veya URL tabanlı oturum belirteci — tarayıcı çerezleri reddedildiğinde, temel oturum bazlı işlevleri sağlamak için kullanılır.</li>
            </ul>

            <h3>3. Çerez Sınıfları ve Hukuki Dayanak</h3>
            <ul>
                <li><strong>Zorunlu çerezler:</strong> Site çalışması için zorunludur. Bu çerezler olmadan oturum açma, içerik paylaşma, sayfa gezinme ve temel güvenlik işlevleri tam olarak çalışmaz. GDPR kapsamında bu çerezlerin kullanımı hizmetin sağlanması için gereklidir.</li>
                <li><strong>Analitik/pazarlama çerezleri:</strong> Şu anda hiçbir analitik veya reklam amaçlı çerez kullanılmamaktadır.</li>
            </ul>

            <h3>4. Onay ve Reddetme</h3>
            <p>Çerez bildiriminde "Kabul ediyorum" seçeneğini seçerek bu politikayı ve zorunlu çerezlerin kullanımını kabul etmiş olursunuz. "Çerezleri reddet" seçeneğini seçerseniz, site yalnızca zorunlu çerezleri veya URL tabanlı oturum belirtecini kullanmaya devam edecektir.</p>
            <p>Seçiminizi değiştirmek veya çerez tercihlerinizi yeniden gözden geçirmek isterseniz, siteyi yeniden ziyaret ederek çerez bildirimini tekrar kullanabilirsiniz. Ayrıca tarayıcı ayarlarınızdan çerezleri silebilir veya engelleyebilirsiniz; ancak zorunlu çerezler engellenirse sitenin bazı bölümleri düzgün çalışmayabilir.</p>

            <h3>5. Reddedildiğinde Ne Olur?</h3>
            <p>Çerezleri reddettiğinizde, site yalnızca temel işlevler için gerekli olan çerezleri kullanır. Bazı oturum bazlı özellikler, tarayıcı çerezlerine alternatif olarak URL tabanlı oturum kimliği (sid) ile çalışabilir. Bu, tarayıcıda izleme amaçlı herhangi bir analitik veya pazarlama çerezi kullanılmadığı anlamına gelir.</p>

            <h3>6. Saklanma Süresi</h3>
            <p>Oturum çerezleri genellikle tarayıcı kapatıldığında sona erer. <strong>Çerez kabul edildi</strong> çerezi, kullanıcı tercihlerini hatırlamak için en fazla bir yıl boyunca saklanır. Bu çerez, yalnızca çerez tercihinizin tekrar sorulmasını önlemek amacıyla tutulur.</p>

            <h3>7. Üçüncü Taraf Çerezleri</h3>
            <p>Bu platformda şu anda üçüncü taraf analitik, reklam veya sosyal medya çerezleri kullanılmamaktadır. Üçüncü taraf hizmetler kullanılmaya başlandığında, bu hizmetler ve ilgili çerezler bu sayfada açıkça bildirilecektir.</p>

            <h3>8. Haklarınız ve İletişim</h3>
            <p>Kişisel verilerinize erişme, düzeltme ve silme haklarınız bulunmaktadır. Ayrıca çerez tercihlerinizi geri çekme veya tekrar verme hakkına sahipsiniz. Hesabınızı silme istekleri için site içindeki hesap silme adımlarını takip edebilirsiniz.</p>
            <p>Bu çerez politikası hakkında sorularınız varsa, lütfen <a href="mailto:admin@mevzuatraporu.com">admin@mevzuatraporu.com</a> adresine e-posta gönderin. Her türlü veri ve çerez tercih sorusu için bu e-posta adresinden bizimle iletişime geçebilirsiniz.</p>
        </div>

        <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
            .policy-body {
                padding: 24px;
                line-height: 1.75;
                font-size: 14px;
                background: #fff;
                border: 1px solid #e6e6e6;
                border-radius: 10px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                max-width: 820px;
                margin: 0 auto;
            }
            .policy-body h3 {
                font-size: 15px;
                font-weight: 700;
                margin: 22px 0 10px;
                color: #2a2a2a;
            }
            .policy-body p {
                margin: 0 0 14px;
                color: #404040;
            }
            .policy-body ul {
                margin: 0 0 18px 24px;
                padding-left: 0;
                list-style: disc outside;
            }
            .policy-body ul li {
                margin-bottom: 10px;
            }
            .policy-updated {
                color: #6d6d6d;
                margin-bottom: 18px;
            }
        </style>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
