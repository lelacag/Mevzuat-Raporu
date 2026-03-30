# 🚀 Final Deployment Guide - Mevzuat Raporu

## Pre-Deployment Summary

### ✅ System Status
- **Application**: Production-ready
- **Database**: All migrations completed
- **Security**: CSRF, XSS, SQL injection protected
- **Responsive**: Mobile, tablet, desktop optimized
- **Features**: All core features implemented and tested

### 📋 Recent Implementations (January 2026)
1. **User Approval System**
   - New users require admin approval
   - Rookie badge for unapproved users
   - 10-post limit before approval
   - Admin interface: `admin/pending_users.php`

2. **Responsive Design**
   - Mobile-first approach
   - Android optimization (Galaxy A10 tested)
   - Tablet layouts (769px-1024px)
   - Desktop responsive (1025px+)

3. **SMS Module** (Optional)
   - Modularized in `modules/sms/`
   - Turkish provider support
   - Disabled by default

## 🔧 Deployment Steps

### Step 1: Server Requirements
```
- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- SSL certificate (HTTPS recommended)
- Minimum 256MB PHP memory_limit
- File upload support (for future features)
```

### Step 2: Upload Files
Upload entire project structure:
```
/var/www/html/
├── .htaccess (Apache rewrite rules)
├── .env (copy from .env.example, configure)
├── index.php
├── login.php, register.php, etc. (all root PHP files)
├── admin/ (full directory)
├── api/ (full directory)
├── assets/ (full directory)
├── includes/ (full directory)
├── lang/ (full directory)
├── migrations/ (for reference only)
├── modules/ (optional SMS module)
├── templates/ (full directory)
└── logs/ (create directory, chmod 777)
```

### Step 3: Configure Environment

**Create `.env` file** (from `.env.example`):
```env
APP_ENV=production
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_db_user
DB_PASS=your_strong_password
BASE_PATH=
SITE_NAME=Mevzuat Raporu
MAIL_ENABLED=true
MAIL_FROM_EMAIL=no-reply@yourdomain.com
```

**OR manually edit `includes/config.php`:**
```php
define('ENVIRONMENT', 'production'); // CRITICAL!
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('BASE_PATH', ''); // empty for root domain
define('MAIL_ENABLED', true);
```

### Step 4: Database Setup

**Import complete schema:**
```bash
mysql -u your_user -p your_database < database_schema.sql
```

**Run all migrations in order:**
```bash
cd migrations
mysql -u your_user -p your_database < 20260115_badges_migration.sql
mysql -u your_user -p your_database < 20260115_review_system.sql
mysql -u your_user -p your_database < 20260116_add_mention_migration.sql
mysql -u your_user -p your_database < 20260117_add_censored_flag.sql
mysql -u your_user -p your_database < 20260117_bad_words_migration.sql
mysql -u your_user -p your_database < 20260118_premium_system.sql
mysql -u your_user -p your_database < 20260115_add_birthday_migration.sql
mysql -u your_user -p your_database < add_email_verification.sql
mysql -u your_user -p your_database < add_is_active_column.sql
mysql -u your_user -p your_database < add_user_approval.sql
```

**Verify tables exist:**
```sql
SHOW TABLES;
-- Should show: users, posts, likes, follows, notifications, reports, 
-- badges, user_badges, premium_settings, events, approved_words, etc.
```

### Step 5: Create Admin User

```sql
-- Create first admin user (change password!)
INSERT INTO users (username, password_hash, email, email_verified, is_approved, role, created_at)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
        'admin@yourdomain.com', 1, 1, 'admin', NOW());
-- Password is 'password' - CHANGE THIS IMMEDIATELY!
```

**Then login and change password via Profile Edit.**

### Step 6: File Permissions

```bash
# Set proper permissions
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

# Logs directory needs write permission
chmod 777 /var/www/html/logs

# Protect .env file
chmod 600 /var/www/html/.env

# Make sure Apache can read
chown -R www-data:www-data /var/www/html
```

### Step 7: Apache Configuration

**Ensure `.htaccess` is working:**
```apache
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
```

**Enable mod_rewrite:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Step 8: SSL/HTTPS Setup

**Using Let's Encrypt (Recommended):**
```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com
```

**Force HTTPS in `.htaccess`** (add at top):
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Step 9: Security Hardening

**1. Protect sensitive files (.htaccess):**
```apache
# Protect .env file
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

# Protect config files
<FilesMatch "(config\.php|db\.php|auth\.php)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

**2. Verify error logging:**
```bash
# Check logs directory exists
mkdir -p logs
touch logs/php_errors.log
chmod 666 logs/php_errors.log
```

**3. Test error handling:**
- Visit non-existent page → should show user-friendly error
- Check `logs/php_errors.log` for detailed errors

### Step 10: Post-Deployment Testing

**Critical Tests:**
- [ ] Registration works (email verification sent)
- [ ] Login/logout functions
- [ ] Post creation (unapproved user sees own posts)
- [ ] Admin can approve new users
- [ ] Timeline shows only approved user posts
- [ ] Rookie badge assigned to new users
- [ ] Rookie badge removed after approval
- [ ] Like/unlike works
- [ ] Follow/unfollow works
- [ ] Notifications sent
- [ ] Search functions
- [ ] Profile editing works
- [ ] Admin panel accessible
- [ ] Responsive on mobile (test Android & iOS)
- [ ] Responsive on tablet
- [ ] Responsive on desktop

**Performance Tests:**
- [ ] Page load time < 2 seconds
- [ ] Database queries optimized
- [ ] No N+1 query issues
- [ ] CSS/JS files loading

**Security Tests:**
- [ ] CSRF protection working (try form submission without token)
- [ ] SQL injection blocked (try ' OR '1'='1)
- [ ] XSS blocked (try <script> in posts)
- [ ] Admin pages require admin role
- [ ] Rate limiting works (try 6+ failed logins)

## 🔒 Production Security Checklist

- [ ] `ENVIRONMENT` set to `'production'`
- [ ] `display_errors` off (check `includes/config.php`)
- [ ] Error logging enabled to `logs/php_errors.log`
- [ ] Strong database password (12+ characters, mixed)
- [ ] HTTPS enabled with valid SSL certificate
- [ ] `.env` file protected (chmod 600)
- [ ] Database user has minimum required privileges
- [ ] Regular backups scheduled
- [ ] File permissions correct (644/755, not 777)
- [ ] Apache/Nginx security headers configured
- [ ] PHP version up to date
- [ ] MySQL/MariaDB version up to date

## 📊 Monitoring & Maintenance

### Daily Checks
- Monitor `logs/php_errors.log` for errors
- Check pending user approvals (`admin/pending_users.php`)
- Review reports (`admin/reports.php`)
- Check suspicious content (`admin/pending_review.php`)

### Weekly Tasks
- Database backup
- Review admin activity
- Check disk space
- Monitor performance

### Monthly Tasks
- Update bad words list
- Review premium subscriptions
- Audit user accounts
- Check SSL certificate expiry

## 🆘 Troubleshooting

### Common Issues

**Issue: White screen after deployment**
- Check `logs/php_errors.log`
- Verify database connection in `includes/config.php`
- Ensure all migrations ran successfully
- Check file permissions

**Issue: 404 errors on all pages**
- Enable mod_rewrite: `sudo a2enmod rewrite`
- Check `.htaccess` exists and is readable
- Verify `AllowOverride All` in Apache config

**Issue: Database connection failed**
- Verify credentials in `includes/config.php` or `.env`
- Check MySQL is running: `sudo systemctl status mysql`
- Test connection: `mysql -u user -p database`

**Issue: Email not sending**
- Check `MAIL_ENABLED` is `true`
- Verify `MAIL_FROM_EMAIL` is configured
- Check PHP mail() function works
- Review server mail logs

**Issue: Mobile layout broken**
- Clear browser cache
- Check responsive CSS loaded (`assets/css/main.css`)
- Test viewport meta tag in `includes/header.php`
- Use browser dev tools to inspect

## 📞 Support

- Documentation: `/docs/` directory
- Deployment Checklist: `DEPLOYMENT_CHECKLIST.md`
- Security Guide: `PRODUCTION_SECURITY.md`
- Production Ready: `PRODUCTION_READY.md`

## 🎉 Launch Ready!

Your application is now production-ready with:
- ✅ User approval system
- ✅ Complete responsive design
- ✅ Security hardening
- ✅ Email verification
- ✅ Content moderation
- ✅ Admin panel
- ✅ Premium features
- ✅ Mobile optimization

**Good luck with your launch! 🚀**
