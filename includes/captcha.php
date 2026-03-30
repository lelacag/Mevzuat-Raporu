<?php /* EN + TR comments used. */
/**
 * Simple CAPTCHA System - No JavaScript Required
 * Bot-resistant through CSS visual tricks and session validation
 */

// Generate random codes for CAPTCHA (increased entropy)
function generate_captcha_words() {
    // Generate 4 random short codes (6 characters) using [a-z]
    $words = [];
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    for ($i = 0; $i < 4; $i++) {
        $code = '';
        for ($j = 0; $j < 6; $j++) {
            $code .= $chars[random_int(0, strlen($chars)-1)];
        }
        $words[] = $code;
    }
    shuffle($words);
    return $words;
}

// Initialize CAPTCHA in session
function init_captcha() {
    // Rate limit token generation per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && is_captcha_generation_rate_exceeded($ip, CAPTCHA_GENERATION_LIMIT, CAPTCHA_GENERATION_WINDOW)) {
        return false; // caller must handle failure
    }

    $words = generate_captcha_words();
    $correct_index = random_int(0, count($words) - 1);
    $correct_word = $words[$correct_index];
    $token = bin2hex(random_bytes(16));

    // Store in session with expiry
    $_SESSION['captcha_data'] = [
        'answer' => strtolower($correct_word),
        'words' => $words,
        'correct_index' => $correct_index,
        'token' => $token,
        'time' => time(),
        'attempts' => 0 // track per-token attempts
    ];

    // Record generation for rate limiting
    if ($ip) record_captcha_generation($ip);

    // Also save to DB so clients that reject cookies can still fetch the image & verify by token
    save_captcha_to_store($token, $_SESSION['captcha_data']);

    return $token;
}

// Normalize text for CAPTCHA comparison: strip invisible chars, Unicode normalize, map Turkish chars, remove diacritics, and create ASCII fallback
function normalize_for_captcha($s) {
    $raw = (string)$s;
    $s = trim($raw);

    // Remove BOM / zero-width / directional / invisible characters commonly pasted
    $s = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $s);

    // Unicode normalization if available
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_C);
    }

    // Map common Turkish characters and a few Latin variants to ASCII-friendly equivalents
    $map = [
        'İ' => 'I','ı' => 'i','ş' => 's','Ş' => 's','ç' => 'c','Ç' => 'c','ğ' => 'g','Ğ' => 'g','ö' => 'o','Ö' => 'o','ü' => 'u','Ü' => 'u',
        'â' => 'a','ê' => 'e','î' => 'i','ô' => 'o','û' => 'u'
    ];
    $s = strtr($s, $map);

    // Remove combining marks (diacritics)
    $s = preg_replace('/\p{M}/u', '', $s);

    // Lowercase for normalized comparison
    $norm = mb_strtolower($s, 'UTF-8');

    // ASCII transliteration fallback
    $ascii = '';
    if (function_exists('transliterator_transliterate')) {
        $ascii = @transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9] Remove', $norm);
    } else {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $norm);
    }
    if ($ascii === false) $ascii = '';

    // Keep only letters and numbers for the final compare forms
    $ascii = preg_replace('/[^a-z0-9]/i', '', $ascii);
    $norm_clean = preg_replace('/[^a-z0-9]/iu', '', $norm);

    return [$norm_clean, $ascii, $raw];
}

// Verify CAPTCHA answer
function verify_captcha($user_input, $token) {
    // If no session copy exists, try DB store fallback for clients rejecting cookies
    if (!isset($_SESSION['captcha_data'])) {
        $loaded = load_captcha_from_store($token);
        if ($loaded === false) {
            return ['valid' => false, 'error' => 'CAPTCHA session expired'];
        }
        $data = $loaded;
        $using_db_store = true;
    } else {
        $data = $_SESSION['captcha_data'];
        $using_db_store = false;
    }
    
    // Verify token
    if ($data['token'] !== $token) {
        // If session data exists but token doesn't match, attempt load from store (e.g., session loss or overwritten token)
        $store = load_captcha_from_store($token);
        if ($store !== false && isset($store['token']) && $store['token'] === $token) {
            $data = $store;
            $using_db_store = true;
            // Keep session in sync for future validations
            $_SESSION['captcha_data'] = $data;
        } else {
            return ['valid' => false, 'error' => 'Invalid CAPTCHA token'];
        }
    }
    
    // Check expiry (5 minutes) — also respect DB-backed expiry on load
    if (time() - $data['time'] > CAPTCHA_STORE_TTL) {
        // Remove both session and DB copies if present
        if (isset($_SESSION['captcha_data']) && ($_SESSION['captcha_data']['token'] ?? '') === $token) unset($_SESSION['captcha_data']);
        delete_captcha_from_store($token);
        return ['valid' => false, 'error' => 'CAPTCHA expired, please try again'];
    }

    // Enforce minimum time (anti-bot): require at least CAPTCHA_MIN_SECONDS between generation and submit
    if (time() - $data['time'] < CAPTCHA_MIN_SECONDS) {
        // Do not reveal details to the client — generic error message
        if (isset($_SESSION['captcha_data']) && ($_SESSION['captcha_data']['token'] ?? '') === $token) unset($_SESSION['captcha_data']);
        delete_captcha_from_store($token);
        // Record IP failure for monitoring
        if (!empty($_SERVER['REMOTE_ADDR'])) record_captcha_ip_failure($_SERVER['REMOTE_ADDR']);
        return ['valid' => false, 'error' => 'CAPTCHA submitted too quickly'];
    }

    // Increment per-token attempt counter and enforce max attempts (CAPTCHA_MAX_ATTEMPTS)
    if (!isset($data['attempts'])) $data['attempts'] = 0;
    $data['attempts']++;
    // Persist attempts differently depending on whether we used session or DB store
    if (!empty($using_db_store)) {
        // we'll save back to DB later on failure
    } else {
        $_SESSION['captcha_data']['attempts'] = $data['attempts'];
    }
    $attempts_left = max(0, CAPTCHA_MAX_ATTEMPTS - $data['attempts']);
    if ($data['attempts'] > CAPTCHA_MAX_ATTEMPTS) {
        // Invalidate this token and record IP failure
        if (isset($_SESSION['captcha_data'])) unset($_SESSION['captcha_data']);
        delete_captcha_from_store($token);
        if (!empty($_SERVER['REMOTE_ADDR'])) record_captcha_ip_failure($_SERVER['REMOTE_ADDR']);
        if (defined('CAPTCHA_DEBUG') && CAPTCHA_DEBUG) {
            error_log('[CAPTCHA DEBUG] Too many attempts, token_prefix=' . substr($token,0,8) . ' expected=' . ($data['answer'] ?? ''));
        }
        return ['valid' => false, 'error' => 'Too many attempts for this CAPTCHA, please try again', 'attempts_left' => $attempts_left];
    }
    
    // Enforce language-specific character whitelist to avoid non-Turkish/strange characters
    // Sanitize input: remove zero-width/invisible chars and punctuation, then validate letters-only
    $current_lang = $_SESSION['lang'] ?? 'tr';
    // Remove zero-width and BOM-like characters which users may paste inadvertently
    $clean = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', (string)$user_input);
    // Remove punctuation and symbols, keep letters and spacing for sanitization, then strip spaces
    $clean = preg_replace('/[\p{P}\p{S}]/u', '', $clean);
    $clean = trim($clean);
    // Remove remaining whitespace to compare single-word captcha (users shouldn't type spaces)
    $clean = preg_replace('/\s+/u', '', $clean);

    if ($current_lang === 'tr') {
        // After sanitization, only Turkish letters are allowed
        if (!preg_match('/^[a-zçğıöşüİĞŞÜÖÇı]+$/iu', $clean)) {
            if (defined('CAPTCHA_DEBUG') && CAPTCHA_DEBUG) {
                $hex = function_exists('mb_convert_encoding') ? bin2hex(mb_convert_encoding((string)$user_input, 'UTF-8')) : bin2hex((string)$user_input);
                $msg = '[CAPTCHA DEBUG] token=' . substr($token,0,8) . ' invalid_chars_tr user_input=' . substr((string)$user_input,0,64) . ' clean=' . substr($clean,0,64) . ' user_hex=' . substr($hex,0,80) . ' using_db_store=' . ($using_db_store ? '1':'0');
                error_log($msg);
            }
            return ['valid' => false, 'error' => 'CAPTCHA contains invalid characters'];
        }
    } else {
        // English and other languages: only ASCII letters
        if (!preg_match('/^[a-z]+$/i', $clean)) {
            if (defined('CAPTCHA_DEBUG') && CAPTCHA_DEBUG) {
                $hex = function_exists('mb_convert_encoding') ? bin2hex(mb_convert_encoding((string)$user_input, 'UTF-8')) : bin2hex((string)$user_input);
                $msg = '[CAPTCHA DEBUG] token=' . substr($token,0,8) . ' invalid_chars_en user_input=' . substr((string)$user_input,0,64) . ' clean=' . substr($clean,0,64) . ' user_hex=' . substr($hex,0,80) . ' using_db_store=' . ($using_db_store ? '1':'0');
                error_log($msg);
            }
            return ['valid' => false, 'error' => 'CAPTCHA contains invalid characters'];
        }
    }

    // Verify answer with Unicode- and diacritics-tolerant comparison
    list($user_norm, $user_trans, $user_raw) = normalize_for_captcha($clean);
    list($ans_norm, $ans_trans, $ans_raw) = normalize_for_captcha($data['answer']);

    // Accept exact normalized match or ASCII-transliterated match (lenient with diacritics)
    $is_correct = ($user_norm === $ans_norm) || ($user_trans !== '' && $user_trans === $ans_trans);

    // Detailed debug logging if enabled (server-side only)
    if (defined('CAPTCHA_DEBUG') && CAPTCHA_DEBUG) {
        $raw_hex = function_exists('mb_convert_encoding') ? bin2hex(mb_convert_encoding($user_raw, 'UTF-8')) : bin2hex($user_raw);
        $expected_hex = function_exists('mb_convert_encoding') ? bin2hex(mb_convert_encoding($ans_raw, 'UTF-8')) : bin2hex($ans_raw);
        $msg = '[CAPTCHA DEBUG] token=' . substr($token,0,8)
             . ' expected_raw=' . substr($ans_raw,0,64)
             . ' expected_norm=' . $ans_norm
             . ' expected_ascii=' . $ans_trans
             . ' expected_hex=' . substr($expected_hex,0,80)
             . ' user_raw=' . substr($user_raw,0,64)
             . ' user_norm=' . $user_norm
             . ' user_ascii=' . $user_trans
             . ' user_hex=' . substr($raw_hex,0,80)
             . ' attempts=' . ($data['attempts'] ?? 0)
             . ' using_db_store=' . ($using_db_store ? '1':'0') . ' match=' . ($is_correct ? '1':'0');
        // Write to the main PHP error log only
        error_log($msg);
    }

    // Clear captcha data after a successful verification only, otherwise keep token until attempts exhausted
    if ($is_correct) {
        // remove both session and store copies
        if (isset($_SESSION['captcha_data']) && ($_SESSION['captcha_data']['token'] ?? '') === $token) unset($_SESSION['captcha_data']);
        delete_captcha_from_store($token);
    } else {
        // Record an IP failure for incorrect answers
        if (!empty($_SERVER['REMOTE_ADDR'])) record_captcha_ip_failure($_SERVER['REMOTE_ADDR']);
        // Persist updated attempts if using DB store
        save_captcha_to_store($token, $data);
    }
    
    if (!$is_correct) {
        return ['valid' => false, 'error' => 'Incorrect answer', 'attempts_left' => $attempts_left ?? 0];
    }
    
    return ['valid' => true, 'error' => null];
}

// Get CAPTCHA input from POST
function get_captcha_input_from_post() {
    return $_POST['captcha'] ?? '';
}

// Render CAPTCHA HTML
function render_captcha() {
    global $lang;
    
    // Ensure captcha CSS is loaded (cache-busted using filemtime)
    static $captcha_css_included = false;
    if (!$captcha_css_included) {
        $css_path = __DIR__ . '/../assets/css/captcha.css';
        $v = is_file($css_path) ? filemtime($css_path) : time();
        echo '<link rel="stylesheet" href="' . BASE_PATH . '/assets/css/captcha.css?v=' . $v . '">';
        $captcha_css_included = true;
    }

    // Load language if not available
    if (!isset($lang) || !is_array($lang)) {
        $current_lang = $_SESSION['lang'] ?? 'tr';
        $lang_file = __DIR__ . '/../lang/' . $current_lang . '.php';
        if (file_exists($lang_file)) {
            $lang = include $lang_file;
        } else {
            $lang = include __DIR__ . '/../lang/tr.php';
        }
    }
    
    $token = init_captcha();

    // If rate-limited or initialization failed, show user-friendly message
    if ($token === false) {
        echo '<div class="captcha-container"><p class="text-danger">Çok fazla CAPTCHA isteği tespit edildi. Lütfen birkaç dakika sonra tekrar deneyin.</p></div>'; 
        return;
    }

    // Ensure captcha_data is set
    if (!isset($_SESSION['captcha_data'])) {
        echo '<div class="captcha-container"><p class="text-danger">Error: CAPTCHA initialization failed.</p></div>'; 
        return;
    }

    $data = $_SESSION['captcha_data'];
    $words = $data['words'] ?? [];
    $correct_index = $data['correct_index'] ?? 0;
    
    $instruction = $lang['captcha_instruction'] ?? '<strong>YEŞİL</strong> renkteki kelimeyi yazın:';
    // UX tweak: shorter placeholder with capitalized words per request
    $placeholder = $lang['captcha_placeholder'] ?? 'Yeşil Kelimeyi Buraya Yazın';
    
    echo '<div class="captcha-container">';
    echo '<div class="captcha-inner">';
    echo '<p class="captcha-instruction">' . $instruction . '</p>'; 

    // Render CAPTCHA as an image (green word is rendered inside the image). No correct word is exposed in HTML.
    // generate image via the standard script path (profile.php will forward if needed)
    $img_url = BASE_PATH . '/captcha_image.php?token=' . urlencode($token);
    echo '<div class="captcha-image-wrap"><img src="' . htmlspecialchars($img_url, ENT_QUOTES, 'UTF-8') . '" alt="CAPTCHA image"></div>';
    // Simple refresh link (no JS required) — reloads the form to get a new CAPTCHA
    echo '<a href="' . full_url(invite_url()) . '#captcha" class="captcha-refresh">Yenile CAPTCHA</a>';

    echo '<input type="text" name="captcha" class="captcha-input" placeholder="' . htmlspecialchars($placeholder) . '" required autocomplete="off">';
    echo '<input type="hidden" name="captcha_token" value="' . htmlspecialchars($token) . '">';
    echo '</div>'; // .captcha-inner
    echo '</div>'; // .captcha-container
}

// Record IP-level failures and implement simple cooldown
function record_captcha_ip_failure($ip) {
    // sanitize IP; FILTER_SANITIZE_STRING is deprecated
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return;
    try {
        // Ensure table exists
        query("CREATE TABLE IF NOT EXISTS captcha_failures (
            ip VARCHAR(45) PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 0,
            last_failed_at DATETIME NOT NULL,
            first_failed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Insert or update failure row (attempts increments)
        query("INSERT INTO captcha_failures (ip, attempts, last_failed_at, first_failed_at) VALUES (?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_failed_at = NOW()", [$ip]);
    } catch (Exception $e) {
        error_log('[CAPTCHA] record_captcha_ip_failure DB error: ' . $e->getMessage());
    }
}

// Record when a CAPTCHA token was generated by IP (used for rate-limiting generations)
function record_captcha_generation($ip) {
    // sanitize IP; avoid deprecated constant
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return;
    try {
        query("CREATE TABLE IF NOT EXISTS captcha_generations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        query("INSERT INTO captcha_generations (ip, created_at) VALUES (?, NOW())", [$ip]);
    } catch (Exception $e) {
        error_log('[CAPTCHA] record_captcha_generation DB error: ' . $e->getMessage());
    }
}

function is_captcha_generation_rate_exceeded($ip, $limit = 30, $window_seconds = 300) {
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return false;

    try {
        $stmt = query("SELECT COUNT(*) AS cnt FROM captcha_generations WHERE ip = ? AND created_at > NOW() - INTERVAL ? SECOND", [$ip, $window_seconds]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return ((int)$row['cnt'] >= (int)$limit);
    } catch (Exception $e) {
        return false;
    }
}

// Return true if IP is currently blocked due to too many failures in the last window
function is_captcha_ip_blocked($ip, $threshold = 15, $window_seconds = 3600) {
    $ip = filter_var($ip, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if ($ip === '') return false;
    try {
        $stmt = query("SELECT attempts, last_failed_at FROM captcha_failures WHERE ip = ? LIMIT 1", [$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        // If last_failed_at older than window, treat as not blocked
        $last = strtotime($row['last_failed_at']);
        if ($last === false) return false;
        if (time() - $last > $window_seconds) return false;
        return ((int)$row['attempts'] >= $threshold);
    } catch (Exception $e) {
        return false;
    }
}

// Save captcha data to DB store (token -> JSON payload)
function save_captcha_to_store($token, $data) {
    try {
        query("CREATE TABLE IF NOT EXISTS captcha_store (
            token VARCHAR(64) PRIMARY KEY,
            payload TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $payload = json_encode($data);
        $expires = date('Y-m-d H:i:s', time() + CAPTCHA_STORE_TTL);
        query("INSERT INTO captcha_store (token, payload, created_at, expires_at) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at), created_at = NOW()", [$token, $payload, $expires]);
    } catch (Exception $e) {
        error_log('[CAPTCHA] save_captcha_to_store DB error: ' . $e->getMessage());
    }
}

// Load captcha data from DB by token, returns array or false
function load_captcha_from_store($token) {
    try {
        $stmt = query("SELECT payload, expires_at FROM captcha_store WHERE token = ? LIMIT 1", [$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if (strtotime($row['expires_at']) < time()) {
            // expired
            delete_captcha_from_store($token);
            return false;
        }
        $payload = json_decode($row['payload'], true);
        return $payload ?: false;
    } catch (Exception $e) {
        return false;
    }
}

function delete_captcha_from_store($token) {
    try {
        query("DELETE FROM captcha_store WHERE token = ?", [$token]);
    } catch (Exception $e) {
        // ignore
    }
}
?>
