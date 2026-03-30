# Production Deployment Checklist

## ✅ Recent Updates (January 2026)

### User Approval System
- [x] `is_approved` column added to users table
- [x] New users require admin approval before posts appear publicly
- [x] "Yeni Gelen" (Rookie) badge for unapproved users
- [x] 10-post limit for unapproved users
- [x] Admin pending users interface (`admin/pending_users.php`)
- [x] Approval notification system
- [x] Timeline filtering (hide unapproved user posts)

### SMS Module
- [x] Modularized to `modules/sms/` directory
- [x] Optional feature (disabled by default)
- [x] Turkish SMS provider support (Netgsm, İletiMerkezi, Mutlucell)
- [x] Complete documentation in `modules/sms/`

### Responsive Design
- [x] Mobile-first CSS implementation
- [x] Android phone optimization (Galaxy A10, etc.)
- [x] iOS compatibility
- [x] Tablet responsive layout (769px-1024px)
- [x] Desktop responsive layout (1025px+)
- [x] Admin navigation mobile-friendly
- [x] Post cards responsive on all screens

### UI/UX Improvements
- [x] Post number moved to right lower corner
- [x] Proper spacing between elements
- [x] Touch-friendly navigation (mobile)
- [x] Horizontal scroll navigation (small phones)
- [x] Vertical menu stacking (admin panel mobile)

## ✅ Pre-Deployment Checklist

### Database
- [x] Database schema updated with `review_status` column
- [x] `approved_words` table created
- [x] Smart filtering system migrated
- [x] All indexes properly set
- [x] Foreign keys configured
- [x] Premium settings added (including `similarity_threshold`)
- [x] Email verification columns (`email_verified`, `verification_token`, `verification_token_expiry`)
- [x] Account management column (`is_active`)
- [x] **User approval system** (`is_approved` column)
- [x] **Rookie badge** created (ID: 4, slug: 'yeni-gelen')
- [x] All migrations tested and working

### Recent Migrations
- `add_user_approval.sql` - User approval system + Rookie badge
- `add_is_active_column.sql` - Account management
- `add_email_verification.sql` - Email verification
- `20260118_premium_system.sql` - Premium features
- `20260117_bad_words_migration.sql` - Bad words system
- `20260115_review_system.sql` - Content review system
- `20260115_badges_migration.sql` - Badge system
- `20260115_add_birthday_migration.sql` - Age verification

### Files & Code
- [x] All PHP files present (22 core files)
- [x] Admin panel complete (10 admin pages)
- [x] API endpoints functional (25+ API files)
- [x] Clean URL system implemented
- [x] Turkish language file complete
- [x] CSS files organized (7 CSS files)
- [x] Logo updated (green scale icon)
- [x] No test/debug files in production

### Features Implemented
- [x] User authentication & authorization
- [x] **Email verification** (mandatory on registration)
- [x] Post creation & replies (with auto-chunking)
- [x] Like/Unlike system
- [x] Follow/Unfollow system
- [x] Notifications (8 types)
- [x] Premium subscriptions
- [x] Badge system
- [x] Event system
- [x] Profile editing
- [x] **Account management** (disable/delete)
- [x] Search functionality
- [x] Report system
- [x] Smart word filtering (leet speak, reversals, separators)
- [x] Suspicious content review panel
- [x] Approved words whitelist
- [x] Word boundary censoring
- [x] CSRF protection on all forms
- [x] Rate limiting (login, registration)
- [x] Password strength validation
- [x] CAPTCHA system (no JavaScript, pure PHP+CSS)
- [x] Cookie compliance (GDPR)
- [x] Terms of Use & Privacy Policy

### Security
- [x] Password hashing (password_hash)
- [x] SQL injection protection (prepared statements)
- [x] XSS protection (htmlspecialchars)
- [x] CSRF tokens (sessions)
- [x] Admin-only routes protected
- [x] Input validation on all forms
- [x] Word censoring system
- [x] Suspicious content detection

## 📦 Deployment Steps

### 1. **Export Database**
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/textsocialmedia
# Database schema is already in database_schema.sql
```

### 2. **Prepare Configuration**

**Option A: Using Environment Variables (Recommended)**
1. Copy `.env.example` to `.env`
2. Update all values:
```bash
APP_ENV=production
DB_HOST=your_production_host
DB_NAME=your_production_db
DB_USER=your_production_user
DB_PASS=your_strong_password
BASE_PATH=
SITE_NAME=Your Site Name
MAIL_ENABLED=true
MAIL_FROM_EMAIL=no-reply@yourdomain.com
```

**Option B: Direct Configuration**
Update `includes/config.php` for production:
```php
define('ENVIRONMENT', 'production'); // Change from 'development'
define('DB_HOST', 'your_production_host');
define('DB_NAME', 'your_production_db');
define('DB_USER', 'your_production_user');
define('DB_PASS', 'your_production_password');
define('BASE_PATH', ''); // Root domain or '/subfolder'
define('MAIL_ENABLED', true);
define('MAIL_FROM_EMAIL', 'no-reply@yourdomain.com');
```

> **Tip:** You can also use the no-JavaScript web installer at `setup/install.php` to create the database (importing `database_schema.sql`), generate `includes/config.php`, and create an initial admin account. For security, run the installer on a private or trusted network and remove the `setup/` directory immediately after a successful installation.

**Important Security Steps:**
- [ ] Set `ENVIRONMENT` to `'production'`
- [ ] Use strong database password
- [ ] Enable error logging (already configured)
- [ ] Disable display_errors (already configured)
- [ ] Set proper file permissions (644 for files, 755 for directories)
- [ ] Protect `.env` file with `.htaccess`
- [ ] Generate new CSRF secrets

Stripe configuration (optional):
- Set environment variables: STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY
- For local testing (no live charges), you can set test keys instead: `STRIPE_TEST_SECRET_KEY` and `STRIPE_TEST_PUBLISHABLE_KEY`
- For webhooks, set `STRIPE_WEBHOOK_SECRET` (live) or `STRIPE_TEST_WEBHOOK_SECRET` (test) and configure endpoint to `/webhook/stripe.php`
- Install Stripe PHP SDK in your environment: `composer require stripe/stripe-php`
- Note: Without Stripe credentials the Stripe payment flow will be disabled and the site will show a helpful message to admins/users. In non-production environments the app will accept `STRIPE_TEST_*` keys to enable test-mode.

> **Tip:** You can optionally provide your Stripe keys during web installer (`setup/install.php`) and it will create/update a local `.env` file for you. For security, run the installer on a private/trusted network and remove the `setup/` directory immediately after a successful installation. Ensure `.env` is not committed to your repository.

IAP (in-app purchase) configuration & testing:
- Configure `IAP_API_TOKEN` (a secret used by native apps to call `POST /api/validate_iap.php`). Example: `export IAP_API_TOKEN=testtoken` on your local dev server.
- For local end-to-end testing without real store credentials, enable test mode: `IAP_TEST_MODE=1` and set `IAP_TEST_SUCCESS_TOKEN=TEST_SUCCESS` (or any token you like). Mobile wrappers can call the API with the test token to simulate a successful purchase.
- Mobile defaults for skeletons:
  - Deep link scheme: `myapp://` (sample deep link: `myapp://buy?plan=monthly`)
  - Android package id example: `com.example.webviewwrapper` (replace in your Android project)
  - iOS bundle id example: `com.example.webviewwrapper` (replace in your Xcode project)
- Admin token: set `ADMIN_API_TOKEN` on the server to allow CI and admin API calls (used by `api/admin_reverify_iap.php`). Example: `export ADMIN_API_TOKEN=admintoken`
- Run the integration tests locally: `scripts/run_integration_tests.sh` (requires `IAP_TEST_MODE=1`, `IAP_API_TOKEN` and `ADMIN_API_TOKEN` set).
- Google Play RTDN: set up `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` (either the path to the service account JSON file or the JSON blob) and configure `/webhook/google_play.php` as your RTDN endpoint. Ensure the service account has access to the Play Console for the app.
- App Store Server Notifications: configure `APPLE_KEY_ID`, `APPLE_ISSUER_ID`, and `APPLE_PRIVATE_KEY` and set `/webhook/apple.php` as the notification endpoint.
- Document sandbox credentials and test accounts for both stores (required for verification testing).

### 3. **Upload Files**
Upload entire project to server:
```
your-domain.com/
├── .htaccess
├── *.php (22 core files)
├── admin/ (10 files)
├── api/ (25+ files)
├── assets/ (CSS, logo.svg)
├── includes/ (6 core files)
├── lang/ (2 language files)
├── migrations/ (all migration files)
├── templates/ (4 template files)
└── docs/ (documentation)
```

### 4. **Import Database**
On production server:
```sql
-- Import the complete schema
SOURCE database_schema.sql;

-- Verify tables
SHOW TABLES;

-- Create first admin user
INSERT INTO users (username, email, password_hash, role, created_at) 
VALUES ('admin', 'admin@yourdomain.com', '$2y$10$...', 'admin', NOW());
```

### 5. **Set Permissions**
```bash
chmod 755 /path/to/project
chmod 644 /path/to/project/*.php
chmod 644 /path/to/project/includes/config.php
```

### 6. **Configure Apache**
Enable mod_rewrite and set up virtual host:
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/yourdomain
    
    <Directory /var/www/html/yourdomain>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Redirect to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/yourdomain
    
    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem
    
    <Directory /var/www/html/yourdomain>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 7. **SSL Certificate**
```bash
# Using Let's Encrypt
certbot --apache -d yourdomain.com -d www.yourdomain.com
```

### 8. **Test Clean URLs**
Visit these URLs to verify:
- `/anasayfa` → index.php ✓
- `/bildirimler` → notification.php ✓
- `/etkinlikler` → events.php ✓
- `/username` → profile.php?username=username ✓
- `/post/123` → post.php?id=123 ✓

## 🎯 Post-Deployment Checklist

### Immediate Tasks
- [ ] Create first admin user
- [ ] Test login/registration
- [ ] Test post creation
- [ ] Test notifications
- [ ] Add initial bad words to database
- [ ] Test smart filtering system
- [ ] Configure premium pricing
- [ ] Test all admin panels
- [ ] Add scheduled GitHub Actions workflow to run daily IAP health check and integration tests
- [ ] Schedule reverify cron to run `scripts/reverify_pending_iap.php` (every 6 hours recommended)

### Run Tests
- Run the built-in Stripe handler test to validate webhook handling (no Stripe creds required):
```bash
php scripts/run_tests.sh
```
This will create a temporary test user, invoke the `stripe_handle_event()` handler with a simulated `checkout.session.completed` payload, and verify the `premium_subscriptions` row is created. It will clean up after itself.```

### Security Hardening
- [ ] Enable HTTPS everywhere
- [ ] Set secure session cookies
- [ ] Configure CORS if needed
- [ ] Set up database backups
- [ ] Configure error logging (not display)
- [ ] Remove setup.php after setup
- [ ] Change default admin password

### Monitoring Setup
- [ ] Set up error logging
- [ ] Monitor pending review queue
- [ ] Track premium subscriptions
- [ ] Monitor suspicious content detection
- [ ] Set up automated backups

## 📊 Database Statistics
- **Total Tables:** 14
- **User System:** users, follows
- **Content:** posts, likes, post_edits
- **Notifications:** notifications
- **Moderation:** reports, bad_words, approved_words
- **Premium:** premium_subscriptions, premium_settings, user_custom_badges
- **Badges:** badges, user_badges
- **Events:** events

## 🔧 Smart Filtering Configuration

### Default Settings
- Similarity Threshold: 75%
- Minimum Word Length: 4 characters
- Detection Methods:
  - Substring matching
  - Levenshtein similarity
  - Leet speak normalization
  - Separator removal
  - Character repetition handling
  - Reversed word detection

### Adjust Threshold (if needed)
```sql
UPDATE premium_settings 
SET setting_value = '80' 
WHERE setting_key = 'similarity_threshold';
```

## 📱 Production URLs

### User-Facing
- Homepage: `/anasayfa`
- Notifications: `/bildirimler`
- Events: `/etkinlikler`
- Search: `/ara`
- Premium: `/premium`
- Rules: `/kurallar`

### Admin Panel
- Dashboard: `/admin/index.php`
- User Approvals: `/admin/users.php`
- Reports: `/admin/reports.php`
- **Suspicious Content:** `/admin/pending_review.php`
- **Whitelist:** `/admin/approved_words.php`
- Bad Words: `/admin/badwords.php`
- Badges: `/admin/badges.php`
- Premium: `/admin/premium_users.php`
- Events: `/admin/events.php`

## 🚀 Quick Start Commands

```bash
# 1. Upload files
scp -r textsocialmedia/ user@server:/var/www/html/

# 2. Import database
mysql -u root -p production_db < database_schema.sql

# 3. Set permissions
chmod -R 755 /var/www/html/textsocialmedia
chmod 644 /var/www/html/textsocialmedia/includes/config.php

# 4. Restart Apache
systemctl restart apache2

# 5. Test
curl https://yourdomain.com/anasayfa
```

## ✨ New Features in This Version

1. **Smart Word Filtering**
   - Detects leet speak (s1k, s!k, $ik)
   - Catches reversed words (kis → sik)
   - Removes separators (s-i-k, s.i.k)
   - Handles repetition (siiik, sikkkk)
   - Substring matching (sikiminiki contains sik)

2. **Admin Review System**
   - Pending content review panel
   - Similarity percentage display
   - One-click approval with whitelisting
   - Automatic learning system

3. **Enhanced UI**
   - Green scale logo (balance/moderation theme)
   - Gray header design
   - Green button theme
   - Improved notification page layout 

## 📞 Support & Troubleshooting

If clean URLs don't work:
1. Check mod_rewrite: `a2enmod rewrite`
2. Verify .htaccess: `AllowOverride All`
3. Check BASE_PATH in config.php
4. Test: `http://localhost/textsocialmedia/index.php` should work

If smart filtering doesn't detect:
1. Verify approved_words table exists
2. Check similarity_threshold setting
3. Ensure bad words are longer than 3 characters
4. Check migration ran successfully

---

**Deployment Date:** January 15, 2026
**Version:** 2.0 (Smart Filtering System)
**Status:** Production Ready ✅
