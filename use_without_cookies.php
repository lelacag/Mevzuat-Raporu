<?php
require_once __DIR__ . '/includes/config.php';
// Lightweight no-JS page explaining the no-cookie flow and offering links back to login/landing
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Use without cookies</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px;background:#fff;color:#111} .panel{max-width:640px;margin:40px auto;border:1px solid #ddd;padding:16px;border-radius:6px}</style>
</head>
<body>
  <div class="panel">
    <h1>Çerez kullanmadan devam et</h1>
    <p>Çerez kullanmadan devam etmeyi seçerseniz, girişte kısa ömürlü URL tabanlı bir oturum belirteci kullanılacaktır. Bu, çerezlere göre daha az sorunsuz olabilir (devam etmek için bağlantılara tıklamanız gerekebilir), ancak tarayıcınızda hiçbir şey depolanmaz.</p>
    <p>JavaScript kullanılmamaktadır.</p>
    <p>
      <a href="<?= BASE_PATH ?>/landing.php?reject_cookies=1">Ana sayfaya çerez kullanmadan devam et</a>
    </p>
    <p>
      <a href="<?= BASE_PATH ?>/giris?reject_cookies=1">Giriş sayfasına çerez kullanmadan git</a>
    </p>
    <p style="font-size:12px;color:#666">Normal çerezli deneyime dönmek için bu sayfayı kapatıp normal giriş/çerez kabul akışını kullanabilirsiniz.</p>
  </div>
</body>
</html>