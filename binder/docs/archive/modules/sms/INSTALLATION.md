# SMS Module Installation Guide

## Prerequisites

Before installing the SMS module, ensure you have:

- [ ] Decided to enable SMS posting feature
- [ ] Chosen an SMS provider (Netgsm, İletiMerkezi, Twilio, etc.)
- [ ] Budget allocated for SMS costs
- [ ] HTTPS enabled on your domain (required for webhooks)

## Installation Steps

### 1. Run Database Migration

```bash
# Navigate to your project directory
cd /Applications/XAMPP/xamppfiles/htdocs/textsocialmedia

# Run the migration
mysql -u root -p your_database_name < modules/sms/migrations/add_sms_support.sql
```

This will add:
- Phone-related columns to `users` table
- `sms_log` table for tracking

### 2. Enable the Module

Edit your environment configuration or directly in `modules/sms/config.php`:

**Option A: Using .env file (recommended)**
```bash
SMS_ENABLED=true
```

**Option B: Direct config edit**
Edit `modules/sms/config.php` line 8:
```php
define('SMS_MODULE_ENABLED', true);
```

### 3. Choose and Configure SMS Provider

#### For Netgsm (Turkish - Recommended):

1. **Sign up**: https://www.netgsm.com.tr
2. **Purchase SMS package** (start with 1000 SMS ~30 TL)
3. **Get credentials** from panel
4. **Add to config**:

```bash
SMS_PROVIDER=netgsm
SMS_NUMBER=+905XXXXXXXXX  # Your receiving number
NETGSM_USERNAME=your_username
NETGSM_PASSWORD=your_password
NETGSM_HEADER=YOURHEADER  # Sender name (needs approval)
```

#### For Twilio (International):

1. **Sign up**: https://www.twilio.com
2. **Purchase phone number** with SMS capability
3. **Get credentials** from console
4. **Add to config**:

```bash
SMS_PROVIDER=twilio
SMS_NUMBER=+1234567890
TWILIO_ACCOUNT_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
```

#### For İletiMerkezi (Turkish Alternative):

```bash
SMS_PROVIDER=iletimerkezi
SMS_NUMBER=+905XXXXXXXXX
ILETIMERKEZI_USERNAME=your_username
ILETIMERKEZI_PASSWORD=your_password
```

### 4. Configure Webhook in Provider Panel

**Netgsm:**
1. Login to https://www.netgsm.com.tr/panel
2. Go to: Ayarlar → API Ayarları
3. Set webhook URL:
   ```
   https://yourdomain.com/modules/sms/api/sms_webhook_turkish.php
   ```
4. Enable "Gelen SMS Bildirimi"
5. Save settings

**Twilio:**
1. Login to https://www.twilio.com/console
2. Go to: Phone Numbers → Your number
3. Under "Messaging", set webhook:
   ```
   https://yourdomain.com/modules/sms/api/sms_webhook.php
   ```
4. Method: POST
5. Save

**İletiMerkezi:**
1. Login to panel
2. Go to: Ayarlar → Webhook
3. Set URL:
   ```
   https://yourdomain.com/modules/sms/api/sms_webhook_turkish.php
   ```
4. Method: POST
5. Save

### 5. Create Logs Directory

```bash
mkdir -p logs
chmod 750 logs
touch logs/sms_webhook.log
chmod 640 logs/sms_webhook.log
```

### 6. Test the Installation

#### Test Phone Verification:

1. Go to: `https://yourdomain.com/modules/sms/verify_phone.php`
2. Enter your phone number
3. Check if you receive verification code
4. Enter the code
5. Verify success message

#### Test SMS Posting:

1. After verification, send SMS to your platform number
2. Message content: "Test post from SMS"
3. Check your profile - post should appear
4. Check logs: `logs/sms_webhook.log`
5. Check database: `SELECT * FROM sms_log ORDER BY created_at DESC LIMIT 5;`

### 7. Verify Integration

Check that:
- [ ] Phone verification section shows in profile edit page
- [ ] You can verify a phone number
- [ ] Verification code SMS arrives (within 1 minute)
- [ ] You can send SMS to platform number
- [ ] SMS creates a post on your profile
- [ ] Confirmation SMS is sent back
- [ ] Logs are being written
- [ ] Database `sms_log` table has entries

## Troubleshooting

### Issue: Module doesn't appear in profile edit

**Solution:**
```bash
# Check if module is enabled
grep -r "SMS_MODULE_ENABLED" modules/sms/config.php

# Should show: define('SMS_MODULE_ENABLED', true);
```

### Issue: Webhook not receiving requests

**Solutions:**
1. Verify webhook URL is HTTPS (HTTP won't work)
2. Check SSL certificate is valid
3. Test webhook directly:
   ```bash
   curl -X POST https://yourdomain.com/modules/sms/api/sms_webhook_turkish.php \
     -d "phone=05301234567" \
     -d "message=Test" \
     -d "msgid=12345"
   ```
4. Check logs: `tail -f logs/sms_webhook.log`

### Issue: Phone verification code not received

**Solutions:**
1. Check provider SMS balance
2. Verify credentials in config
3. Check provider panel for sent messages
4. If using Netgsm, verify SMS header is approved
5. Check phone number format (should be +905XX for Turkey)

### Issue: SMS received but no post created

**Solutions:**
1. Check `sms_log` table for status
2. Verify `phone_verified = 1` in users table
3. Check phone number format matches exactly
4. Review `logs/sms_webhook.log` for errors

## Uninstallation

If you decide to disable the module:

### Soft Disable (keeps data):

```bash
# In .env or config
SMS_ENABLED=false
```

Module will be inactive but data remains.

### Full Removal (deletes data):

```bash
# Remove database tables
mysql -u root -p your_database << EOF
ALTER TABLE users DROP COLUMN phone_number;
ALTER TABLE users DROP COLUMN phone_verified;
ALTER TABLE users DROP COLUMN phone_verification_code;
ALTER TABLE users DROP COLUMN phone_verification_expiry;
DROP TABLE sms_log;
EOF

# Remove module directory
rm -rf modules/sms/
```

## Support

- **Netgsm Support**: destek@netgsm.com.tr, 0850 885 0 885
- **Twilio Support**: https://www.twilio.com/help
- **İletiMerkezi Support**: destek@iletimerkezi.com

## Cost Monitoring

### Check SMS Balance:

**Netgsm:**
- Login to panel → Bakiye
- Set up low balance alerts

**Twilio:**
- Console → Billing
- Set up usage alerts

### Estimate Monthly Cost:

```
Daily SMS posts × 30 days × Cost per SMS = Monthly cost

Example (Netgsm):
10 posts/day × 30 days × 0.03 TL = 9 TL/month

Example (Twilio):
10 posts/day × 30 days × $0.015 = $4.50/month
```

## Security Best Practices

1. **Never commit credentials** to git
2. **Use environment variables** for sensitive data
3. **Enable HTTPS** for all webhook URLs
4. **Monitor logs** regularly for abuse
5. **Set rate limits** if high usage expected
6. **Whitelist webhook IPs** if provider supports it
7. **Rotate credentials** periodically

## Next Steps

After successful installation:

1. **Document** your SMS number for users
2. **Add instructions** to help/FAQ page
3. **Monitor costs** in provider dashboard
4. **Set up alerts** for low balance
5. **Test edge cases** (long messages, special characters)
6. **Add rate limiting** if needed
