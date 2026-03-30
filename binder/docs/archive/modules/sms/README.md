# SMS Module

## Overview

The SMS module allows users to post to their profile by sending an SMS message. This is a **completely optional feature** that can be enabled or disabled independently of the core application.

## Status: OPTIONAL MODULE

This module is **NOT required** for the main application to function. All core features work perfectly without SMS support.

## Features

When enabled, users can:
1. Verify their phone number via 6-digit SMS code
2. Send SMS messages to the platform's phone number
3. SMS content automatically becomes a post on their profile
4. Receive confirmation SMS when post is created

## File Structure

```
modules/sms/
├── README.md                     # This file
├── config.php                    # SMS module configuration
├── verify_phone.php              # Phone verification UI
├── api/
│   ├── sms_webhook.php          # Webhook for Twilio
│   └── sms_webhook_turkish.php  # Webhook for Turkish providers
└── migrations/
    └── add_sms_support.sql      # Database schema (only run if enabling)
```

## Installation (When Ready to Enable)

### Step 1: Decide to Enable

Only enable this module if you:
- Have budget for SMS costs (~0.03-0.50 TL per SMS)
- Want to offer SMS posting as a feature
- Have chosen and signed up with an SMS provider

### Step 2: Choose SMS Provider

**For Turkish Users (Recommended):**
- **Netgsm** - Most popular, ~0.03 TL/SMS
- **İletiMerkezi** - Good alternative, ~0.04 TL/SMS
- **Mutlucell** - Similar to Netgsm

**For International:**
- **Twilio** - Global, ~$0.015/SMS (~0.50 TL)

See [../../docs/SMS_TURKISH_PROVIDERS.md](../../docs/SMS_TURKISH_PROVIDERS.md) for detailed comparison.

### Step 3: Run Database Migration

```bash
mysql -u your_user -p your_database < modules/sms/migrations/add_sms_support.sql
```

This adds:
- `phone_number`, `phone_verified`, `phone_verification_code`, `phone_verification_expiry` to `users` table
- `sms_log` table for tracking SMS messages

### Step 4: Configure Credentials

Edit `.env` file or `modules/sms/config.php`:

**For Netgsm (Turkish):**
```bash
SMS_ENABLED=true
SMS_PROVIDER=netgsm
SMS_NUMBER=+905XXXXXXXXX
NETGSM_USERNAME=your_username
NETGSM_PASSWORD=your_password
NETGSM_HEADER=YOURHEADER
```

**For Twilio (International):**
```bash
SMS_ENABLED=true
SMS_PROVIDER=twilio
SMS_NUMBER=+1234567890
TWILIO_ACCOUNT_SID=your_sid
TWILIO_AUTH_TOKEN=your_token
```

### Step 5: Set Up Webhook

Configure your SMS provider to send incoming SMS to:

**For Netgsm/İletiMerkezi/Mutlucell:**
```
https://yourdomain.com/modules/sms/api/sms_webhook_turkish.php
```

**For Twilio:**
```
https://yourdomain.com/modules/sms/api/sms_webhook.php
```

### Step 6: Test

1. Visit: `https://yourdomain.com/modules/sms/verify_phone.php`
2. Enter your phone number
3. Receive and enter verification code
4. Send test SMS to your platform number
5. Check if post was created

## Disabling the Module

To completely disable SMS features:

**Option 1: Environment Variable**
```bash
SMS_ENABLED=false
```

**Option 2: Config File**
Edit `modules/sms/config.php`:
```php
define('SMS_MODULE_ENABLED', false);
```

When disabled:
- Phone verification option won't show in profile settings
- Webhook endpoints will reject requests
- No SMS-related database queries
- Zero performance impact

## Integration with Main App

The main application checks if SMS module is enabled:

```php
// In profile_edit.php
if (defined('SMS_MODULE_ENABLED') && SMS_MODULE_ENABLED) {
    // Show phone verification section
}
```

## Database Tables (Only if Enabled)

### users table additions:
- `phone_number` - User's phone number (+905XXXXXXXXX format)
- `phone_verified` - Boolean flag (0 or 1)
- `phone_verification_code` - 6-digit code for verification
- `phone_verification_expiry` - Code expiration time

### sms_log table:
- Tracks all incoming/outgoing SMS
- Helps with debugging and billing
- Records status and provider message IDs

## Cost Estimates

Based on 1000 SMS posts per month:

| Provider | Per SMS | Monthly Cost |
|----------|---------|--------------|
| Netgsm (TR) | 0.03 TL | ~30 TL |
| İletiMerkezi (TR) | 0.04 TL | ~40 TL |
| Twilio (Global) | $0.015 | ~$15 (~500 TL) |

## Security

- Phone verification required before SMS posting
- Unique phone constraint (one phone = one account)
- Request logging for audit trail
- Token expiry (15 minutes for verification)
- HTTPS required for webhooks

## Support

For detailed setup guides:
- Turkish Providers: [docs/SMS_TURKISH_PROVIDERS.md](../../docs/SMS_TURKISH_PROVIDERS.md)
- Twilio Setup: [docs/SMS_SETUP.md](../../docs/SMS_SETUP.md)

## Troubleshooting

Check logs at:
- `logs/sms_webhook.log` - Webhook requests
- `sms_log` table - SMS history

Common issues:
1. **Webhook not called** → Check provider panel webhook URL
2. **No post created** → Check phone_verified flag in database
3. **Can't send SMS** → Verify credentials in config
