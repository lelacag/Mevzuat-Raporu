<?php
/**
 * photo_upload.php — 2-step photo upload flow (zero JavaScript, PRG pattern).
 *
 * GET  /fotograf-yukle               → Step 1: file selector
 * POST action=preview                → validate + move to tmp + session → 302 step=2
 * GET  /fotograf-yukle?step=2        → Step 2: preview + EXIF + caption + tags
 * POST action=publish                → move tmp→permanent, insert DB, redirect
 * POST action=restart                → discard draft, back to step 1
 * GET  /fotograf-yukle?action=tmpimg → serve temp draft image (binary, no HTML)
 */

// ─── Helpers (defined before any output so tmpimg handler can call them) ──────

function _serve_photo_draft(): void {
    $draft = $_SESSION['photo_draft'] ?? null;
    if (!$draft) { http_response_code(404); echo 'Not Found'; return; }

    $token = $_GET['token'] ?? '';
    if (!$token || !hash_equals((string)($draft['token'] ?? ''), $token)) {
        http_response_code(403); echo 'Forbidden'; return;
    }
    if (isset($draft['expires']) && time() > $draft['expires']) {
        http_response_code(410); echo 'Gone'; return;
    }

    $base = realpath(__DIR__ . '/tmp/photo_drafts');
    if (!$base) { http_response_code(404); echo 'Not Found'; return; }

    $full = realpath(__DIR__ . '/' . ltrim((string)($draft['tmp_path'] ?? ''), '/'));
    if (!$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        http_response_code(404); echo 'Not Found'; return;
    }

    $allowed_mimes = ['image/jpeg', 'image/png'];
    $mime = $draft['mime'] ?? 'image/jpeg';
    if (!in_array($mime, $allowed_mimes, true)) {
        http_response_code(403); echo 'Forbidden'; return;
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Length: ' . filesize($full));
    readfile($full);
}

function photo_count_for_user(int $user_id): int {
    $stmt = db_connect()->prepare(
        "SELECT COUNT(*) FROM user_images WHERE user_id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}

function photo_user_hash(int $user_id): string {
    return hash('sha256', 'mevzuat_photo_' . $user_id);
}

function photo_upload_dir(int $user_id): string {
    $base = realpath(__DIR__) . '/users/' . photo_user_hash($user_id) . '/photos';
    if (!is_dir($base)) {
        mkdir($base, 0750, true);
    }
    return $base;
}

function photo_draft_dir(): string {
    $dir = realpath(__DIR__) . '/tmp/photo_drafts';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function exif_gps_to_decimal(array $exif): ?array {
    $lat_raw = $exif['GPS']['GPSLatitude'] ?? null;
    $lat_ref = strtoupper($exif['GPS']['GPSLatitudeRef'] ?? 'N');
    $lon_raw = $exif['GPS']['GPSLongitude'] ?? null;
    $lon_ref = strtoupper($exif['GPS']['GPSLongitudeRef'] ?? 'E');
    if (!$lat_raw || !$lon_raw) return null;

    $lat = _exif_dms_to_decimal((array)$lat_raw);
    $lon = _exif_dms_to_decimal((array)$lon_raw);
    if ($lat === null || $lon === null) return null;
    if ($lat_ref === 'S') $lat = -$lat;
    if ($lon_ref === 'W') $lon = -$lon;

    return [
        'lat'     => round($lat, 6),
        'lon'     => round($lon, 6),
        'lat_ref' => $lat_ref,
        'lon_ref' => $lon_ref,
    ];
}

function _exif_dms_to_decimal(array $dms): ?float {
    if (count($dms) < 3) return null;
    $parts = array_map(function ($r) {
        if (is_string($r) && strpos($r, '/') !== false) {
            [$n, $d] = explode('/', $r, 2);
            return (float)$d != 0 ? (float)$n / (float)$d : 0.0;
        }
        return (float)$r;
    }, array_values($dms));
    return $parts[0] + ($parts[1] / 60.0) + ($parts[2] / 3600.0);
}


/**
 * Verify file header bytes match known image signatures.
 * Catches executables / arbitrary data renamed to an image extension.
 * Returns true only if the raw bytes match JPEG or PNG magic numbers.
 */
function _photo_check_magic_bytes(string $path): bool {
    static $sigs = [
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89PNG\r\n\x1A\n"],
    ];
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $header = (string)fread($fh, 12);
    fclose($fh);
    foreach ($sigs as $patterns) {
        foreach ($patterns as $sig) {
            if (strncmp($header, $sig, strlen($sig)) === 0) return true;
        }
    }
    return false;
}

/**
 * Re-encode JPEG/PNG through GD to strip content appended after image data
 * (polyglot attacks). Fail-closed: caller must reject when this returns false.
 */
function _photo_reencode(string $path, string $mime): bool {
    // Max-MP gate keeps decompression bounded; 192M is enough for ≤25 MP + GD overhead.
    $prev_mem = @ini_set('memory_limit', '192M');
    $restore = static function () use ($prev_mem): void {
        if ($prev_mem !== false) {
            @ini_set('memory_limit', $prev_mem);
        }
    };

    if (!extension_loaded('gd')) {
        $restore();
        return false;
    }

    $img = null;
    $ok  = false;
    try {
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($path);
                if (!$img) {
                    return false;
                }
                $ok = (bool)imagejpeg($img, $path, 92);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($path);
                if (!$img) {
                    return false;
                }
                imagesavealpha($img, true);
                $ok = (bool)imagepng($img, $path, 6);
                break;
            default:
                return false;
        }
    } finally {
        if (is_resource($img) || $img instanceof GdImage) {
            imagedestroy($img);
        }
        $restore();
    }
    return $ok;
}

/**
 * Run a shell command with a hard wall-clock timeout (freeze prevention).
 * Uses GNU timeout when available; otherwise proc_open + proc_terminate.
 * Exit code 124 mirrors GNU timeout on wall-clock expiry.
 */
function _photo_exec_timeout(string $cmd, int $timeout_sec, array &$output, int &$rc): void {
    $output = [];
    $rc     = -1;
    $timeout_sec = max(1, $timeout_sec);

    $timeout_bin = null;
    foreach (['/usr/bin/timeout', '/bin/timeout'] as $cand) {
        if (is_executable($cand)) {
            $timeout_bin = $cand;
            break;
        }
    }

    if ($timeout_bin !== null) {
        $full = escapeshellarg($timeout_bin) . ' ' . (int)$timeout_sec . ' ' . $cmd;
        exec($full . ' 2>&1', $output, $rc);
        return;
    }

    if (!function_exists('proc_open')) {
        // Last resort: unbounded exec (should be rare). Prefer failing closed at caller if rc odd.
        exec($cmd . ' 2>&1', $output, $rc);
        return;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $start  = microtime(true);
    $timed_out = false;
    while (true) {
        $status = proc_get_status($process);
        $chunk1 = stream_get_contents($pipes[1]);
        $chunk2 = stream_get_contents($pipes[2]);
        if ($chunk1 !== false && $chunk1 !== '') {
            $stdout .= $chunk1;
        }
        if ($chunk2 !== false && $chunk2 !== '') {
            $stdout .= $chunk2;
        }
        if (!$status['running']) {
            $rc = (int)$status['exitcode'];
            break;
        }
        if ((microtime(true) - $start) >= $timeout_sec) {
            $timed_out = true;
            @proc_terminate($process, 9);
            $rc = 124;
            break;
        }
        usleep(100000);
    }

    $chunk1 = stream_get_contents($pipes[1]);
    $chunk2 = stream_get_contents($pipes[2]);
    if ($chunk1 !== false && $chunk1 !== '') {
        $stdout .= $chunk1;
    }
    if ($chunk2 !== false && $chunk2 !== '') {
        $stdout .= $chunk2;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $close_rc = proc_close($process);
    if (!$timed_out && $rc < 0) {
        $rc = (int)$close_rc;
    }
    $stdout = trim($stdout);
    if ($stdout === '') {
        $output = [];
    } else {
        $parts  = preg_split("/\r\n|\n|\r/", $stdout);
        $output = is_array($parts) ? $parts : [$stdout];
    }
}

/**
 * ClamAV scan with hard wall-clock timeout (freeze prevention).
 * Prefers clamdscan (daemon). If socket/permission fails (typical rc=2),
 * falls back to clamscan under the same timeout — never unbounded exec.
 * @return array{scanned:bool,infected:bool,timed_out:bool,rc:int,engine:string}
 */
function _photo_clamav_scan(string $path): array {
    $result = [
        'scanned'   => false,
        'infected'  => false,
        'timed_out' => false,
        'rc'        => -1,
        'engine'    => '',
    ];
    if ($path === '' || !is_file($path)) {
        return $result;
    }

    $candidates = [];
    // --fdpass: pass open FD to clamd so it can scan 0600 upload tmp files the
    // daemon user cannot open by path (avoids Access denied → slow clamscan).
    if (is_file('/usr/bin/clamdscan') && is_executable('/usr/bin/clamdscan')) {
        $candidates[] = [
            'bin'     => '/usr/bin/clamdscan',
            'args'    => '--fdpass --no-summary',
            'timeout' => 5,
            'engine'  => 'clamdscan',
        ];
    }
    // Last-resort standalone scanner (loads DB each call). Hard-capped; never unbounded.
    if (is_file('/usr/bin/clamscan') && is_executable('/usr/bin/clamscan')) {
        $candidates[] = [
            'bin'     => '/usr/bin/clamscan',
            'args'    => '--no-summary',
            'timeout' => 10,
            'engine'  => 'clamscan',
        ];
    }

    foreach ($candidates as $cand) {
        $cmd = escapeshellarg($cand['bin']) . ' ' . $cand['args'] . ' ' . escapeshellarg($path);
        $out = [];
        $rc  = -1;
        _photo_exec_timeout($cmd, (int)$cand['timeout'], $out, $rc);
        $result['rc']     = $rc;
        $result['engine'] = $cand['engine'];

        if ($rc === 124) {
            $result['timed_out'] = true;
            // Daemon timeout → try standalone once; standalone timeout stops (fail-closed).
            if ($cand['engine'] === 'clamdscan') {
                continue;
            }
            return $result;
        }
        if ($rc === 0 || $rc === 1) {
            $result['scanned']  = true;
            $result['infected'] = ($rc === 1);
            return $result;
        }
        // rc=2 etc. → try next candidate
    }

    return $result;
}

/**
 * Open a lock file for flock. If an existing file is unwritable (e.g. left
 * behind by root smoke tests), try to replace it so the web user is not
 * permanently locked out with a false "server busy" error.
 * @return resource|false
 */
function _photo_open_lock_file(string $path) {
    $fp = @fopen($path, 'c+');
    if ($fp) {
        @chmod($path, 0660);
        return $fp;
    }
    // Unwritable leftover (common after root-owned *.lock files) — recreate.
    if (is_file($path) && !is_writable($path)) {
        @unlink($path);
        $fp = @fopen($path, 'c+');
        if ($fp) {
            @chmod($path, 0660);
            return $fp;
        }
    }
    return false;
}

/**
 * Acquire non-blocking per-user lock + one of PHOTO_GLOBAL_CONCURRENT global slots.
 * Distinguishes true contention (busy) from lock-file open failures (error_log).
 * @return array{user:mixed,global:mixed,ok:bool,reason:string}
 */
function _photo_acquire_upload_locks(int $user_id): array {
    $dir = photo_draft_dir();
    $result = ['user' => null, 'global' => null, 'ok' => false, 'reason' => 'busy'];

    $user_path = $dir . '/upload_user_' . (int)$user_id . '.lock';
    $user_fp   = _photo_open_lock_file($user_path);
    if (!$user_fp) {
        error_log('photo_upload: lock_open_fail user_path=' . $user_path);
        $result['reason'] = 'open_fail';
        return $result;
    }
    if (!flock($user_fp, LOCK_EX | LOCK_NB)) {
        fclose($user_fp);
        $result['reason'] = 'user_busy';
        return $result;
    }
    $result['user'] = $user_fp;

    $slots = defined('PHOTO_GLOBAL_CONCURRENT') ? (int)PHOTO_GLOBAL_CONCURRENT : 2;
    $slots = max(1, $slots);
    $opened_any = false;
    for ($i = 0; $i < $slots; $i++) {
        $gpath = $dir . '/upload_global_' . $i . '.lock';
        $gfp   = _photo_open_lock_file($gpath);
        if (!$gfp) {
            error_log('photo_upload: lock_open_fail global_path=' . $gpath);
            continue;
        }
        $opened_any = true;
        if (flock($gfp, LOCK_EX | LOCK_NB)) {
            $result['global'] = $gfp;
            $result['ok']     = true;
            $result['reason'] = 'ok';
            return $result;
        }
        fclose($gfp);
    }

    // No global slot — release user lock
    flock($user_fp, LOCK_UN);
    fclose($user_fp);
    $result['user']   = null;
    $result['reason'] = $opened_any ? 'global_busy' : 'open_fail';
    return $result;
}

/** @param array{user:mixed,global:mixed,ok?:bool} $locks */
function _photo_release_upload_locks(array $locks): void {
    foreach (['global', 'user'] as $k) {
        $fp = $locks[$k] ?? null;
        if ($fp === null) {
            continue;
        }
        // PHP 7 resources and PHP 8+ stream objects both work with flock/fclose
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/** Delete draft files older than PHOTO_DRAFT_TTL (best-effort, lightweight). */
function _photo_gc_stale_drafts(): void {
    $dir = photo_draft_dir();
    $ttl = defined('PHOTO_DRAFT_TTL') ? (int)PHOTO_DRAFT_TTL : 3600;
    $now = time();
    foreach (glob($dir . '/drft_*.*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $ttl) {
            @unlink($file);
        }
    }
}

// ─── Inline temp-image serve (before header.php to allow binary output) ───────
if (isset($_GET['action']) && $_GET['action'] === 'tmpimg') {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db.php';
    _serve_photo_draft();
    exit;
}

// ─── Normal page flow ─────────────────────────────────────────────────────────
$page_title = 'Fotoğraf Yükle';
require_once __DIR__ . '/includes/config.php';
$extra_head = '<link rel="stylesheet" href="' . BASE_PATH . '/assets/css/photos.css">';
require_once __DIR__ . '/includes/header.php';

// ─── Auth ─────────────────────────────────────────────────────────────────────
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

// ─── Context ──────────────────────────────────────────────────────────────────
$_ctx_raw = $_POST['context'] ?? ($_GET['context'] ?? 'profile');
$context  = in_array($_ctx_raw, ['profile', 'group'], true) ? $_ctx_raw : 'profile';
$group_id = ($context === 'group') ? (int)($_POST['group_id'] ?? ($_GET['group_id'] ?? 0)) : 0;

$group = null;
if ($context === 'group' && $group_id > 0) {
    $pdo   = db_connect();
    $gstmt = $pdo->prepare(
        "SELECT gt.id, gt.name, gt.slug FROM groups_table gt
         JOIN group_members gm ON gm.group_id = gt.id
         WHERE gt.id = ? AND gm.user_id = ? LIMIT 1"
    );
    $gstmt->execute([$group_id, $current_user_id]);
    $group = $gstmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        header('Location: ' . BASE_PATH . '/gruplar');
        exit;
    }
}

// ─── Constants ────────────────────────────────────────────────────────────────
const PHOTO_FREE_LIMIT         = 10;
const PHOTO_MIN_PIXELS         = 800000;
const PHOTO_MAX_PIXELS         = 25000000; // ~25 MP — caps GD decompression RAM
const PHOTO_MAX_SIDE           = 10000;    // reject pathological dimensions
const PHOTO_MAX_BYTES          = 8 * 1024 * 1024; // 8 MB hard cap
const PHOTO_LICENCE            = 'Mevzuat Raporu';
const PHOTO_ALLOWED_MIMES      = ['image/jpeg', 'image/png'];
const PHOTO_ALLOWED_EXTS       = ['jpg', 'jpeg', 'png'];
const PHOTO_DRAFT_TTL          = 3600; // seconds
const PHOTO_GLOBAL_CONCURRENT  = 2;
const PHOTO_PREVIEW_TIME_LIMIT = 30;   // seconds for heavy preview work
const PHOTO_RATE_FREE          = 5;    // preview attempts per hour (free)
const PHOTO_RATE_PREMIUM       = 30;   // preview attempts per hour (premium/admin)
const PHOTO_RATE_WINDOW        = 3600;
const PHOTO_RATE_IP            = 20;   // preview attempts per hour per IP

// ─── State ────────────────────────────────────────────────────────────────────
$is_premium    = function_exists('is_user_premium') ? is_user_premium($current_user_id) : false;
$is_admin_usr  = function_exists('is_admin') ? is_admin() : false;
$current_count = photo_count_for_user($current_user_id);
$at_limit      = !$is_premium && !$is_admin_usr && $current_count >= PHOTO_FREE_LIMIT;

$step   = (int)($_GET['step'] ?? 1);
$action = $_POST['action'] ?? '';
$errors = [];

// Best-effort stale draft cleanup (cheap; once per request when visiting upload UI)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    _photo_gc_stale_drafts();
}

// ─── Detect PHP-level POST oversize (post_max_size exceeded) ─────────────────
// When this happens PHP silently empties $_POST and $_FILES, hiding the cause.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $cl     = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $pm_raw = trim((string)ini_get('post_max_size'));
    $pm     = (int)$pm_raw;
    if ($pm_raw !== '') {
        $last = strtolower(substr($pm_raw, -1));
        if ($last === 'g') $pm = (int)$pm_raw * 1073741824;
        elseif ($last === 'm') $pm = (int)$pm_raw * 1048576;
        elseif ($last === 'k') $pm = (int)$pm_raw * 1024;
    }
    if ($cl > 0 && $cl > $pm) {
        $errors[] = 'Fotoğraf boyutu çok büyük (maksimum ' . round($pm / 1048576) . ' MB). Lütfen daha küçük bir dosya seçin veya fotoğrafı sıkıştırın.';
    }
}

// Expire stale draft
$draft = $_SESSION['photo_draft'] ?? null;
if ($draft && isset($draft['expires']) && time() > $draft['expires']) {
    if (!empty($draft['tmp_path'])) {
        @unlink(__DIR__ . '/' . ltrim($draft['tmp_path'], '/'));
    }
    unset($_SESSION['photo_draft']);
    $draft = null;
}

// ─── POST: action=restart ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restart') {
    if ($draft && !empty($draft['tmp_path'])) {
        @unlink(__DIR__ . '/' . ltrim($draft['tmp_path'], '/'));
    }
    unset($_SESSION['photo_draft']);
    $qs = $context === 'group' && $group
        ? '?context=group&group_id=' . (int)$group['id']
        : '?context=profile';
    header('Location: ' . BASE_PATH . '/fotograf-yukle' . $qs);
    exit;
}

// ─── POST: action=preview (Step 1 → 2) ───────────────────────────────────────
// Order: CSRF → rate → lock → size → magic → MIME → ext → dimensions → EXIF
//        → session_write_close → GD re-encode → ClamAV (timeout) → draft → redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview') {
    $upload_locks = ['user' => null, 'global' => null, 'ok' => false, 'reason' => ''];
    $session_closed_for_heavy = false;
    $scanned = false;
    $exif_data = [];
    $tmp_path  = '';
    $orig_name = '';
    $ext       = '';
    $mime      = '';
    $file_size = 0;

    try {
        @set_time_limit(PHOTO_PREVIEW_TIME_LIMIT);

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Geçersiz istek. Lütfen tekrar deneyin.';
        }

        // Rate limits (user + IP) before any heavy work
        if (empty($errors) && function_exists('check_rate_limit')) {
            $rate_max = ($is_premium || $is_admin_usr) ? PHOTO_RATE_PREMIUM : PHOTO_RATE_FREE;
            $uid_key  = (string)(int)$current_user_id;
            $ip       = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            if (!check_rate_limit('photo_preview', $uid_key, $rate_max, PHOTO_RATE_WINDOW)) {
                error_log('photo_upload: rate_limit user=' . $uid_key);
                $errors[] = 'Çok fazla yükleme denemesi yaptınız. Lütfen bir süre sonra tekrar deneyin.';
            } elseif (!check_rate_limit('photo_preview_ip', $ip, PHOTO_RATE_IP, PHOTO_RATE_WINDOW)) {
                error_log('photo_upload: rate_limit ip user=' . $uid_key);
                $errors[] = 'Bu ağdan çok fazla yükleme denemesi var. Lütfen bir süre sonra tekrar deneyin.';
            }
        }

        if (empty($errors) && $at_limit) {
            $errors[] = PHOTO_FREE_LIMIT . ' fotoğraf limitine ulaştınız. Daha fazla yüklemek için premium üye olun.';
        }

        // Concurrency locks (1 per user + global slots)
        if (empty($errors)) {
            $upload_locks = _photo_acquire_upload_locks((int)$current_user_id);
            if (empty($upload_locks['ok'])) {
                $why = (string)($upload_locks['reason'] ?? 'busy');
                error_log('photo_upload: lock_fail reason=' . $why . ' user=' . (int)$current_user_id);
                // open_fail used to look like "busy" forever when root left unwritable *.lock files
                if ($why === 'open_fail') {
                    $errors[] = 'Yükleme kilidi oluşturulamadı. Lütfen tekrar deneyin; sorun sürerse site yöneticisine bildirin.';
                } else {
                    $errors[] = 'Sunucu meşgul, lütfen birkaç saniye sonra tekrar deneyin.';
                }
            }
        }

        $file_err = $_FILES['photo_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($errors) && $file_err !== UPLOAD_ERR_OK) {
            $errors[] = ($file_err === UPLOAD_ERR_INI_SIZE || $file_err === UPLOAD_ERR_FORM_SIZE)
                ? 'Dosya boyutu çok büyük (maksimum ' . round(PHOTO_MAX_BYTES / 1048576) . ' MB). Lütfen daha küçük bir dosya seçin.'
                : 'Lütfen bir fotoğraf dosyası seçin.';
        }

        if (empty($errors)) {
            $file_size = (int)($_FILES['photo_file']['size'] ?? 0);
            if ($file_size <= 0 || $file_size > PHOTO_MAX_BYTES) {
                $errors[] = 'Dosya boyutu çok büyük (maksimum ' . round(PHOTO_MAX_BYTES / 1048576) . ' MB). Lütfen daha küçük bir dosya seçin.';
            }
        }

        $tmp_path  = (string)($_FILES['photo_file']['tmp_name'] ?? '');
        $orig_name = (string)($_FILES['photo_file']['name'] ?? '');

        if (empty($errors) && ($tmp_path === '' || !is_uploaded_file($tmp_path))) {
            $errors[] = 'Lütfen bir fotoğraf dosyası seçin.';
        }

        // Magic bytes → MIME → extension (cheap rejects)
        if (empty($errors) && !_photo_check_magic_bytes($tmp_path)) {
            $errors[] = 'Bu dosya geçerli bir fotoğraf değildir.';
        }

        if (empty($errors)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? (string)finfo_file($finfo, $tmp_path) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            if (!$mime || !in_array($mime, PHOTO_ALLOWED_MIMES, true)) {
                $errors[] = 'Yalnızca JPEG veya PNG formatındaki fotoğraflar kabul edilmektedir.';
            }
        }

        if (empty($errors)) {
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, PHOTO_ALLOWED_EXTS, true)) {
                $errors[] = 'Geçersiz dosya uzantısı. Yalnızca .jpg, .jpeg veya .png kabul edilir.';
            }
        }

        // Dimensions before EXIF/GD (min + max MP + max side)
        if (empty($errors)) {
            $img_size = @getimagesize($tmp_path);
            if (!$img_size || empty($img_size[0]) || empty($img_size[1])) {
                $errors[] = 'Fotoğraf boyutları okunamadı. Lütfen geçerli bir JPEG veya PNG yükleyin.';
            } else {
                $w = (int)$img_size[0];
                $h = (int)$img_size[1];
                $pixels = $w * $h;
                if ($w > PHOTO_MAX_SIDE || $h > PHOTO_MAX_SIDE) {
                    $errors[] = 'Fotoğraf kenar uzunluğu çok büyük (maksimum ' . PHOTO_MAX_SIDE . ' px).';
                } elseif ($pixels > PHOTO_MAX_PIXELS) {
                    $errors[] = 'Fotoğraf çözünürlüğü çok yüksek (maksimum ~' . round(PHOTO_MAX_PIXELS / 1000000) . ' MP). Lütfen daha küçük bir görüntü yükleyin.';
                } elseif ($pixels < PHOTO_MIN_PIXELS) {
                    $errors[] = 'Fotoğrafın çözünürlüğü çok düşük. Lütfen kameradan alınan yüksek çözünürlüklü bir fotoğraf yükleyin.';
                }
            }
        }

        // EXIF camera/scanner policy (after we know it is a bounded image)
        if (empty($errors)) {
            $raw_exif = @exif_read_data($tmp_path, 'IFD0,EXIF,GPS', true);
            if (empty($raw_exif)) {
                $errors[] = 'Bu fotoğraf bir dijital kamera veya tarayıcıdan gelmediği için kabul edilemiyor. '
                          . 'Lütfen doğrudan kameradan veya yüksek çözünürlüklü tarayıcıdan elde edilmiş bir fotoğraf yükleyin.';
            } else {
                $make  = isset($raw_exif['IFD0']['Make'])
                    ? substr(trim((string)$raw_exif['IFD0']['Make']), 0, 100) : null;
                $model = isset($raw_exif['IFD0']['Model'])
                    ? substr(trim((string)$raw_exif['IFD0']['Model']), 0, 100) : null;

                if (empty($make) && empty($model)) {
                    $errors[] = 'Bu fotoğraf bir dijital kamera veya tarayıcıdan gelmediği için kabul edilemiyor. '
                              . 'Lütfen doğrudan kameradan veya yüksek çözünürlüklü tarayıcıdan elde edilmiş bir fotoğraf yükleyin.';
                } else {
                    $blocked_makes = [
                        'apple', 'google', 'huawei', 'xiaomi', 'oneplus', 'oppo',
                        'vivo', 'motorola', 'motorola mobility', 'lg electronics', 'lg',
                        'nokia', 'htc', 'zte', 'blackberry', 'realme', 'tecno', 'infinix',
                        'meizu', 'lenovo', 'honor', 'nothing', 'fairphone', 'umidigi',
                    ];
                    $phone_model_keywords = [
                        'iphone', 'galaxy', 'pixel', 'android', 'sm-', 'xperia',
                        'redmi', 'poco', 'moto ', 'nexus', 'lumia', 'zenfone',
                        'find x', 'reno', 'a52', 'a72', 'note ', 'pro max', 'ultra',
                    ];
                    $make_lower     = strtolower((string)($make ?? ''));
                    $model_lower    = strtolower((string)($model ?? ''));
                    $software_lower = strtolower((string)($raw_exif['IFD0']['Software'] ?? ''));

                    $is_phone = in_array($make_lower, $blocked_makes, true);
                    if (!$is_phone) {
                        foreach ($phone_model_keywords as $kw) {
                            if (str_contains($model_lower, $kw)) {
                                $is_phone = true;
                                break;
                            }
                        }
                    }
                    if (!$is_phone && (str_contains($software_lower, 'ios') || str_contains($software_lower, 'android'))) {
                        $is_phone = true;
                    }

                    if ($is_phone) {
                        $errors[] = 'Cep telefonu fotoğrafları kabul edilmemektedir. '
                                  . 'Lütfen dijital fotoğraf makinesi (DSLR/mirrorless) veya tarayıcıdan elde edilmiş bir fotoğraf yükleyin.';
                    }
                }

                if (empty($errors)) {
                    $fs_raw = $raw_exif['EXIF']['FileSource'] ?? null;
                    $source_type = 'camera';
                    if (is_string($fs_raw)) {
                        $fs_ord = ord($fs_raw);
                        if ($fs_ord === 1 || $fs_ord === 2) {
                            $source_type = 'scan';
                        }
                    }
                    // Only source_type is kept — make/model/datetime/GPS never persisted
                    $exif_data = ['source_type' => $source_type];
                }
            }
        }

        // Release session lock before GD + ClamAV so other same-user requests do not queue
        if (empty($errors) && session_status() === PHP_SESSION_ACTIVE) {
            // Drop previous draft file pointer from session data we will replace later
            $prev_draft_path = null;
            if (!empty($draft['tmp_path'])) {
                $prev_draft_path = __DIR__ . '/' . ltrim((string)$draft['tmp_path'], '/');
            }
            session_write_close();
            $session_closed_for_heavy = true;
            if ($prev_draft_path && is_file($prev_draft_path)) {
                @unlink($prev_draft_path);
            }
        }

        // GD re-encode (fail-closed polyglot strip)
        if (empty($errors)) {
            if (!_photo_reencode($tmp_path, $mime)) {
                error_log('photo_upload: reencode_fail user=' . (int)$current_user_id . ' size=' . $file_size . ' mime=' . $mime);
                $errors[] = 'Fotoğraf işlenemedi. Lütfen farklı bir JPEG veya PNG deneyin.';
            } else {
                // Re-validate after rewrite
                if (!_photo_check_magic_bytes($tmp_path)) {
                    $errors[] = 'Fotoğraf işlendikten sonra doğrulanamadı.';
                } else {
                    $finfo2 = finfo_open(FILEINFO_MIME_TYPE);
                    $mime2  = $finfo2 ? (string)finfo_file($finfo2, $tmp_path) : '';
                    if ($finfo2) {
                        finfo_close($finfo2);
                    }
                    if (!$mime2 || !in_array($mime2, PHOTO_ALLOWED_MIMES, true)) {
                        $errors[] = 'Fotoğraf işlendikten sonra format doğrulanamadı.';
                    } else {
                        $mime = $mime2;
                    }
                    $new_size = (int)@filesize($tmp_path);
                    if ($new_size <= 0 || $new_size > PHOTO_MAX_BYTES) {
                        $errors[] = 'İşlenen dosya boyutu kabul edilen sınırların dışında.';
                    }
                }
            }
        }

        // ClamAV: timed clamdscan, then timed clamscan fallback; fail-closed
        if (empty($errors)) {
            if (!function_exists('exec') && !function_exists('proc_open')) {
                error_log('photo_upload: clamav_unavailable exec/proc_open disabled user=' . (int)$current_user_id);
                $errors[] = 'Güvenlik taraması şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.';
            } else {
                $scan = _photo_clamav_scan($tmp_path);
                if (!empty($scan['timed_out'])) {
                    error_log('photo_upload: clamav_timeout engine=' . ($scan['engine'] ?? '') . ' user=' . (int)$current_user_id . ' size=' . $file_size);
                    $errors[] = 'Güvenlik taraması zaman aşımına uğradı. Lütfen daha sonra tekrar deneyin.';
                } elseif (!empty($scan['infected'])) {
                    @unlink($tmp_path);
                    $errors[] = 'Yüklenen dosya zararlı yazılım içerdiği için reddedildi.';
                } elseif (empty($scan['scanned'])) {
                    error_log('photo_upload: clamav_fail engine=' . ($scan['engine'] ?? '') . ' rc=' . (int)($scan['rc'] ?? -1) . ' user=' . (int)$current_user_id);
                    $errors[] = 'Güvenlik taraması şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.';
                } else {
                    $scanned = true;
                }
            }
        }

        // Stage draft + reopen session briefly for $_SESSION write
        if (empty($errors)) {
            $draft_token = bin2hex(random_bytes(16));
            $draft_dir   = photo_draft_dir();
            $draft_name  = 'drft_' . $draft_token . '.' . $ext;
            $draft_path  = $draft_dir . '/' . $draft_name;
            $draft_rel   = 'tmp/photo_drafts/' . $draft_name;

            if (!@move_uploaded_file($tmp_path, $draft_path)) {
                // Re-encode may have rewritten tmp in place; fall back to rename/copy
                if (is_file($tmp_path) && (@rename($tmp_path, $draft_path) || (@copy($tmp_path, $draft_path) && @unlink($tmp_path)))) {
                    // ok
                } else {
                    $errors[] = 'Dosya kaydedilemedi. Lütfen tekrar deneyin.';
                }
            }

            if (empty($errors)) {
                $draft_payload = [
                    'token'         => $draft_token,
                    'tmp_path'      => $draft_rel,
                    'ext'           => $ext,
                    'mime'          => $mime,
                    'orig_name'     => basename($orig_name),
                    'exif'          => $exif_data,
                    'context'       => $context,
                    'group_id'      => $group_id,
                    'expires'       => time() + PHOTO_DRAFT_TTL,
                    'virus_scanned' => $scanned,
                ];

                if ($session_closed_for_heavy) {
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        @session_start();
                    }
                }
                $_SESSION['photo_draft'] = $draft_payload;

                $qs = '?step=2';
                if ($context === 'group' && $group) {
                    $qs .= '&context=group&group_id=' . (int)$group['id'];
                }
                _photo_release_upload_locks($upload_locks);
                $upload_locks = ['user' => null, 'global' => null, 'ok' => false];
                header('Location: ' . BASE_PATH . '/fotograf-yukle' . $qs);
                exit;
            }
        }
    } finally {
        _photo_release_upload_locks($upload_locks);
    }

    // Errors: ensure session is available for page render (CSRF etc.)
    if ($session_closed_for_heavy && session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $step = 1; // fall through to step 1 view with errors
}

// ─── POST: action=publish (Step 2 → done) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'publish') {

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Geçersiz istek. Lütfen tekrar deneyin.';
    }

    $draft = $_SESSION['photo_draft'] ?? null;

    if (empty($errors) && !$draft) {
        $errors[] = 'Taslak bulunamadı. Lütfen tekrar başlayın.';
        $step = 1;
    }

    if (empty($errors) && isset($draft['expires']) && time() > $draft['expires']) {
        if (!empty($draft['tmp_path'])) @unlink(__DIR__ . '/' . ltrim($draft['tmp_path'], '/'));
        unset($_SESSION['photo_draft']);
        $draft = null;
        $errors[] = 'Taslak süresi doldu. Lütfen fotoğrafı tekrar yükleyin.';
        $step = 1;
    }

    if (empty($errors) && $at_limit) {
        $errors[] = PHOTO_FREE_LIMIT . ' fotoğraf limitine ulaştınız.';
        $step = 2;
    }

    $draft_abs = null;
    if (empty($errors)) {
        $draft_base = realpath(__DIR__ . '/tmp/photo_drafts');
        $draft_abs  = realpath(__DIR__ . '/' . ltrim((string)($draft['tmp_path'] ?? ''), '/'));
        if (!$draft_abs || !$draft_base
            || strpos($draft_abs, $draft_base . DIRECTORY_SEPARATOR) !== 0
            || !is_file($draft_abs)) {
            $errors[] = 'Geçici dosya bulunamadı. Lütfen fotoğrafı tekrar yükleyin.';
            $step = 1;
        }
    }

    if (empty($errors)) {
        $upload_dir = photo_upload_dir($current_user_id);
        $safe_name  = bin2hex(random_bytes(16)) . '.' . ($draft['ext'] ?? 'jpg');
        $dest_path  = $upload_dir . '/' . $safe_name;

        $moved = rename($draft_abs, $dest_path);
        if (!$moved && copy($draft_abs, $dest_path)) {
            @unlink($draft_abs);
            $moved = true;
        }
        if (!$moved) {
            $errors[] = 'Dosya taşınamadı. Lütfen tekrar deneyin.';
            $step = 2;
        }
    }

    if (empty($errors)) {
        $caption = mb_substr(trim($_POST['caption'] ?? ''), 0, 500);

        // Trending checkboxes + custom text → unified tags field
        $trending_checked = is_array($_POST['trending_tags'] ?? null)
            ? array_map('strval', (array)$_POST['trending_tags'])
            : [];
        $custom_parts = array_filter(array_map('trim', explode(',', trim($_POST['tags_custom'] ?? ''))));
        // Also extract #hashtags written inline in the caption
        $_cap_hashtags = [];
        preg_match_all('/#([\p{L}\p{N}_\-]+)/u', $caption, $_cap_m);
        if (!empty($_cap_m[1])) {
            $_cap_hashtags = $_cap_m[1];
        }
        $all_tags   = array_unique(array_filter(array_merge($trending_checked, $custom_parts, $_cap_hashtags)));
        unset($_cap_hashtags, $_cap_m);
        $tags_clean = mb_substr(implode(', ', $all_tags), 0, 255);

        $exif_d        = $draft['exif'] ?? [];
        $source_type   = $exif_d['source_type'] ?? 'camera';
        $publish_date  = date('Y-m-d');

        // Context from draft (authoritative, not from GET/POST)
        $pub_context  = $draft['context'] ?? 'profile';
        $pub_group_id = (int)($draft['group_id'] ?? 0);

        $pdo = db_connect();
        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO user_images
                    (user_id, filename, original_filename, source_type, publish_date, tags, licence)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $current_user_id,
                $safe_name,
                $draft['orig_name'] ?? 'photo',
                $source_type,
                $publish_date,
                $tags_clean ?: null,
                PHOTO_LICENCE,
            ]);
            $image_id = (int)$pdo->lastInsertId();

            $post_content = $caption !== '' ? $caption : '📷 Fotoğraf';
            // Append image tags as #hashtags to post content so trending/search picks them up
            if (!empty($all_tags)) {
                preg_match_all('/#([\p{L}\p{N}_\-]+)/u', $post_content, $_cap_existing);
                $_existing_lower = array_map('mb_strtolower', $_cap_existing[1] ?? []);
                $_to_append = [];
                foreach ($all_tags as $_at) {
                    if (!in_array(mb_strtolower(ltrim($_at, '#')), $_existing_lower, true)) {
                        $_to_append[] = '#' . ltrim($_at, '#');
                    }
                }
                if (!empty($_to_append)) {
                    $post_content .= ' ' . implode(' ', $_to_append);
                }
                unset($_cap_existing, $_existing_lower, $_to_append, $_at);
            }

            if ($pub_context === 'group' && $pub_group_id > 0) {
                $gp_ins = $pdo->prepare(
                    "INSERT INTO group_posts (group_id, user_id, content, image_id, scheduled_at)
                     VALUES (?, ?, ?, ?, NULL)"
                );
                $gp_ins->execute([$pub_group_id, $current_user_id, $post_content, $image_id]);
                $new_post_id = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE user_images SET group_post_id = ? WHERE id = ?")
                    ->execute([$new_post_id, $image_id]);
                $gs = $pdo->prepare("SELECT slug FROM groups_table WHERE id = ? LIMIT 1");
                $gs->execute([$pub_group_id]);
                $pub_slug = (string)($gs->fetchColumn() ?: '');
                $redirect = BASE_PATH . '/g/' . rawurlencode($pub_slug);
            } else {
                $p_ins = $pdo->prepare(
                    "INSERT INTO posts (user_id, content, image_id, approved) VALUES (?, ?, ?, 1)"
                );
                $p_ins->execute([$current_user_id, $post_content, $image_id]);
                $new_post_id = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE user_images SET post_id = ? WHERE id = ?")
                    ->execute([$new_post_id, $image_id]);
                $redirect = BASE_PATH . '/' . rawurlencode(
                    function_exists('get_user') ? (get_user($current_user_id)['username'] ?? '') : ''
                );
            }

            $pdo->commit();
            unset($_SESSION['photo_draft']);
            header('Location: ' . ($redirect ?: BASE_PATH . '/'));
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            @unlink($dest_path);
            error_log('photo_upload publish: ' . $e->getMessage());
            $errors[] = 'Fotoğraf kaydedilirken bir hata oluştu. Lütfen tekrar deneyin.';
            $step = 2;
        }
    }
}

// ─── Step 2 guard ─────────────────────────────────────────────────────────────
$draft = $_SESSION['photo_draft'] ?? null;
if ($step === 2) {
    if (!$draft || empty($draft['tmp_path'])
        || !is_file(__DIR__ . '/' . ltrim($draft['tmp_path'], '/'))) {
        header('Location: ' . BASE_PATH . '/fotograf-yukle');
        exit;
    }
}

// ─── Trending tags for step 2 ─────────────────────────────────────────────────
$trending_tags = [];
if ($step === 2 && function_exists('get_trending_tags')) {
    $trending_tags = get_trending_tags(12);
}

// ─── Back-link ────────────────────────────────────────────────────────────────
$current_username = function_exists('get_user') ? (get_user($current_user_id)['username'] ?? '') : '';
if ($context === 'group' && $group) {
    $back_url   = BASE_PATH . '/g/' . rawurlencode($group['slug']);
    $back_label = '← Gruba Dön';
    $page_h1    = '📷 Fotoğraf Gönderisi — ' . htmlspecialchars($group['name']);
} else {
    $back_url   = BASE_PATH . '/' . rawurlencode($current_username);
    $back_label = '← Profile Dön';
    $page_h1    = '📷 Fotoğraf Gönderisi Oluştur';
}
?>

<div class="page-container">
<div class="photo-upload-container">

    <div class="photo-upload-header">
        <a href="<?= $back_url ?>" class="back-link"><?= $back_label ?></a>
        <h1 class="photo-upload-title"><?= $page_h1 ?></h1>
    </div>

    <!-- Step indicator -->
    <div class="photo-step-indicator">
        <div class="photo-step<?= $step >= 1 ? ' active' : '' ?>">
            <span class="photo-step-dot"></span>
            <span class="photo-step-label">Fotoğrafı Seç</span>
        </div>
        <div class="photo-step-line<?= $step >= 2 ? ' active' : '' ?>"></div>
        <div class="photo-step<?= $step >= 2 ? ' active' : '' ?>">
            <span class="photo-step-dot"></span>
            <span class="photo-step-label">Önizle &amp; Paylaş</span>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="photo-upload-errors">
        <?php foreach ($errors as $e): ?>
            <p class="photo-error-msg">⚠ <?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php if ($step === 2 && $draft): ?>

    <!-- ── STEP 2: Preview ────────────────────────────────────────────────── -->

    <div class="photo-preview-wrap">
        <img src="<?= BASE_PATH ?>/fotograf-yukle?action=tmpimg&amp;token=<?= htmlspecialchars($draft['token']) ?>"
             alt="Fotoğraf Önizlemesi"
             class="photo-preview-img"
             loading="lazy">
    </div>

    <?php if (!empty($draft['virus_scanned'])): ?>
    <div class="photo-scan-badge">
        <span class="photo-scan-badge-icon">🛡</span>
        <div class="photo-scan-badge-text">
            <strong>Virüs Taramasından Geçirildi</strong>
            <span>ClamAV ile tarandı &middot; <?= date('d M Y') ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $exif_d      = $draft['exif'] ?? [];
    $source_type = $exif_d['source_type'] ?? 'camera';
    $source_label = $source_type === 'scan' ? 'Tarayıcı' : 'Dijital fotoğraf makinesi';
    ?>
    <div class="photo-source-card">
        <div class="photo-source-icon">✅</div>
        <div class="photo-source-body">
            <strong>Fotoğraf Kaynağı Doğrulandı</strong>
            <span>Kaynak: <?= htmlspecialchars($source_label) ?> &middot; Durum: Kabul edildi</span>
            <span class="photo-privacy-note">GPS konumu, çekim tarihi ve kamera bilgileri kaydedilmez.</span>
        </div>
    </div>

    <!-- Değiştir: discard draft and go back to step 1 -->
    <form method="POST" action="<?= BASE_PATH ?>/fotograf-yukle" class="photo-restart-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="restart">
        <?php if ($context === 'group' && $group): ?>
            <input type="hidden" name="context" value="group">
            <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="context" value="profile">
        <?php endif; ?>
        <button type="submit" class="btn-outline btn-sm">← Değiştir</button>
    </form>

    <!-- Publish form -->
    <form method="POST" action="<?= BASE_PATH ?>/fotograf-yukle" class="photo-upload-form photo-publish-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="publish">

        <div class="photo-caption-section">
            <label for="caption" class="photo-field-label">
                Açıklama <span class="photo-field-optional">(isteğe bağlı)</span>
            </label>
            <textarea id="caption" name="caption" maxlength="500"
                      placeholder="Bu fotoğraf hakkında bir şeyler yaz..."
                      class="photo-caption-input"><?= htmlspecialchars($_POST['caption'] ?? '') ?></textarea>
        </div>

        <div class="photo-tags-section">
            <div class="photo-field-label">Etiketler</div>

            <?php if (!empty($trending_tags)): ?>
            <div class="photo-trending-label">Revaçtaki Mevzuatlar:</div>
            <div class="photo-tag-chips">
                <?php foreach ($trending_tags as $tag):
                    $tc = ltrim($tag['tag'], '#');
                ?>
                <label class="photo-tag-chip">
                    <input type="checkbox" name="trending_tags[]" value="<?= htmlspecialchars($tc) ?>">
                    <span>#<?= htmlspecialchars($tc) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <label for="tags_custom" class="photo-field-label-small">
                Özel etiket <span class="photo-field-optional">(virgülle ayırın)</span>
            </label>
            <input type="text" id="tags_custom" name="tags_custom" maxlength="255"
                   placeholder="hukuk, mevzuat, dava"
                   value="<?= htmlspecialchars($_POST['tags_custom'] ?? '') ?>"
                   class="photo-tags-input">
        </div>

        <div class="photo-licence-row">
            <span class="photo-licence-label">📋 Lisans:</span>
            <span class="photo-licence-value"><?= htmlspecialchars(PHOTO_LICENCE) ?></span>
        </div>

        <?php if (!$is_premium && !$is_admin_usr): ?>
        <div class="photo-limit-indicator">
            <?= $current_count ?> / <?= PHOTO_FREE_LIMIT ?> fotoğraf kullanıldı
            <?php if ($current_count >= PHOTO_FREE_LIMIT - 2): ?>
                — <a href="<?= BASE_PATH ?>/premium">Premium ile sınırsız yükleyin</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="photo-upload-actions">
            <a href="<?= $back_url ?>" class="btn-outline">✕ İptal</a>
            <button type="submit" class="btn-post">📤 Paylaş</button>
        </div>
    </form>

<?php elseif ($at_limit): ?>

    <div class="photo-limit-notice">
        <p>Ücretsiz hesabınızla en fazla <strong><?= PHOTO_FREE_LIMIT ?></strong> fotoğraf yükleyebilirsiniz.</p>
        <p>Daha fazla yüklemek için <a href="<?= BASE_PATH ?>/premium">premium üye</a> olabilirsiniz.</p>
    </div>

<?php else: ?>

    <!-- ── STEP 1: Select file ─────────────────────────────────────────────── -->
    <form method="POST" enctype="multipart/form-data"
          action="<?= BASE_PATH ?>/fotograf-yukle"
          class="photo-upload-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= PHOTO_MAX_BYTES ?>">
        <?php if ($context === 'group' && $group): ?>
            <input type="hidden" name="context" value="group">
            <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="context" value="profile">
        <?php endif; ?>

        <div class="photo-upload-dropzone">
            <label for="photo_file" class="photo-dropzone-label">
                <span class="photo-dropzone-icon">📷</span>
                <span class="photo-dropzone-text">
                    Fotoğraf Seç
                    <span class="photo-dropzone-hint">(JPEG / PNG)</span>
                </span>
                <input type="file" id="photo_file" name="photo_file"
                       accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                       required
                       class="photo-file-input"
                       data-max-size="<?= PHOTO_MAX_BYTES ?>">
            </label>
            <p class="photo-upload-note">
                Yalnızca dijital kamera veya yüksek çözünürlüklü tarayıcıdan elde edilmiş
                JPEG/PNG fotoğraflar kabul edilmektedir. Cep telefonu fotoğrafları reddedilir.
                Minimum çözünürlük: ~0,8 MP &middot; Maksimum: ~<?= (int)round(PHOTO_MAX_PIXELS / 1000000) ?> MP,
                <?= round(PHOTO_MAX_BYTES / 1048576) ?> MB.
                İşlem güvenlik taraması nedeniyle 30 saniyeye kadar sürebilir; sayfayı yenilemeyin.
            </p>
        </div>

        <?php if (!$is_premium && !$is_admin_usr): ?>
        <div class="photo-limit-indicator">
            <?= $current_count ?> / <?= PHOTO_FREE_LIMIT ?> fotoğraf kullanıldı
            <?php if ($current_count >= PHOTO_FREE_LIMIT - 2): ?>
                — <a href="<?= BASE_PATH ?>/premium">Premium ile sınırsız yükleyin</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="photo-upload-actions">
            <a href="<?= $back_url ?>" class="btn-outline">İptal</a>
            <button type="submit" class="btn-post">Önizlemeye Geç →</button>
        </div>
    </form>

<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
