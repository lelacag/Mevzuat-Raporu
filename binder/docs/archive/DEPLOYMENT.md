# Deployment Guide - Social Media Platform

## Overview
This is a complete deployment guide for the social media platform with purple classic Facebook design, clean URLs, and all features.

## Requirements

### Server Requirements
- PHP 7.4 or higher (8.0+ recommended)
- MySQL 5.7 or higher / MariaDB 10.2 or higher
- Apache with mod_rewrite enabled
- HTTPS enabled (recommended for production)

### PHP Extensions Required
- PDO
- PDO_MySQL
- mbstring
- session

## Deployment Steps

### 1. Prepare the Files

Upload all files to your web server:
```
/var/www/html/your-domain/
├── .htaccess
├── index.php
├── login.php
├── register.php
├── profile.php
├── post.php
├── admin/
├── api/
├── assets/
├── includes/
├── lang/
├── templates/
└── database_schema.sql
```

### 2. Configure Database

#### Edit `includes/config.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

define('SITE_NAME', 'YourSiteName');
define('BASE_PATH', ''); // For root: '' or '/folder' for subdirectory
define('TIMEZONE', 'Europe/Istanbul'); // Your timezone
define('DEFAULT_LANG', 'tr'); // tr or en

// Security
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 days
define('MAX_POST_LENGTH', 5000);
define('MAX_BIO_LENGTH', 500);
define('POSTS_PER_PAGE', 50);
?>
```

### 3. Import Database

Run the complete database schema:

```bash
mysql -u your_user -p your_database < database_schema.sql
```

Or import via phpMyAdmin:
1. Login to phpMyAdmin
2. Select your database
3. Click "Import"
4. Choose `database_schema.sql`
5. Click "Go"

---

### 3.1 Apply New Feature Migrations (Tests / Polls / Slugs)

This project adds several new features that require schema migrations and a short backfill step to ensure SEO-friendly slugs and edit timestamps are present.

Recommended order (run these in your database):

```bash
# Run schema migrations (examples; adjust DB connection as needed)
mysql -u your_user -p your_database < migrations/20260211_add_tests.sql
mysql -u your_user -p your_database < migrations/20260211_add_polls.sql
mysql -u your_user -p your_database < migrations/20260212_add_slugs_tests_polls.sql
mysql -u your_user -p your_database < migrations/20260212_add_updated_at_tests.sql
```

Backfill existing rows (generate slugs for older records):

```bash
# Backfill slugs (PHP CLI or run via web-admin script)
php scripts/backfill_slugs.php
```

Notes:
- If your environment does not have `php` CLI available, you can run the SQL produced by the script manually or run the script via an admin-only web endpoint (ask your admin to enable temporarily).
- The `updated_at` migration adds an `updated_at` column to `tests` and it is set automatically on updates (ON UPDATE CURRENT_TIMESTAMP).
- These migrations are defensive — code checks whether columns exist before trying to update slugs or timestamps to avoid runtime SQL errors.

---

### 4. Set File Permissions

### 4.1 No-JS mode and cleaning client scripts

Recent updates remove runtime client-side JavaScript/JSON for important features (cookie notice, dev debug HUD, token revoke flows). The app now prefers pure server-side HTML/PHP flows for functionality and accessibility.

Recommended deploy tasks:

- Remove or do not deploy unnecessary client-side scripts under `assets/js/` and JSON files under `assets/` or `scripts/` unless you intentionally rely on them.

```bash
# Example: remove the cookie-notice JS placeholder (it's intentionally left empty in repo)
rm -f /var/www/html/your-domain/assets/js/cookie-notice.js
```

- Dev/debug HTML files under `scripts/tmp-smoketest-logs/` have been converted to no-JS flows where possible; keep or remove these files in production as appropriate.

- Admin helper pages added:
  - `admin/revoke_url_session.php` — revoke dev url-sessions via a server-side confirm form (dev/admin only).

### 5. Finalize installation (cleanup client scripts & debug files)

After migrations and backfills are applied, remove client-side JS and dev-only debugging artifacts before moving to production. A helper script is provided:

```bash
# Dry-run (lists files that would be removed)
./scripts/cleanup_remove_js.sh

# Perform actual deletions
./scripts/cleanup_remove_js.sh --yes
```

- The script removes `assets/js/*.js`, `assets/*.json`, `scripts/*.json` and clears `scripts/tmp-smoketest-logs/` by default.
- Verify functionality after cleanup (cookie notice acceptance via POST, admin revoke via `admin/revoke_url_session.php`, events page loads correctly).
- If you need to preserve any specific JS file, copy it out before running the script.



### 5. Set File Permissions

```bash
# Set proper ownership
chown -R www-data:www-data /var/www/html/your-domain/

# Set directory permissions
find /var/www/html/your-domain/ -type d -exec chmod 755 {} \;

# Set file permissions
find /var/www/html/your-domain/ -type f -exec chmod 644 {} \;

# Make setup.php executable (if you want to run it)
chmod 755 /var/www/html/your-domain/setup.php
```

### 5. Apache Configuration

Enable mod_rewrite:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Configure VirtualHost (optional but recommended):
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/html/your-domain

    <Directory /var/www/html/your-domain>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/your-domain-error.log
    CustomLog ${APACHE_LOG_DIR}/your-domain-access.log combined
</VirtualHost>
```

For HTTPS (recommended):
```bash
sudo certbot --apache -d your-domain.com -d www.your-domain.com
```

### 6. Create First Admin User

1. Register a user through the web interface: `https://your-domain.com/register.php`
2. Manually promote to admin via MySQL:

```sql
UPDATE users SET role = 'admin' WHERE username = 'your_username';
```

Or via command line:
```bash
mysql -u your_user -p your_database -e "UPDATE users SET role = 'admin' WHERE username = 'your_username';"
```

### 7. Configure Email Notifications (Optional)

If using email notifications, configure PHP mail or SMTP in your server's php.ini:

```ini
[mail function]
SMTP = smtp.your-provider.com
smtp_port = 587
sendmail_from = noreply@your-domain.com
```

### 8. Security Checklist

- [ ] Change all default database credentials
- [ ] Enable HTTPS with valid SSL certificate
- [ ] Set secure session settings in php.ini:
  ```ini
  session.cookie_secure = 1
  session.cookie_httponly = 1
  session.cookie_samesite = "Strict"
  ```
- [ ] Disable directory listing (already in .htaccess)
- [ ] Set proper file permissions (755 for directories, 644 for files)
- [ ] Remove or secure setup.php after initial setup
- [ ] Configure firewall to allow only ports 80 and 443
- [ ] Set up regular database backups
- [ ] Configure fail2ban for brute force protection

### 9. Clean URLs Configuration

The `.htaccess` file is already configured for clean URLs:

**Clean URL Examples (enable via env or constant):**
- `domain.com/username` → User profile
- `domain.com/username/123` → User post (SEO-friendly)
- `domain.com/post/123` → Numeric post URL fallback
- `domain.com/username/followers` → User's followers
- `domain.com/username/following` → User's following
- `domain.com/g/groupname` → Group page (preferred)
- `domain.com/g/groupname/post/123` → Group post

**Legacy URLs Still Work:**
- `domain.com/profile.php?username=john`
- `domain.com/post.php?id=123`

**How to enable:**
Set an environment variable `USE_CLEAN_URLS=1` or define `USE_CLEAN_URLS` in `includes/config.php` to `true`. Ensure Apache's `mod_rewrite` is enabled and `.htaccess` is present in the webroot. After enabling, verify that URLs like `/username/123` correctly resolve to posts and that canonical links on post pages point to the new format.
### 10. Testing

Test all features:
- [ ] User registration and login
- [ ] Post creation and display
- [ ] Like functionality
- [ ] Reply functionality
- [ ] Follow/unfollow
- [ ] Notifications
- [ ] Admin panel access
- [ ] Premium features
- [ ] Events (premium)
- [ ] Badge system
- [ ] Clean URLs (test username links)
- [ ] Report system
- [ ] Search functionality

### 11. Performance Optimization

**Enable PHP OPcache** (php.ini):
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

**Enable Gzip Compression** (already in .htaccess):
- CSS, JS, HTML files are compressed
- Reduces bandwidth usage

**Database Optimization:**
```sql
-- Analyze tables
ANALYZE TABLE users, posts, likes, follows, notifications;

-- Optimize tables
OPTIMIZE TABLE users, posts, likes, follows, notifications;
```

### 12. Backup Strategy

**Database Backup** (daily cron job):
```bash
#!/bin/bash
mysqldump -u your_user -p'your_password' your_database > /backups/db-$(date +%Y%m%d).sql
# Keep only last 30 days
find /backups/ -name "db-*.sql" -mtime +30 -delete
```

**File Backup:**
```bash
tar -czf /backups/files-$(date +%Y%m%d).tar.gz /var/www/html/your-domain/
```

### 13. Monitoring

**Server Monitoring:**
- Monitor disk space
- Monitor MySQL performance
- Monitor Apache logs
- Set up uptime monitoring (Pingdom, UptimeRobot)

**Application Monitoring:**
- Check error logs: `/var/log/apache2/error.log`
- Monitor database size growth
- Track user registrations
- Monitor reported content

## Troubleshooting

### Clean URLs Not Working
1. Check if mod_rewrite is enabled: `apache2ctl -M | grep rewrite`
2. Verify .htaccess is in the root directory
3. Check Apache AllowOverride is set to "All"
4. Check file permissions on .htaccess (644)

### Database Connection Errors
1. Verify database credentials in `includes/config.php`
2. Check if MySQL is running: `systemctl status mysql`
3. Test connection: `mysql -u your_user -p`
4. Check user permissions in MySQL

### 500 Internal Server Error
1. Check Apache error log: `tail -f /var/log/apache2/error.log`
2. Check PHP error log
3. Verify file permissions
4. Check .htaccess syntax

### Session Issues
1. Check session directory permissions
2. Verify session settings in php.ini
3. Clear browser cookies
4. Check session.save_path is writable

## Production URLs

Once deployed, your site will have clean URLs:

- Homepage: `https://your-domain.com`
- User Profile: `https://your-domain.com/username`
- Post Detail: `https://your-domain.com/post/123`
- Login: `https://your-domain.com/login.php`
- Register: `https://your-domain.com/register.php`
- Admin Panel: `https://your-domain.com/admin/`
- Premium: `https://your-domain.com/premium.php`
- Events: `https://your-domain.com/events.php`

## Support

For issues or questions, refer to:
- Documentation in `/docs/` folder
- Database schema: `database_schema.sql`
- Migration files in `/migrations/`

## Version

- Version: 1.0.0
- Last Updated: January 14, 2026
- Database Schema: Complete (all migrations included)
- Clean URLs: Enabled
- Theme: Purple Classic Facebook
