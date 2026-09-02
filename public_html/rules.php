<?php
// Set basic meta tags without relying on the translation helper (t()) before header is included.
$META_TITLE = 'Kurallar ve Şartlar';
$META_DESCRIPTION = 'Mevzuat Raporu topluluk kuralları: saygı, mahremiyet, telif haklarına saygı, spam yasağı ve moderasyon süreçleri.';
require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
	<main class="content-area">
		<h1 class="section-title"><?= t('rules_and_terms') ?></h1>
		<div class="policy-body">
			<p>Bu kurallar Mevzuat Raporu topluluğunun huzurlu ve güvenli bir ortamda etkileşim kurmasını sağlamak için hazırlanmıştır. Kurallara uymayan içerikler uyarı, geçici kısıtlama veya kalıcı askıya alma ile sonuçlanabilir.</p>

			<h3>1) Karşılıklı saygı</h3>
			<p>Kişilere karşı hakaret, tehdit, ayrımcılık veya nefret söylemi yasaktır. Tartışmalar saygı çerçevesinde yürütülmelidir.</p>

			<h3>2) Mahremiyet</h3>
			<p>Başkalarının kişisel bilgilerini (adres, telefon, kimlik numarası vb.) izinsiz paylaşmayın.</p>

			<h3>3) Telif hakları</h3>
			<p>Telif hakkı ile korunan içeriklerin izinsiz paylaşımı yasaktır; hak sahibi bildiriminde içerik kaldırılacaktır.</p>

			<h3>4) Spam ve reklam</h3>
			<p>İzinsiz reklam, toplu tanıtım, botlar veya platformu manipüle eden davranışlar yasaktır.</p>

			<h3>5) Yasadışı içerik</h3>
			<p>Terör, çocuk istismarı, suç işleme rehberliği veya diğer yasa dışı faaliyetleri teşvik eden içerik yasaktır.</p>

			<h3>6) Yapay Zekâ ve otomatik veri toplama</h3>
			<p>Mevzuat Raporu üzerindeki içerik ve kullanıcı verileri, açıkça izin verilmedikçe otomatik araçlar (bot, kazıyıcı (scraper), sürünen(crawler), yapay zekâ eğitimi için veri çekme sistemleri vb.) kullanılarak toplanamaz, çoğaltılamaz, dağıtılamaz veya üçüncü kişilere aktaralamaz.</p>
			<p>AI/ML amaçlı veri kazıma, site içeriğine aşırı yüklenen istekler, ve veri toplama amacıyla kimlik gizleme girişimleri tespit edildiğinde:</p>
			<ul>
				<li>Kullanıcı hesabı askıya alınabilir veya silinebilir.</li>
				<li>IP adresleri ve ilgili bağlantılar bloklanabilir.</li>
				<li>Gerekli durumlarda cezai/medeni yasal süreç başlatılabilir (5651, KVKK, FSEK, ilgili yürürlükteki kanunlar çerçevesinde).</li>
			</ul>
			<p>Ayrıca, bu tür erişimler, robot erişim kurallarına (robots.txt gibi) ve bizim tarafımızdan konulan API rate limitlerine mutlaka uymalıdır. İzinsiz AI kazıma, platformun amaç dışı kullanımı olarak değerlendirilir.</p>

			<h3>7) Moderasyon, şikayet ve itiraz</h3>
			<p>İhlalleri bildirmek için lütfen platformun rapor araçlarını kullanın. Moderasyon kararlarına itiraz için yönetimle iletişime geçebilirsiniz; itirazlar sınırlı süre içinde incelenecektir.</p>

			<h3>7) Hesap güvenliği</h3>
			<p>Hesap bilgilerinizin güvenliğinden siz sorumlusunuz; kural ihlalleri uyarıdan kalıcı askıya almaya kadar yaptırım doğurabilir.</p>

			<p style="margin-top:20px;">Bu kurallar Mevzuat Raporu tarafından belirlenmiştir. Sorular için admin@mevzuatraporu.com'a başvurun.</p>
<p>Platformumuz e-posta hizmetleri için <a href="https://www.google.com/gmail/" target="_blank" rel="noopener noreferrer">Gmail</a> ve <a href="https://www.titan.email/" target="_blank" rel="noopener noreferrer">Titan Email</a>, alan adı/DNS yönetimi için <a href="https://www.name.com/" target="_blank" rel="noopener noreferrer">Name.com</a> ve barındırma hizmetleri için <a href="https://www.hostinger.com/" target="_blank" rel="noopener noreferrer">Hostinger</a> kullanır. Bu hizmetler, kullanıcı verilerini platform operasyonu amacıyla işler; veriler satılmaz.</p>
		</div>

		<hr style="margin:24px 0; border:0; border-top:1px solid #eee;">

		<div class="policy-body">
			<p><strong><?= t('terms_last_update') ?>:</strong> <?= date('d.m.Y') ?></p>

			<h2><?= t('terms_acceptance') ?></h2>
			<p><?= t('terms_acceptance_desc') ?></p>

			<h2><?= t('terms_user_obligations') ?></h2>
			<p><?= t('terms_user_obligations_desc') ?></p>
			<ul>
				<li><?= t('terms_obligation_1') ?></li>
				<li><?= t('terms_obligation_2') ?></li>
				<li><?= t('terms_obligation_3') ?></li>
				<li><?= t('terms_obligation_4') ?></li>
				<li><?= t('terms_obligation_5') ?></li>
				<li><?= t('terms_obligation_6') ?></li>
			</ul>

			<h2><?= t('terms_prohibited_content') ?></h2>
			<p><?= t('terms_prohibited_desc') ?></p>
			<ul>
				<li><?= t('terms_prohibited_1') ?></li>
				<li><?= t('terms_prohibited_2') ?></li>
				<li><?= t('terms_prohibited_3') ?></li>
				<li><?= t('terms_prohibited_4') ?></li>
				<li><?= t('terms_prohibited_5') ?></li>
				<li><?= t('terms_prohibited_6') ?></li>
				<li><?= t('terms_prohibited_7') ?></li>
			</ul>

			<h2><?= t('terms_content_moderation') ?></h2>
			<p><?= t('terms_moderation_desc') ?></p>

			<h2><?= t('terms_account_termination') ?></h2>
			<p><?= t('terms_termination_desc') ?></p>

			<h2><?= t('terms_intellectual_property') ?></h2>
			<p><?= t('terms_ip_desc') ?></p>

			<h2><?= t('terms_user_content') ?></h2>
			<p><?= t('terms_user_content_desc') ?></p>
				<h2>AI ve Arama Motoru Optimizasyonu (JSON Olmadan)</h2>
				<p>Bu platform, generative AI ve arama motorlarına uygunluk için semantik HTML ve açık başlıklar kullanmaya öncelik verir. JSON-LD gibi yapısal veri formatları fayda sağlar, ancak zorunlu değildir.</p>
				<ul>
					<li><strong>Article, section, header, footer</strong> gibi etiketlerle içerik yapısını netleştirin.</li>
					<li><strong><meta name="description"></strong> ve <strong><link rel="canonical"></strong></li>
					<li><strong><time datetime="..."></strong> gibi tarih</li>
					<li>Açık anahtar kelime özetleri ve sıralamaya uygun doğal dil</li>
				</ul>
				<p>Bu yaklaşım, hem insan okuyucu hem de yapay zekâ tarafından kolayca verilebilecek anlamlı bir içerik temeli sağlar.</p>
			<h2><?= t('terms_disclaimer') ?></h2>
			<p><?= t('terms_disclaimer_desc') ?></p>

			<h2><?= t('terms_limitation') ?></h2>
			<p><?= t('terms_limitation_desc') ?></p>

			<h2><?= t('terms_changes') ?></h2>
			<p><?= t('terms_changes_desc') ?></p>

			<h2><?= t('terms_contact') ?></h2>
			<p><?= t('terms_contact_desc') ?></p>

			<div style="margin-top: 40px; padding: 15px; background: #f9f9f9; border-left: 4px solid #3498db;">
				<p style="margin: 0; font-size: 14px; color: #555;">
					<?= t('terms_footer_note') ?>
				</p>
			</div>
		</div>
	</main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
