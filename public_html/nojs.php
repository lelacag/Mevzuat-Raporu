<?php
require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">JavaScript Kullanmıyoruz</h1>
        <div style="padding: 20px; line-height: 1.7; font-size: 13px;">
            <div style="text-align:center; margin-bottom: 24px;">
                <img src="<?= BASE_PATH ?>/assets/nojs.png" alt="NO JS" width="100" height="100">
            </div>
            <h2 style="font-size:16px;color:#333;margin-bottom:10px;">Neden JavaScript Yok?</h2>
            <h3>1. Gizlilik</h3>
            <p>JavaScript kullanıcıları takip etmek için yaygın biçimde kullanılır. Biz sizi izlemiyoruz; JavaScript kaldırılarak bu mekanizmaların önüne geçildi.</p>
            <h3>2. Performans</h3>
            <p>Saf HTML/CSS tabanlı yapımız sayesinde sayfalar anında yüklenir; eski cihazlarda ve düşük bant genişliğinde de sorunsuz çalışır.</p>
            <h3>3. Güvenlik</h3>
            <p>XSS saldırıları JavaScript gerektirir. JavaScript olmadan bu saldırı yüzeyi sıfıra indirildi.</p>
            <h3>4. Erişilebilirlik</h3>
            <p>Tüm özellikler JavaScript devre dışıyken de çalışır. Ekran okuyucular ve metin tarayıcılar tam işlevsellikle platformu kullanabilir.</p>
            <h3>5. Şeffaflık</h3>
            <p>Sayfaların kaynağına bakarak sitenin nasıl çalıştığını görebilirsiniz. Her şey açık ve okunabilir HTML'dir.</p>
            <p style="margin-top: 24px;"><a href="<?= BASE_PATH ?>/">&larr; Ana sayfaya dön</a></p>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
