# Production Deployment Security Guide

## 🔒 Pre-Deployment Security Setup

### 1. Environment Configuration

**Create `.env` file on production server:**
```bash
cp .env.example .env
nano .env
```

**Production `.env` settings:**
```env
APP_ENV=production

DB_HOST=localhost
DB_NAME=your_production_db
DB_USER=your_db_user
DB_PASS=your_strong_password_here_20plus_chars

BASE_PATH=
SITE_NAME="Your Site Name"

MAIL_ENABLED=true
MAIL_FROM_EMAIL=noreply@yourdomain.com
```

### 2. File Permissions

```bash
# Navigate to project directory
cd /var/www/html/yourdomain

# Set correct ownership
chown -R www-data:www-data .

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;

# Protect logs directory
chmod 750 logs/
chmod 640 logs/.htaccess

# Protect config
chmod 640 includes/config.php

# Make setup unexecutable after first run
chmod 000 setup.php
# OR delete it: rm setup.php
```

### 3. PHP Configuration

**Edit `/etc/php/8.x/apache2/php.ini` (or your PHP config):**

```ini
; Disable error display
display_errors = Off
display_startup_errors = Off

; Enable error logging
log_errors = On
error_log = /var/log/php/error.log

; Security
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
session.use_only_cookies = 1

; Upload limits
upload_max_filesize = 2M
post_max_size = 8M
max_execution_time = 30
max_input_time = 60
memory_limit = 128M
```

### 4. Apache Configuration

**Create or edit virtual host:**

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    
    # Redirect all HTTP to HTTPS
    Redirect permanent / https://yourdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/html/yourdomain
    
    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem
    
    # Modern SSL configuration
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5:!3DES
    SSLHonorCipherOrder on
    
    # HSTS (uncomment after testing)
    # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    
    <Directory /var/www/html/yourdomain>
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted
        
        # Disable server signature
        ServerSignature Off
    </Directory>
    
    # Protect sensitive directories
    <DirectoryMatch "^/.*/\.(git|svn|env)">
        Require all denied
    </DirectoryMatch>
    
    # Protect sensitive files
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    
    # Error and access logs
    ErrorLog ${APACHE_LOG_DIR}/yourdomain_error.log
    CustomLog ${APACHE_LOG_DIR}/yourdomain_access.log combined
</VirtualHost>
```

### 5. MySQL Security

```sql
-- Create dedicated database user with minimal privileges
CREATE USER 'app_user'@'localhost' IDENTIFIED BY 'strong_password_here';

-- Grant only necessary privileges
GRANT SELECT, INSERT, UPDATE, DELETE ON textsocialmedia.* TO 'app_user'@'localhost';
FLUSH PRIVILEGES;

-- Never use root user in production!
```

**Edit MySQL config `/etc/mysql/mysql.conf.d/mysqld.cnf`:**
```ini
[mysqld]
# Bind to localhost only
bind-address = 127.0.0.1

# Enable slow query log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 2
```

### 6. Firewall (UFW)

```bash
# Enable firewall
ufw enable

# Allow SSH (change port if using non-standard)
ufw allow 22/tcp

# Allow HTTP and HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Deny all other incoming
ufw default deny incoming
ufw default allow outgoing

# Check status
ufw status verbose
```

### 7. Fail2Ban (Brute Force Protection)

```bash
# Install fail2ban
apt-get install fail2ban

# Create Apache auth jail
cat > /etc/fail2ban/jail.d/apache-auth.conf << EOF
[apache-auth]
enabled = true
port = http,https
filter = apache-auth
logpath = /var/log/apache2/*error.log
maxretry = 5
bantime = 3600
findtime = 600
EOF

# Restart fail2ban
systemctl restart fail2ban
```

### 8. SSL Certificate (Let's Encrypt)

```bash
# Install certbot
apt-get install certbot python3-certbot-apache

# Get certificate
certbot --apache -d yourdomain.com -d www.yourdomain.com

# Auto-renewal is configured automatically
# Test renewal:
certbot renew --dry-run
```

### 9. Database Backups

**Create backup script `/usr/local/bin/backup-db.sh`:**

```bash
#!/bin/bash
BACKUP_DIR="/backups/mysql"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="textsocialmedia"
DB_USER="backup_user"
DB_PASS="backup_password"

mkdir -p $BACKUP_DIR
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep only last 7 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +7 -delete
```

**Make executable and add to cron:**
```bash
chmod +x /usr/local/bin/backup-db.sh

# Add to crontab (daily at 2 AM)
crontab -e
0 2 * * * /usr/local/bin/backup-db.sh
```

### 10. Monitoring Setup

**Install monitoring tools:**
```bash
# System monitoring
apt-get install htop iotop nethogs

# Log monitoring
apt-get install logwatch

# Configure logwatch to send daily reports
cat > /etc/cron.daily/00logwatch << EOF
#!/bin/bash
/usr/sbin/logwatch --output mail --mailto admin@yourdomain.com --detail high
EOF
chmod +x /etc/cron.daily/00logwatch
```

### 11. Security Headers (.htaccess)

Your `.htaccess` should include:

```apache
<IfModule mod_headers.c>
    # Prevent clickjacking
    Header always set X-Frame-Options "SAMEORIGIN"
    
    # Prevent MIME sniffing
    Header always set X-Content-Type-Options "nosniff"
    
    # Enable XSS filter
    Header always set X-XSS-Protection "1; mode=block"
    
    # Referrer policy
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    # Content Security Policy
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;"
    
    # HSTS (uncomment after testing HTTPS)
    # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
</IfModule>
```

---

## 🚀 Recommended Hosting Platforms

### 1. **DigitalOcean App Platform** ⭐⭐⭐⭐⭐
**Best for: Managed hosting with security**

**Pricing:** $12-25/month  
**Security Features:**
- Automated SSL/TLS
- DDoS protection
- Managed MySQL with automated backups
- Web Application Firewall (WAF)
- Auto-scaling
- SOC 2 Type II compliance

**Setup:**
```bash
# Deploy via Git
git push digitalocean main

# Environment variables configured in dashboard
# Automatic HTTPS
# Zero-downtime deployments
```

---

### 2. **AWS Lightsail** ⭐⭐⭐⭐
**Best for: AWS ecosystem integration**

**Pricing:** $10-20/month  
**Security Features:**
- Managed databases
- Free SSL certificates
- DDoS protection (Shield Standard)
- Regular snapshots
- VPC isolation

---

### 3. **Cloudflare Pages + PlanetScale** ⭐⭐⭐⭐
**Best for: Free tier / Low traffic**

**Pricing:** Free - $10/month  
**Security Features:**
- Built-in DDoS protection (Cloudflare)
- Edge caching
- Automatic SSL
- PlanetScale: Serverless MySQL with branching

**Note:** Requires minor code changes for serverless compatibility

---

### 4. **Linode/Vultr VPS + Cloudflare** ⭐⭐⭐
**Best for: Full control**

**Pricing:** $5-10/month VPS + Free Cloudflare  
**Security Features:**
- Full root access
- Cloudflare DDoS protection
- Manual security hardening required

**Setup:**
```bash
# Point domain to Cloudflare
# Set Cloudflare to "Full (Strict)" SSL mode
# Enable WAF rules
# Enable "Under Attack Mode" if needed
```

---

## ✅ Post-Deployment Checklist

```
Environment:
[ ] APP_ENV set to 'production'
[ ] .env file created with secure credentials
[ ] Database password is strong (20+ characters)
[ ] BASE_PATH configured correctly

Files:
[ ] File permissions set (755/644)
[ ] setup.php deleted or chmod 000
[ ] .env not accessible via web
[ ] logs/ directory protected

PHP:
[ ] display_errors = Off
[ ] error_log enabled
[ ] expose_php = Off
[ ] Dangerous functions disabled
[ ] Session cookies secure

Apache:
[ ] HTTPS enabled with valid certificate
[ ] Security headers configured
[ ] Directory listing disabled
[ ] .htaccess working
[ ] Virtual host configured

MySQL:
[ ] Dedicated user created
[ ] Root access disabled remotely
[ ] bind-address = 127.0.0.1
[ ] Automated backups configured

Security:
[ ] Firewall enabled (UFW)
[ ] Fail2ban configured
[ ] SSL certificate auto-renewal working
[ ] CSRF protection tested
[ ] Rate limiting tested

Application:
[ ] First admin user created
[ ] Test login/logout
[ ] Test registration
[ ] Test password requirements
[ ] Test email validation
[ ] Test CSRF tokens
[ ] Test rate limiting

Monitoring:
[ ] Error logs rotating
[ ] Access logs reviewed
[ ] Backup script tested
[ ] Uptime monitoring configured
[ ] Email alerts configured

Compliance:
[ ] Privacy policy accessible
[ ] KVKK policy accessible
[ ] Cookie consent (if EU users)
[ ] Terms of service
```

---

## 🔧 Security Testing

```bash
# Test SSL configuration
curl -I https://yourdomain.com

# Test security headers
curl -I https://yourdomain.com | grep -E "X-Frame|X-Content|X-XSS|Strict-Transport"

# Test SSL grade (external)
# Visit: https://www.ssllabs.com/ssltest/analyze.html?d=yourdomain.com

# Test rate limiting
# Try login 6 times with wrong password - should be blocked

# Test CSRF protection
# Try to submit form without token - should fail

# Test password strength
# Try to register with weak password - should fail
```

---

## 📞 Security Incident Response

**If you detect suspicious activity:**

1. **Immediate Actions:**
   ```bash
   # Check access logs
   tail -f /var/log/apache2/access.log
   
   # Check error logs
   tail -f /var/log/apache2/error.log
   tail -f logs/php_errors.log
   
   # Check failed login attempts
   grep "Yanlis username" logs/php_errors.log
   
   # Block suspicious IP
   ufw deny from <IP_ADDRESS>
   ```

2. **Investigation:**
   ```bash
   # Check database for unauthorized changes
   mysql -u root -p
   USE textsocialmedia;
   SELECT * FROM users WHERE role='admin' ORDER BY created_at DESC LIMIT 10;
   SELECT * FROM posts ORDER BY created_at DESC LIMIT 20;
   ```

3. **Recovery:**
   ```bash
   # Restore from backup if needed
   gunzip < /backups/mysql/backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u root -p textsocialmedia
   
   # Force all users to re-login
   # Truncate sessions table or clear session storage
   
   # Notify users if data compromised
   ```

---

## 📊 Security Score Target

**Target: 9.5/10** ⭐⭐⭐⭐⭐⭐⭐⭐⭐⚪

After following this guide:
- ✅ All critical issues resolved
- ✅ HTTPS enabled
- ✅ CSRF protection active
- ✅ Rate limiting implemented
- ✅ Strong password policy
- ✅ Error display disabled
- ✅ Automated backups
- ✅ Firewall configured
- ✅ Monitoring active

---

**Last Updated:** January 15, 2026  
**Review Frequency:** Monthly security audits recommended
