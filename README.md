# Mevzuat Raporu

**Reklam, takip mekanizmaları ve veri madenciliği barındırmayan saf bir sosyal deneyimi. HTTPS protokolü ile verilerinizi güvence altına alırken, kullanıcıları izlemeyen yapısıyla gizliliğe önem veren yapısı vardır.**

Paylaşım, gruplar, etkinlikler, takip sistemi ve bildirimlerle mevzuat ve kamu gündemini bir araya getiren sunucu taraflı bir PHP uygulaması.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-uyumlu-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Lisans](https://img.shields.io/badge/Lisans-MIT-green.svg)](LICENSE)
[![Canlı](https://img.shields.io/badge/Canlı-mevzuatraporu.com-0B6E4F)](https://www.mevzuatraporu.com)

---

## Neden Mevzuat Raporu?

- **Hızlı ve sade** — Sayfalar sunucuda üretilir, ilk yükleme hafiftir.
- **Topluluk odaklı** — Gönderi, yanıt, beğeni, favori ve takip akışları hazır.
- **Gruplar & etkinlikler** — İlgi grubu ve etkinlik yönetimi tek platformda.
- **Yönetim paneli** — Kullanıcı onayı, raporlar, premium, rozetler ve denetim araçları.
- **KVKK bilinci** — Gizlilik, çerez ve veri talebi akışları için temel sayfalar mevcut.
- **Genişletilebilir** — `modules/` altında tercihi özellikler; çekirdek kod sade kalır.

---

## Öne çıkan özellikler

| Alan | Neler var? |
|------|------------|
| **Sosyal** | Gönderiler, yanıtlar, beğeniler, favoriler, takipçi / takip edilen |
| **Gruplar** | Grup oluşturma, üyelik, grup gönderileri ve yorumlar |
| **Etkinlikler** | Etkinlik sayfaları, katılım, güncelleme ve yorumlar |
| **İçerik** | Anketler, fotoğraflar, arama, RSS / Atom |
| **Hesap** | Kayıt, e-posta doğrulama, şifre sıfırlama, profil düzenleme |
| **Premium** | Abonelik / ödeme iskeleti (Stripe & IAP uçları) | (Devreye Alım Aşaması)
| **Yönetim** | Admin paneli, rapor çözümleme, IP engeli, rozet ve rol yönetimi |
| **Güvenlik** | Captcha, oturum koruması, gizli anahtarlar ortam değişkenleriyle |

---

## Teknoloji yığını

- **Dil:** PHP 7.4+
- **Veritabanı:** MySQL / MariaDB
- **Sunucu:** Apache (üretim) veya Nginx + PHP-FPM
- **Önyüz:** Sunucu taraflı HTML + hafif CSS / JS
- **Yapı:** `includes/`, `api/`, `modules/`, `src/`, `templates/`, `assets/`

---

## Depo yapısı

```text
.
├── admin/           # Yönetim paneli
├── api/             # HTTP API uçları
├── assets/          # CSS, ikon ve görseller
├── includes/        # Yapılandırma, oturum, yardımcılar
├── modules/         # Opsiyonel / siteye özel eklentiler
├── src/             # Yönlendirme ve servis katmanı
├── templates/       # HTML parçaları
├── webhook/         # Stripe / mağaza bildirim uçları
├── .env.example     # Ortam değişkeni şablonu
├── index.php        # Giriş noktası
└── README.md
```

> Not: Üretimde web kökünü bu depo köküne (veya kopyaladığınız uygulama dizinine) yönlendirin.

---

## Hızlı başlangıç

### 1) Depoyu alın

```bash
git clone https://github.com/lelacag/Mevzuat-Raporu.git
cd Mevzuat-Raporu
```

### 2) Ortam dosyasını hazırlayın

```bash
cp .env.example .env
```

`.env` içinde en azından şunları doldurun:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `SITE_URL` (ör. `http://localhost` veya canlı alan adınız)
- Gizli anahtarlar: `URL_SESSION_SECRET`, `APP_ENC_KEY`, `APP_SIGN_KEY`

### 3) Veritabanı

1. MySQL / MariaDB’de bir veritabanı oluşturun.
2. Şema dosyanız varsa içe aktarın (geçmiş sürümlerde `database_schema.sql` kullanıldı).
3. PHP-FPM / Apache-Nginx vhost’unun proje kökünü gösterdiğinden emin olun.

### 4) Bağımlılıklar

`vendor/` bu depoda bilerek yok sayılır. Ortamınıza göre Composer veya mevcut PHP kütüphanelerinizi kurun (ör. PHPMailer, Stripe SDK).

### 5) Çalıştırın

Belge kökü = depo kökü olacak şekilde sanal host tanımlayın, ardından tarayıcıdan `SITE_URL` adresine gidin.

---

## Yapılandırma ipuçları

| Anahtar | Açıklama |
|---------|----------|
| `APP_ENV` | `development` / üretim ortamı ayrımı |
| `USE_CLEAN_URLS` | Temiz URL kullanımı (`1` önerilir) |
| `MAIL_ENABLED` | SMTP ile e-posta gönderimi |
| `IAP_TEST_MODE` | Mağaza / abonelik test modu |

**Gizli bilgileri asla commit etmeyin.** `.env`, `.htaccess` ve gerçek anahtarlar `.gitignore` ile dışarıda bırakılır.

---

## Geliştirme önerileri

- Yeni özellikleri mümkünse `modules/` altına koyun; kaldırmak kolay olsun.
- Commit öncesi sözdizimi kontrolü: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
- Admin ve API değişikliklerinde CSRF / yetki kontrollerini koruyun.
- Canlıya almadan önce staging’de duman testi yapın.

---

## Sürüm notları (özet)

**v1.1 ve sonrası (geçmiş):**

- Kalıcı sistem kullanıcısı ve otomatik karşılama / bildirim tetikleyicileri
- Kullanıcı onay akışında sistem mesajları
- Mimari iyileştirmeler, CSRF sertleştirme, performans ve operasyon adımları

Güncel `main` ucu, sunucu tarafındaki uygulama ağacının güncel bir anlık görüntüsünü içerir; önceki mimari commit’ler geçmişte durur.

---

## Katkı

1. Fork / branch açın (`feature/...` veya `fix/...`)
2. Değişikliklerinizi net commit mesajlarıyla ekleyin
3. PR açın ve neyi neden değiştirdiğinizi kısaca yazın

Hata bildirimi ve fikirler için GitHub Issues kullanabilirsiniz.

---

## Lisans

Bu proje [MIT License](LICENSE) ile sunulur.  
Telif: © 2026 Çağlar Araz

---

## İletişim

- **Canlı site:** [www.mevzuatraporu.com](https://www.mevzuatraporu.com)
- **Depo:** [github.com/lelacag/Mevzuat-Raporu](https://github.com/lelacag/Mevzuat-Raporu)
- **Destek e-posta:** admin@mevzuatraporu.com

---

<p align="center">
  <b>Mevzuatı takip et, topluluğa katıl, gündemi birlikte raporla.</b><br/>
  <sub>Mevzuat Raporu ile daha şeffaf bir dijital kamu alanı.</sub>
</p>
