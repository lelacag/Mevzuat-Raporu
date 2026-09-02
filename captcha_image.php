<?php
error_log('captcha_image.php start');
// Simple GD-based CAPTCHA image renderer (no JS required)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php'; // starts session
require_once __DIR__ . '/includes/captcha.php'; // load DB helpers

$token = preg_replace('/[^A-Fa-f0-9]/', '', $_GET['token'] ?? '');
if (!$token) {
    http_response_code(404);
    exit;
}

// If GD is not installed we can't render PNGs.  Fallback to a plain SVG
// that shows the words with the correct one in green; this keeps the
// CAPTCHA usable in environments like the CLI server where extensions
// can't be added.
if (!function_exists('imagecreatetruecolor')) {
    // load captcha data same as below
    $data = null;
    if (!empty($_SESSION['captcha_data']) && ($_SESSION['captcha_data']['token'] ?? '') === $token) {
        $data = $_SESSION['captcha_data'];
    } else {
        $loaded = load_captcha_from_store($token);
        if (!$loaded) {
            http_response_code(404);
            exit;
        }
        $data = $loaded;
        $_SESSION['captcha_data'] = $data;
    }
    $words = $data['words'] ?? [];
    $correct = $data['correct_index'] ?? 0;

    // Build simple SVG – calculate width dynamically so all words fit
    $x = 20;
    $gap = 24;
    $char_width = 14;
    $total_width = $x;
    foreach ($words as $w) {
        $total_width += (strlen($w) * $char_width) + $gap;
    }
    $total_width += 20; // right padding
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total_width . '" height="140">';
    $y = 75;
    foreach ($words as $i => $w) {
        $color = $i === $correct ? 'green' : 'black';
        $svg .= '<text x="' . $x . '" y="' . $y . '" font-family="sans-serif" font-size="24" fill="' . $color . '">' . htmlspecialchars($w) . '</text>';
        $x += (strlen($w) * $char_width) + $gap;
    }
    $svg .= '</svg>';
    header('Content-Type: image/svg+xml');
    echo $svg;
    exit;
}

// Prefer session data when available, otherwise fall back to DB store so cookie-less clients work
$data = null;
if (!empty($_SESSION['captcha_data']) && ($_SESSION['captcha_data']['token'] ?? '') === $token) {
    $data = $_SESSION['captcha_data'];
} else {
    $loaded = load_captcha_from_store($token);
    if (!$loaded) {
        http_response_code(404);
        exit;
    }
    $data = $loaded;
    // Optionally store into session for convenience
    $_SESSION['captcha_data'] = $data;
}

$words = $data['words'] ?? [];
$correct_index = $data['correct_index'] ?? 0;

// Image settings
// determine required width dynamically based on word lengths
$width = 420;
$height = 140; // more vertical room to accommodate much larger text

// if TTF available we can calculate required width before creating image
$calc_width = 0;
$gap = 24;
if ($use_ttf) {
    foreach ($words as $w) {
        $bbox = @imagettfbbox($font_px, 0, $font_path, $w);
        if ($bbox !== false) {
            $calc_width += abs($bbox[2] - $bbox[0]) + $gap;
        }
    }
} else {
    foreach ($words as $w) {
        $text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $w) ?: $w;
        $calc_width += (strlen($text) * 8) + $gap;
    }
}
// if calculated width is larger, expand canvas + some padding
if ($calc_width + 40 > $width) {
    $width = $calc_width + 40;
}
$im = imagecreatetruecolor($width, $height);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
$green = imagecolorallocate($im, 32, 160, 64); // distinct green
$grey = imagecolorallocate($im, 200, 200, 200);

// Fill background
imagefilledrectangle($im, 0, 0, $width, $height, $white);

// Add noise lines
for ($i = 0; $i < 6; $i++) {
    $x1 = rand(0, $width);
    $y1 = rand(0, $height);
    $x2 = rand(0, $width);
    $y2 = rand(0, $height);
    imageline($im, $x1, $y1, $x2, $y2, $grey);
}

// Layout words horizontally with spacing
$font_path = __DIR__ . '/assets/fonts/DejaVuSans.ttf';
$use_ttf = function_exists('imagettftext') && file_exists($font_path);
$font_px = 28; // larger TTF pixel size for improved readability
$x = 20;
$y_baseline = intval($height / 2) + 20; // adjust baseline for larger height/font
$gap = 24;
foreach ($words as $idx => $w) {
    $color = ($idx === $correct_index) ? $green : $black;
    // Prefer rendering with TTF if available (supports UTF-8 and Turkish chars)
    if ($use_ttf) {
        $angle = rand(-6, 6);
        // Calculate bounding box width (safely)
        $bbox = @imagettfbbox($font_px, $angle, $font_path, $w);
        if ($bbox === false) {
            // TTF failed (font unreadable) — fallback to built-in rendering
            $use_ttf = false;
        } else {
            $text_width = abs($bbox[2] - $bbox[0]);
            $y = $y_baseline + rand(-8, 8);
            @imagettftext($im, $font_px, $angle, $x, $y, $color, $font_path, $w);
            $x += $text_width + $gap + rand(-6, 6);
        }
    }
    if (!$use_ttf) {
        // Transliterate to ASCII for built-in font rendering to avoid garbled multi-byte glyphs
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9] Remove', $w);
        } else {
            $text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $w) ?: $w;
            $text = preg_replace('/[^A-Za-z0-9]/', '', $text);
        }
        // Slight random vertical positioning for built-in font
        $y = rand(10, $height - 25);
        imagestring($im, 5, $x, $y, $text, $color);
        $x += (strlen($text) * 8) + $gap + rand(-2, 4);
    }
}

// Add some dots noise
for ($i = 0; $i < 200; $i++) {
    imagesetpixel($im, rand(0, $width), rand(0, $height), $grey);
}

// Output PNG
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
exit;
