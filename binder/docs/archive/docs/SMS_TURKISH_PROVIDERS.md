# Turkish SMS Provider Integration Guide

## Overview
This guide covers integrating Turkish SMS providers (Netgsm, İletiMerkezi, Mutlucell) for the SMS-to-post feature. These providers work with all Turkish operators (Turkcell, Vodafone, Türk Telekom).

## Why Not Turkcell Directly?

**Turkcell** doesn't offer simple SMS API services for individuals or small businesses. They have:
- Enterprise-only solutions requiring corporate accounts
- Complex contracts and minimum commitments
- No simple webhook/API for developers

**Better Option:** Use SMS gateway aggregators that support all Turkish operators.

---

## Recommended Turkish Providers

### 1. **Netgsm** ⭐ Most Popular

**Pros:**
- Very popular in Turkey
- Simple API
- Good documentation (Turkish)
- Affordable pricing
- Reliable delivery
- Two-way SMS support

**Pricing:**
- ~0.03-0.04 TL per SMS
- Packages available: 1000 SMS = ~30-40 TL
- Webhook support: FREE

**Sign Up:**
1. Visit: https://www.netgsm.com.tr
2. Register for "SMS API" service
3. Get username & password
4. Get SMS header (sender name, requires approval)

**API Format:**

Incoming SMS (webhook POST):
```
phone: 05XXXXXXXXX
message: SMS message content
msgid: unique message ID
datetime: 2026-01-15 14:30:00
```

Outgoing SMS (API call):
```
GET https://api.netgsm.com.tr/sms/send/get/?
    usercode=USERNAME&
    password=PASSWORD&
    gsmno=5XXXXXXXXX&
    message=Your message&
    msgheader=YOURHEADER
```

---

### 2. **İletiMerkezi**

**Pros:**
- Good alternative
- Simple integration
- Competitive pricing
- Web panel for monitoring

**Pricing:**
- ~0.03-0.05 TL per SMS
- Packages available
- Webhook support: FREE

**Sign Up:**
1. Visit: https://www.iletimerkezi.com
2. Register account
3. Purchase SMS package
4. Configure webhook URL

**API Format:**

Incoming SMS (webhook POST):
```
from: 05XXXXXXXXX
msg: SMS message content
id: message_id
```

Outgoing SMS (API call):
```
GET https://api.iletimerkezi.com/v1/send-sms/get/?
    username=USERNAME&
    password=PASSWORD&
    receipents=5XXXXXXXXX&
    message=Your message
```

---

### 3. **Mutlucell**

**Pros:**
- Another reliable option
- Similar features to Netgsm
- Good support

**Pricing:**
- Similar to others (~0.03-0.04 TL)

**API Format:**

Incoming SMS (webhook POST):
```
sender: 05XXXXXXXXX
text: SMS message content
msgid: message_id
```

---

## Setup Instructions

### Step 1: Choose Provider

Recommended: **Netgsm** (most popular, best documentation)

### Step 2: Sign Up & Get Credentials

1. Register at provider website
2. Verify identity (required by Turkish law)
3. Purchase SMS package (start with 1000 SMS)
4. Get API credentials:
   - Username
   - Password
   - SMS Header (for Netgsm, requires approval)

### Step 3: Configure Application

Edit `.env` file or `includes/config.php`:

```bash
# For Netgsm
SMS_ENABLED=true
SMS_PROVIDER=netgsm
SMS_NUMBER=+905XXXXXXXXX  # Your receiving number
NETGSM_USERNAME=your_username
NETGSM_PASSWORD=your_password
NETGSM_HEADER=YOURHEADER  # Approved sender name
```

Or for İletiMerkezi:
```bash
SMS_ENABLED=true
SMS_PROVIDER=iletimerkezi
SMS_NUMBER=+905XXXXXXXXX
ILETIMERKEZI_USERNAME=your_username
ILETIMERKEZI_PASSWORD=your_password
```

### Step 4: Set Up Webhook

In provider's web panel:

**Netgsm:**
1. Login to panel
2. Go to "Ayarlar" → "API Ayarları"
3. Set webhook URL: `https://yourdomain.com/api/sms_webhook_turkish.php`
4. Enable "Gelen SMS Bildirimi"

**İletiMerkezi:**
1. Login to panel
2. Go to "Ayarlar" → "Webhook"
3. Set URL: `https://yourdomain.com/api/sms_webhook_turkish.php`
4. Select POST method

### Step 5: Test

1. **Verify phone** on your account via website
2. **Send test SMS** to your SMS number
3. **Check logs**: `logs/sms_webhook.log`
4. **Verify post** created on your profile

---

## Phone Number Format

Turkish mobile numbers can be in various formats:

```
05XXXXXXXXX        → Converted to +905XXXXXXXXX
5XXXXXXXXX         → Converted to +905XXXXXXXXX
905XXXXXXXXX       → Converted to +905XXXXXXXXX
+905XXXXXXXXX      → Already correct
```

The webhook automatically normalizes all formats to `+905XXXXXXXXX`.

---

## Cost Comparison

### Per-SMS Cost (1000 SMS package):

| Provider | Cost per SMS | 1000 SMS Package | Monthly (~1000 posts) |
|----------|-------------|------------------|----------------------|
| **Netgsm** | ~0.03 TL | ~30 TL | ~30 TL |
| **İletiMerkezi** | ~0.04 TL | ~40 TL | ~40 TL |
| **Mutlucell** | ~0.03 TL | ~30 TL | ~30 TL |
| **Twilio** | $0.015 (~0.50 TL) | $15 (~500 TL) | ~500 TL |

**Conclusion:** Turkish providers are **15-20x cheaper** than Twilio for Turkish numbers!

---

## Security Considerations

### 1. **IP Whitelisting** (if available)
Some providers allow you to whitelist IPs. Check your provider's panel.

### 2. **Request Validation**
The webhook logs all requests to `logs/sms_webhook.log` for auditing.

### 3. **Rate Limiting**
Consider adding rate limiting to prevent abuse:
- Max 10 posts per phone per hour
- Add to database: `last_sms_time` tracking

### 4. **Phone Verification Required**
Only users who verified their phone can post via SMS.

---

## Troubleshooting

### Problem: SMS received but no post created

**Check:**
1. `logs/sms_webhook.log` - is webhook being called?
2. Phone number format - is it normalized correctly?
3. User verification - is `phone_verified = 1`?
4. Database - check `sms_log` table for status

### Problem: Webhook not receiving calls

**Check:**
1. URL is correct and HTTPS (required)
2. Webhook is enabled in provider panel
3. Your server firewall allows incoming connections
4. SSL certificate is valid

### Problem: Can't send outgoing SMS (confirmations)

**Check:**
1. Credentials are correct in config
2. SMS header approved (Netgsm requirement)
3. Sufficient SMS balance
4. Check provider API logs in their panel

---

## Example Workflow

1. **User registers** on website
2. **User goes** to "Profil Düzenle"
3. **User clicks** "Telefon Numaranızı Doğrulayın"
4. **User enters** phone: `05301234567`
5. **System sends** 6-digit code via SMS
6. **User enters** code, verification complete
7. **User can now** send SMS to platform number to post
8. **User sends SMS**: "Merhaba, ilk SMS gönderim!"
9. **Webhook receives** SMS → creates post → sends confirmation
10. **Post appears** on user's profile

---

## Production Checklist

- [ ] Provider account created and verified
- [ ] SMS package purchased
- [ ] Credentials added to `config.php` or `.env`
- [ ] Webhook URL configured in provider panel
- [ ] HTTPS enabled (required for webhooks)
- [ ] Test phone verification flow
- [ ] Test sending SMS to create post
- [ ] Test confirmation SMS delivery
- [ ] Monitor `logs/sms_webhook.log` for errors
- [ ] Monitor SMS balance in provider panel
- [ ] Set up low balance alerts (if available)

---

## Support Contacts

**Netgsm:**
- Website: https://www.netgsm.com.tr
- Support: destek@netgsm.com.tr
- Phone: 0850 885 0 885

**İletiMerkezi:**
- Website: https://www.iletimerkezi.com
- Support: destek@iletimerkezi.com
- Phone: 0850 885 0 885

**Mutlucell:**
- Website: https://www.mutlucell.com.tr
- Support: destek@mutlucell.com.tr

---

## Migration from Twilio

If you started with Twilio but want to switch:

1. Keep existing `api/sms_webhook.php` as backup
2. Use `api/sms_webhook_turkish.php` instead
3. Update webhook URL in provider
4. Change `SMS_PROVIDER` in config
5. Add Turkish provider credentials
6. Test thoroughly before going live

No database changes needed - same tables work for all providers!
