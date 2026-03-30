# CAPTCHA System - No JavaScript Implementation

## Overview

This CAPTCHA system is built using **pure PHP and CSS** without any JavaScript, inspired by the "Surviving the Shadows" approach. It's designed to be bot-resistant while maintaining accessibility.

## How It Works

### 1. **Server-Side Image Generation (PHP GD)**
- Generates random 6-character codes (A-Z, 2-9, excluding confusing characters)
- Creates distorted images with noise lines and dots
- Uses multiple colors and random rotations for each character
- Converts image to base64 data URI for inline embedding

### 2. **CSS-Only Zoom Effect**
The clever part - no JavaScript needed!

```css
/* When user focuses on input field 0, zoom to that position */
[captcha-zoom]:has([data-position="0"]:focus) {
    background-position: 0% center;
    background-size: 600px 160px; /* 2x zoom */
}
```

The `:has()` pseudo-class detects which input is focused and adjusts the background image position and size accordingly. This creates a zoom effect that helps users read individual characters.

### 3. **Security Features**

#### Token-Based Validation
- Each CAPTCHA gets a unique random token
- Token is stored in session with the correct answer
- Token must match on submission

#### Time-Based Expiration
- CAPTCHA expires after 2 minutes
- Prevents replay attacks
- Forces fresh CAPTCHA generation

#### One-Time Use
- CAPTCHA is deleted from session after verification attempt
- Prevents reuse of same CAPTCHA

#### Session Storage
```php
$_SESSION['captcha'] = [
    'code' => 'ABC123',          // Correct answer
    'token' => 'random_hash',    // Security token
    'timestamp' => 1234567890    // Expiration check
];
```

## Implementation

### Basic Usage

```php
<?php
require_once 'includes/captcha.php';

// Generate CAPTCHA
$captcha_data = init_captcha();

// Display in form
echo render_captcha_html($captcha_data);

// Verify on submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = get_captcha_input_from_post();
    $token = $_POST['captcha_token'];
    $result = verify_captcha($user_input, $token);
    
    if ($result['valid']) {
        // CAPTCHA passed
    } else {
        // Show error: $result['error']
    }
}
?>
```

### Integration Example (Registration)

```php
// In register.php
require_once __DIR__ . '/includes/captcha.php';

// Always generate fresh CAPTCHA for form
$captcha_data = init_captcha();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CAPTCHA first
    $captcha_input = get_captcha_input_from_post();
    $captcha_token = $_POST['captcha_token'] ?? '';
    $captcha_result = verify_captcha($captcha_input, $captcha_token);
    
    if (!$captcha_result['valid']) {
        $errors[] = 'CAPTCHA failed: ' . $captcha_result['error'];
    } else {
        // Proceed with registration
    }
}

// In form
echo render_captcha_html($captcha_data);
```

## Features

### ✅ **Advantages**

1. **No JavaScript Required**
   - Works with JS disabled
   - Works with NoScript browser extensions
   - Accessible to users with strict privacy settings

2. **Pure CSS Visual Effects**
   - Zoom effect using `:has()` pseudo-class
   - No external libraries
   - Lightweight and fast

3. **Server-Side Security**
   - All validation happens server-side
   - Can't be bypassed by client manipulation
   - Token-based verification
   - Time-based expiration

4. **Bot Resistant**
   - Image distortion
   - Visual noise
   - One-time use tokens
   - Session-based validation

5. **User Friendly**
   - Click or tab to zoom individual characters
   - Clear visual feedback
   - Separate input boxes per character
   - Works on mobile and desktop

### ⚠️ **Limitations**

1. **Browser Compatibility**
   - Requires `:has()` pseudo-class support
   - Modern browsers: ✅ Chrome 105+, Firefox 121+, Safari 15.4+
   - Fallback: Users can still see the full image without zoom

2. **Accessibility**
   - No audio alternative (screen readers won't help)
   - Recommendation: Provide alternative contact method for users who can't complete CAPTCHA

3. **OCR Vulnerability**
   - Advanced OCR can potentially solve it
   - Mitigation: Increase distortion, add more noise
   - Consider additional rate limiting

## Configuration

### Customization Options

```php
// In includes/captcha.php

// Change code length
$code = generate_captcha_code(8); // Default: 6

// Adjust image size
$width = 400;  // Default: 300
$height = 100; // Default: 80

// Modify expiration time
if (time() - $captcha['timestamp'] > 300) // 5 minutes instead of 2

// Change character set
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Exclude O, 0, I, 1
```

### Styling

The CAPTCHA styling can be customized in the embedded CSS within `render_captcha_html()`:

```css
.captcha-container {
    background: #f9f9f9;
    border: 1px solid #ddd;
    /* Customize as needed */
}

.captcha-char-input:focus {
    border-color: #43a047; /* Your theme color */
}
```

## Security Best Practices

### 1. **Combine with Rate Limiting**
```php
if (!check_rate_limit('register', $ip, 3, 3600)) {
    // Block: 3 attempts per hour
}
```

### 2. **Use with CSRF Tokens**
```php
if (!verify_csrf_token($_POST['csrf_token'])) {
    // Reject
}
```

### 3. **Log Failed Attempts**
```php
if (!$captcha_result['valid']) {
    error_log("CAPTCHA failed for IP: " . $_SERVER['REMOTE_ADDR']);
}
```

### 4. **Honeypot Fields**
Add hidden fields that bots will fill but humans won't see:
```html
<input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
```

## Testing

### Test Page
Visit `/captcha_test.php` to test the CAPTCHA:
- Visual appearance
- Zoom functionality
- Verification logic
- Error handling

### Manual Testing Checklist
- [ ] Characters are readable when zoomed
- [ ] Tab navigation works
- [ ] Correct code is accepted
- [ ] Wrong code is rejected
- [ ] Expired CAPTCHA is rejected
- [ ] Reused token is rejected
- [ ] Works without JavaScript

## Troubleshooting

### Issue: "No CAPTCHA session found"
**Cause:** Session not started or session lost  
**Fix:** Ensure `session_start()` is called before `init_captcha()`

### Issue: Characters not visible
**Cause:** GD library not installed  
**Fix:** 
```bash
# Ubuntu/Debian
sudo apt-get install php-gd

# Restart Apache
sudo systemctl restart apache2
```

### Issue: Zoom effect not working
**Cause:** Browser doesn't support `:has()` pseudo-class  
**Fix:** Update browser or inform users. CAPTCHA still works, just without zoom.

### Issue: "CAPTCHA expired"
**Cause:** User took longer than 2 minutes  
**Fix:** Increase timeout or regenerate CAPTCHA with helpful message

## Performance

- **Image Generation:** ~50-100ms (cached in session)
- **Base64 Encoding:** ~20ms
- **Total Size:** ~15-25KB per CAPTCHA (base64 image)
- **Memory Usage:** Minimal (session storage only)

## Future Enhancements

1. **Audio Alternative**
   - Generate text-to-speech audio file
   - Provide "Listen to CAPTCHA" option

2. **Increased Difficulty**
   - Add wavy distortion
   - More complex noise patterns
   - Color gradients

3. **Analytics**
   - Track success/failure rates
   - Detect bot patterns
   - Adjust difficulty automatically

4. **Multi-Language Support**
   - Numbers only option
   - Math problems ("5 + 3 = ?")
   - Language-specific characters

## Credits

Inspired by:
- [Surviving the Shadows: Creating a CAPTCHA without JavaScript](https://medium.com/@EDBCBlog/surviving-the-shadows-creating-a-captchat-without-javascript-1bdda8435fc3) by Enmanuel D Becerra C
- Uses CSS `:has()` pseudo-class for zoom effect
- PHP GD library for image generation

## License

Part of the Text Social Media Platform project.

---

**Last Updated:** January 15, 2026  
**Version:** 1.0
