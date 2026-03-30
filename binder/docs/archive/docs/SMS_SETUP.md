# SMS Posting Setup Guide

## Overview
Users can post content to their profile by sending SMS messages to your dedicated phone number. This feature requires an SMS gateway service.

## SMS Provider Options

### 1. **Twilio** (International - Recommended)
- **Website**: https://www.twilio.com
- **Pricing**: Pay-as-you-go (~$0.0075 per SMS in Turkey)
- **Setup Time**: 15 minutes
- **Pros**: Easy API, reliable, global coverage
- **Cons**: Slightly more expensive than local providers

### 2. **Netgsm** (Turkey)
- **Website**: https://www.netgsm.com.tr
- **Pricing**: ~0.02-0.04 TL per SMS
- **Setup Time**: 30 minutes
- **Pros**: Cheaper for Turkey, Turkish support
- **Cons**: Turkey-only

### 3. **İletiMerkezi** (Turkey)
- **Website**: https://www.iletimerkezi.com
- **Pricing**: ~0.03 TL per SMS
- **Setup Time**: 30 minutes
- **Pros**: Good Turkish provider
- **Cons**: Turkey-only

## Setup Steps (Using Twilio)

### Step 1: Create Twilio Account

1. Go to https://www.twilio.com/try-twilio
2. Sign up for free trial ($15 credit)
3. Verify your email and phone
4. Note your **Account SID** and **Auth Token** from dashboard

### Step 2: Get a Phone Number

1. In Twilio Console, go to **Phone Numbers** → **Buy a Number**
2. Select Turkey (+90) or your country
3. Filter by **SMS** capability
4. Purchase a number (uses free trial credit)
5. Note your new phone number (e.g., +90 XXX XXX XXXX)

### Step 3: Configure Webhook

1. Go to **Phone Numbers** → **Manage** → **Active Numbers**
2. Click on your purchased number
3. Scroll to **Messaging**
4. Under "A MESSAGE COMES IN":
   - Webhook URL: `https://yourdomain.com/api/sms_webhook.php`
   - HTTP Method: `POST`
5. Click **Save**

### Step 4: Update Configuration

Add to `includes/config.php`:

```php
// SMS Configuration
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'twilio'); // or 'netgsm', 'iletimerkezi'
define('SMS_NUMBER', '+90XXXXXXXXXX'); // Your Twilio number

// Twilio credentials
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('TWILIO_AUTH_TOKEN', 'your_auth_token_here');
```

### Step 5: Run Database Migration

```bash
mysql -u root -p yourdatabase < migrations/add_sms_support.sql
```

### Step 6: Create Logs Directory

```bash
mkdir -p logs
chmod 750 logs
touch logs/sms_webhook.log
chmod 640 logs/sms_webhook.log
```

### Step 7: Test the System

1. **Verify Phone Number**:
   - Login to your account
   - Go to Profile Edit
   - Click "Telefon Doğrula"
   - Enter your phone number
   - Enter verification code

2. **Send Test SMS**:
   - Open your phone's SMS app
   - Send a message to your Twilio number
   - Message content: "This is my first SMS post!"
   - Check your profile - post should appear

## User Flow

### For Users:

1. **One-time Setup**:
   ```
   Profile Edit → Telefon Doğrula → Enter Phone → Verify Code
   ```

2. **Posting via SMS**:
   ```
   Open SMS App → New Message → To: +90 XXX XXX XXXX
   → Type your post → Send
   → Receive confirmation SMS
   ```

## Implementation Details

### Database Schema

```sql
-- users table additions
phone_number VARCHAR(20)          -- User's phone number
phone_verified TINYINT(1)         -- 1 if verified
phone_verification_code VARCHAR(6) -- 6-digit code
phone_verification_expiry DATETIME -- Code expiry

-- sms_log table
id, user_id, phone_number, direction, message_text, status, created_at
```

### Security Features

✅ **Phone Verification Required** - Only verified phones can post
✅ **One Phone Per Account** - Phone numbers are unique
✅ **Rate Limiting** - Can add SMS rate limits
✅ **Logging** - All SMS logged to database and file
✅ **Expiry** - Verification codes expire in 15 minutes

### SMS Webhook Processing

```
Incoming SMS → sms_webhook.php
↓
1. Parse sender number and message
2. Log to sms_log table
3. Find user by phone_number
4. Check if phone_verified = 1
5. Create post using create_post()
6. Send confirmation SMS
7. Update log status
```

## Costs Estimation

### Twilio (Turkey)
- Incoming SMS: $0.0075 each
- Outgoing SMS (confirmation): $0.0075 each
- **Total per post**: $0.015 (~0.50 TL)

### For 1000 SMS posts/month:
- Twilio: ~$15/month (~500 TL)
- Netgsm: ~20-40 TL/month

## Optional Enhancements

### 1. Add to Profile Edit Page

```php
// In profile_edit.php, add phone verification section:
<?php if (!$profile_user['phone_verified']): ?>
    <a href="<?= BASE_PATH ?>/verify_phone.php">Telefon Numaranızı Doğrulayın</a>
<?php else: ?>
    ✓ Doğrulanmış: <?= htmlspecialchars($profile_user['phone_number']) ?>
    <small>SMS ile gönderi paylaşabilirsiniz</small>
<?php endif; ?>
```

### 2. Add SMS Rate Limiting

```php
// Limit to 10 SMS posts per hour
$count = query("SELECT COUNT(*) as c FROM posts WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND created_via = 'sms'", [$user_id]);
if ($count->fetchColumn() >= 10) {
    send_sms_response("Saat başına maksimum 10 SMS gönderisi yapabilirsiniz.");
    exit;
}
```

### 3. Add SMS Indicator to Posts

```sql
ALTER TABLE posts ADD COLUMN created_via ENUM('web', 'sms') DEFAULT 'web';
```

Show badge on posts created via SMS.

## Troubleshooting

### SMS Not Received
- Check Twilio console logs
- Verify webhook URL is publicly accessible
- Check logs/sms_webhook.log for errors
- Ensure phone is verified in database

### Verification Code Not Sent
- In development: Code shown in UI
- In production: Integrate actual SMS sending API
- Check Twilio balance

### Post Not Created
- Check sms_log table for status
- Verify user exists with phone_verified = 1
- Check post creation errors in logs

## Production Checklist

- [ ] SMS provider account created
- [ ] Phone number purchased
- [ ] Webhook configured
- [ ] Database migration run
- [ ] Config.php updated with credentials
- [ ] Logs directory created
- [ ] Test SMS sending
- [ ] Test SMS receiving
- [ ] Test phone verification
- [ ] Monitor costs
- [ ] Set up billing alerts

## Support

For issues:
- Twilio: https://support.twilio.com
- Netgsm: https://www.netgsm.com.tr/destek
- Check logs: `tail -f logs/sms_webhook.log`

---

**Cost Control Tips**:
- Use free tier credits first
- Set billing alerts
- Monitor usage in provider dashboard
- Consider Turkish providers for lower costs
