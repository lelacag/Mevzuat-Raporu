# 🔐 No-JavaScript CAPTCHA System

A pure **PHP + CSS** CAPTCHA implementation that works without any JavaScript, inspired by the ["Surviving the Shadows"](https://medium.com/@EDBCBlog/surviving-the-shadows-creating-a-captchat-without-javascript-1bdda8435fc3) approach.

## ✨ Features

- 🚫 **Zero JavaScript** - Works with JS disabled
- 🎨 **Pure CSS Zoom** - Uses `:has()` pseudo-class for interactive effects
- 🔒 **Server-Side Security** - All validation on backend
- ⏱️ **Time-Based Expiration** - 2-minute validity
- 🎫 **One-Time Use** - Prevents replay attacks
- 🤖 **Bot Resistant** - Image distortion and noise
- ♿ **Keyboard Accessible** - Full tab navigation

## 🎯 How It Works

### 1. Image Generation (PHP GD)
```php
// Generate random code
$code = generate_captcha_code(6); // e.g., "A7K9P2"

// Create distorted image with noise
$image = create_captcha_image($code);

// Convert to base64 data URI
$data_uri = 'data:image/png;base64,' . base64_encode($image);
```

### 2. CSS-Only Zoom Effect
```css
/* When input 0 is focused, zoom to that position */
[captcha-zoom]:has([data-position="0"]:focus) {
    background-position: 0% center;
    background-size: 600px 160px; /* 2x zoom */
}
```

### 3. Server-Side Validation
```php
// Store in session
$_SESSION['captcha'] = [
    'code' => 'A7K9P2',
    'token' => bin2hex(random_bytes(32)),
    'timestamp' => time()
];

// Verify submission
verify_captcha($user_input, $token);
```

## 🚀 Quick Start

### Test the CAPTCHA
Visit: `http://localhost/textsocialmedia/captcha_test.php`

### Implementation (3 steps)

**Step 1:** Include the library
```php
require_once __DIR__ . '/includes/captcha.php';
```

**Step 2:** Generate and display CAPTCHA
```php
$captcha_data = init_captcha();
echo render_captcha_html($captcha_data);
```

**Step 3:** Verify on submission
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = get_captcha_input_from_post();
    $token = $_POST['captcha_token'];
    $result = verify_captcha($user_input, $token);
    
    if ($result['valid']) {
        // ✅ CAPTCHA passed
    } else {
        // ❌ Show error
        echo $result['error'];
    }
}
```

## 📖 Example Usage

See the full implementation in:
- **[register.php](../register.php)** - Registration with CAPTCHA
- **[captcha_test.php](../captcha_test.php)** - Interactive test page

## 🔧 API Reference

### Functions

#### `init_captcha()`
Generates a new CAPTCHA and stores it in session.

**Returns:**
```php
[
    'code' => 'ABC123',      // The correct answer
    'token' => 'random...',  // Security token
    'image' => 'data:image/png;base64,...'
]
```

#### `render_captcha_html($captcha_data)`
Renders the CAPTCHA HTML with embedded CSS.

**Parameters:**
- `$captcha_data` - Array from `init_captcha()`

**Returns:** HTML string

#### `verify_captcha($user_input, $token)`
Validates user's CAPTCHA submission.

**Parameters:**
- `$user_input` - User's entered code
- `$token` - CAPTCHA token from form

**Returns:**
```php
[
    'valid' => true/false,
    'error' => 'Error message' // if invalid
]
```

#### `get_captcha_input_from_post()`
Collects CAPTCHA characters from `$_POST`.

**Returns:** String (e.g., "ABC123")

## 🎨 Customization

### Change Code Length
```php
// In includes/captcha.php
$code = generate_captcha_code(8); // Default: 6
```

### Adjust Expiration Time
```php
// In verify_captcha()
if (time() - $captcha['timestamp'] > 300) // 5 minutes
```

### Modify Colors
```php
// In create_captcha_image()
$text_colors = [
    imagecolorallocate($image, 255, 0, 0),   // Red
    imagecolorallocate($image, 0, 0, 255),   // Blue
];
```

### Custom Styling
```css
/* Override in your CSS */
.captcha-container {
    background: #your-color;
}

.captcha-char-input:focus {
    border-color: #your-theme-color;
}
```

## 🛡️ Security Features

| Feature | Implementation |
|---------|---------------|
| **Token Validation** | 64-character random token per CAPTCHA |
| **Time Expiration** | 2-minute validity window |
| **One-Time Use** | Session cleared after verification |
| **Session Binding** | Server-side storage only |
| **Rate Limiting** | Combine with registration rate limits |
| **CSRF Protection** | Works with existing CSRF tokens |

## 🌐 Browser Compatibility

| Feature | Requirement |
|---------|-------------|
| **CAPTCHA Display** | All browsers ✅ |
| **Form Submission** | All browsers ✅ |
| **Zoom Effect** | Modern browsers with `:has()` |

**`:has()` Support:**
- Chrome 105+ ✅
- Firefox 121+ ✅
- Safari 15.4+ ✅
- Edge 105+ ✅

**Fallback:** CAPTCHA works without zoom; users see full image.

## ⚡ Performance

| Metric | Value |
|--------|-------|
| Image Generation | ~50-100ms |
| Base64 Encoding | ~20ms |
| Image Size | 15-25KB |
| Memory Usage | Minimal (session only) |

## 🧪 Testing

### Manual Test Checklist
- [ ] CAPTCHA image displays
- [ ] Can tab between input fields
- [ ] Zoom works on focus (modern browsers)
- [ ] Correct code is accepted
- [ ] Wrong code is rejected
- [ ] Expired CAPTCHA (>2min) is rejected
- [ ] Token reuse is prevented
- [ ] Works with JavaScript disabled

### Automated Testing
```php
// Test valid submission
$captcha = init_captcha();
$result = verify_captcha($captcha['code'], $captcha['token']);
assert($result['valid'] === true);

// Test invalid code
$result = verify_captcha('WRONG', $captcha['token']);
assert($result['valid'] === false);
```

## 🐛 Troubleshooting

### "GD library not installed"
```bash
# Ubuntu/Debian
sudo apt-get install php-gd
sudo systemctl restart apache2

# Check installation
php -m | grep gd
```

### "No CAPTCHA session found"
Ensure `session_start()` is called before `init_captcha()`.

### "Characters not readable"
- Increase font size in `create_captcha_image()`
- Reduce noise intensity
- Use TTF font instead of built-in font

## 📚 Documentation

- **[Full Documentation](CAPTCHA_SYSTEM.md)** - Complete technical guide
- **[Security Audit](../SECURITY_AUDIT.md)** - Security analysis
- **[Test Page](../captcha_test.php)** - Live demo

## 🎓 Credits

**Inspired by:**
- ["Surviving the Shadows: Creating a CAPTCHA without JavaScript"](https://medium.com/@EDBCBlog/surviving-the-shadows-creating-a-captchat-without-javascript-1bdda8435fc3) by Enmanuel D Becerra C
- Uses CSS `:has()` pseudo-class for interaction
- PHP GD library for image generation

**Adapted for:** Text Social Media Platform

## 📜 License

Part of the Text Social Media Platform  
See main project LICENSE

---

**Version:** 1.0  
**Last Updated:** January 15, 2026  
**Status:** ✅ Production Ready
