<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// PHP version guard — must be first, before any other code
// ---------------------------------------------------------------------------
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    http_response_code(500);
    exit('PHP 8.1 or higher is required. Current version: ' . PHP_VERSION);
}

require_once __DIR__ . '/ImageFormat.php';

date_default_timezone_set('America/Chicago');

// ---------------------------------------------------------------------------
// .env loader — reads KEY=VALUE pairs from an env file and exposes them via
// $_ENV / getenv().  Only sets a key if it is not already in the environment
// (process-level env vars take precedence).  Call before any define() that
// reads $_ENV so that operator overrides take effect.
// ---------------------------------------------------------------------------
function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// ---------------------------------------------------------------------------
// Path constants — all absolute, derived from this file's location.
// lib/ is one level below web root; private/ sits alongside web/ (outside HTTP reach).
// ---------------------------------------------------------------------------
define('WEB_ROOT',      dirname(__DIR__));                      // e.g. /workspace/web
define('PRIVATE_ROOT',  dirname(WEB_ROOT) . '/private');        // e.g. /workspace/private
define('LOG_FILE',      PRIVATE_ROOT . '/app.log');
define('UPLOAD_DIR',    PRIVATE_ROOT . '/uploads/');
define('CONVERTED_DIR', PRIVATE_ROOT . '/converted/');
define('ZIP_DIR',       PRIVATE_ROOT . '/zips/');
define('RATE_FILE',     PRIVATE_ROOT . '/rate_limits.json');
define('CLEANUP_LOCK',  PRIVATE_ROOT . '/cleanup.lock');

// Load .env from the workspace root (outside web root, safe from web access)
loadEnv(dirname(WEB_ROOT) . '/.env');

// ---------------------------------------------------------------------------
// Application constants — operator-overridable via .env
// ---------------------------------------------------------------------------
define('MAX_FILE_SIZE',  (int)(getenv('MAX_FILE_SIZE')  ?: 20971520));  // 20 MB
define('MAX_FILE_COUNT', (int)(getenv('MAX_FILE_COUNT') ?: 10));
define('PURGE_MINUTES',  (int)(getenv('PURGE_MINUTES')  ?: 15));
define('RATE_LIMIT_MAX', (int)(getenv('RATE_LIMIT_MAX') ?: 15));        // conversions per window
define('RATE_LIMIT_WIN', (int)(getenv('RATE_LIMIT_WIN') ?: 60));        // window in seconds
define('LOG_MAX_BYTES',  (int)(getenv('LOG_MAX_BYTES')  ?: 5242880));   // 5 MB before rotation
define('SERVER_OWNER',   (string)(getenv('SERVER_OWNER') ?: 'www-data'));

// Trusted reverse-proxy IP (set in .env as TRUSTED_PROXY=x.x.x.x).
// When set and REMOTE_ADDR matches, X-Forwarded-For is used for rate-limiting.
define('TRUSTED_PROXY',  (string)(getenv('TRUSTED_PROXY') ?: ''));

// X-Sendfile offload (set XSENDFILE_ENABLED=1 in .env for Apache mod_xsendfile).
define('XSENDFILE_ENABLED', (int)(getenv('XSENDFILE_ENABLED') ?: 0) === 1);
// X-Accel-Redirect for nginx: set NGINX_ACCEL_PATH to the internal location (e.g. /zips-internal/)
define('NGINX_ACCEL_PATH', (string)(getenv('NGINX_ACCEL_PATH') ?: ''));

// MIME types used by download.php
const DOWNLOAD_MIME = [
    'zip'  => 'application/zip',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'bmp'  => 'image/bmp',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
    'tiff' => 'image/tiff',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'svg'  => 'image/svg+xml',
];

// ---------------------------------------------------------------------------
// Structured JSON logging with 3-file rotation.
//
// Each line is a JSON object: {"ts":"…","level":"info","msg":"…","ctx":{…}}
// Falls back to PHP error_log() if the file cannot be written.
// ---------------------------------------------------------------------------
function logMessage(string $message, string $level = 'info', array $context = []): void
{
    $entry = ['ts' => date('c'), 'level' => $level, 'msg' => $message];
    if (!empty($context)) {
        $entry['ctx'] = $context;
    }
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // 3-file rotation: app.log → app.log.1 → app.log.2 (oldest is deleted)
    if (file_exists(LOG_FILE)) {
        $sz = filesize(LOG_FILE);
        if ($sz !== false && $sz > LOG_MAX_BYTES) {
            $log2 = LOG_FILE . '.2';
            $log1 = LOG_FILE . '.1';
            if (file_exists($log2)) {
                unlink($log2);
            }
            if (file_exists($log1)) {
                rename($log1, $log2);
            }
            rename(LOG_FILE, $log1);
        }
    }

    $written = file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        error_log('[app.log write failed] ' . $message);
    }
}

// ---------------------------------------------------------------------------
// Directory bootstrap
// ---------------------------------------------------------------------------
function createDirectory(string $directory, string $owner, int $permissions): void
{
    if (file_exists($directory)) {
        return;
    }
    try {
        if (!mkdir($directory, $permissions, true) && !is_dir($directory)) {
            throw new RuntimeException("mkdir() returned false for: $directory");
        }
        @chown($directory, $owner);
        logMessage("Directory created: $directory");
    } catch (Exception $e) {
        logMessage("Error creating directory $directory: " . $e->getMessage(), 'error');
        if (PHP_SAPI !== 'cli') {
            header('Location: /error.php?error=' . urlencode('Startup error: directory creation failed'));
        }
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Client-IP resolution — honours a trusted reverse proxy.
// ---------------------------------------------------------------------------
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (TRUSTED_PROXY !== '' && $remoteAddr === TRUSTED_PROXY) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For may be a comma-separated list; leftmost is the original client.
            $candidates = array_map('trim', explode(',', $forwarded));
            foreach ($candidates as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
    }

    return $remoteAddr;
}

// ---------------------------------------------------------------------------
// Rate limiting — APCu preferred, flat-file JSON fallback.
//
// Returns an array:
//   allowed   (bool)  — true if the request should proceed
//   remaining (int)   — conversions left in the current window
//   reset     (int)   — Unix timestamp when the window resets
// ---------------------------------------------------------------------------
function checkRateLimit(string $ip): array
{
    $key  = 'rl_' . md5($ip);
    $now  = time();
    $reset = $now + RATE_LIMIT_WIN;

    if (extension_loaded('apcu') && apcu_enabled()) {
        $count = (int)(apcu_fetch($key) ?: 0);
        if ($count >= RATE_LIMIT_MAX) {
            logMessage("Rate limit hit for IP (apcu)", 'warn', ['ip_hash' => md5($ip)]);
            return ['allowed' => false, 'remaining' => 0, 'reset' => $reset];
        }
        apcu_store($key, $count + 1, RATE_LIMIT_WIN);
        return ['allowed' => true, 'remaining' => RATE_LIMIT_MAX - $count - 1, 'reset' => $reset];
    }

    // Flat-file fallback with exclusive lock
    $fh = fopen(RATE_FILE, 'c+');
    if ($fh === false) {
        return ['allowed' => true, 'remaining' => RATE_LIMIT_MAX, 'reset' => $reset];
    }
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh) ?: '{}';
    $data = json_decode($raw, true) ?: [];

    // Expire entries outside the window
    $data[$key] = array_values(array_filter(
        $data[$key] ?? [],
        static fn(int $t): bool => ($now - $t) < RATE_LIMIT_WIN
    ));

    $count = count($data[$key]);
    if ($count >= RATE_LIMIT_MAX) {
        flock($fh, LOCK_UN);
        fclose($fh);
        logMessage("Rate limit hit for IP (file fallback)", 'warn', ['ip_hash' => md5($ip)]);
        return ['allowed' => false, 'remaining' => 0, 'reset' => $reset];
    }

    $data[$key][] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);

    return ['allowed' => true, 'remaining' => RATE_LIMIT_MAX - $count - 1, 'reset' => $reset];
}

// ---------------------------------------------------------------------------
// ZIP purge — removes archives older than PURGE_MINUTES
// ---------------------------------------------------------------------------
function purgeOldZipFiles(): void
{
    $now = time();
    foreach (glob(ZIP_DIR . '*') ?: [] as $file) {
        if (is_file($file) && ($now - filemtime($file)) > PURGE_MINUTES * 60) {
            if (unlink($file)) {
                logMessage('Purged expired file', 'info', ['file' => basename($file)]);
            } else {
                logMessage('Failed to purge file', 'warn', ['file' => basename($file)]);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Temp-file cleanup after conversion
// ---------------------------------------------------------------------------
function cleanupFiles(array $files): void
{
    $count = 0;
    foreach ($files as $file) {
        if (is_file($file) && unlink($file)) {
            $count++;
        }
    }
    logMessage('Temp files cleaned up', 'info', ['count' => $count]);
}

// ---------------------------------------------------------------------------
// Image conversion via Imagick
//
// $format  — an ImageFormat enum case
// $options keys:
//   resize_width  (int,    0 = no resize)
//   resize_height (int,    0 = no resize)
//   resize_mode   (string, 'fit' | 'fill' | 'stretch')
//   quality       (int 1-100, applied to JPG/WEBP)
//
// Throws RuntimeException or InvalidArgumentException on failure.
// ---------------------------------------------------------------------------
function convertImage(
    string      $source,
    string      $destination,
    ImageFormat $format,
    array       $options = []
): void {
    $tag = 'convertImage[' . basename($source) . ']';
    logMessage("$tag START", 'info', [
        'dest'    => basename($destination),
        'format'  => $format->value,
        'options' => $options,
    ]);

    // ── Extension check ──────────────────────────────────────────────────────
    if (!extension_loaded('imagick')) {
        logMessage("$tag FAIL — Imagick extension not loaded", 'error');
        throw new RuntimeException('Server configuration error: Imagick extension not loaded');
    }

    // ── Source file sanity ───────────────────────────────────────────────────
    if (!file_exists($source)) {
        logMessage("$tag FAIL — source file not found", 'error', ['source' => $source]);
        throw new RuntimeException('Source file not found');
    }
    $srcSize = filesize($source);
    logMessage("$tag source exists", 'info', ['size' => $srcSize]);

    // ── MIME check via finfo (always) ────────────────────────────────────────
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($source);
    logMessage("$tag finfo MIME", 'info', ['mime' => $mimeType ?: '(false)']);

    $isSvgSource = ($mimeType === 'image/svg+xml');

    if ($mimeType === false
        || (!str_starts_with($mimeType, 'image/') && $mimeType !== 'application/pdf')
    ) {
        logMessage("$tag FAIL — MIME rejected", 'error', ['mime' => $mimeType]);
        throw new InvalidArgumentException('Invalid file type');
    }

    // ── getimagesize() — skip for SVG (it always returns false) ─────────────
    if (!$isSvgSource) {
        $imageInfo = getimagesize($source);
        if ($imageInfo === false) {
            logMessage("$tag FAIL — getimagesize() returned false", 'error');
            throw new InvalidArgumentException('Not a valid image file');
        }
        logMessage("$tag dimensions", 'info', [
            'w'    => $imageInfo[0],
            'h'    => $imageInfo[1],
            'type' => $imageInfo[2],
        ]);

        $ext            = image_type_to_extension($imageInfo[2], false);
        $originalFormat = $ext !== false ? strtolower($ext) : 'unknown';
        if (!in_array($originalFormat, ImageFormat::values(), true)) {
            logMessage("$tag FAIL — source format not supported", 'error', ['format' => $originalFormat]);
            throw new InvalidArgumentException("Unsupported source format: $originalFormat");
        }
    }

    // ── Validate target format ───────────────────────────────────────────────
    if (!$format->isTargetSupported()) {
        logMessage("$tag FAIL — target format not supported for output", 'error', ['format' => $format->value]);
        throw new InvalidArgumentException("Format '{$format->value}' cannot be used as a conversion target");
    }

    // ── Options ──────────────────────────────────────────────────────────────
    $resizeW   = max(0, (int)($options['resize_width']  ?? 0));
    $resizeH   = max(0, (int)($options['resize_height'] ?? 0));
    $quality   = min(100, max(1, (int)($options['quality'] ?? 85)));
    $resizeMode = (string)($options['resize_mode'] ?? 'fit');
    logMessage("$tag options parsed", 'info', [
        'resizeW'    => $resizeW,
        'resizeH'    => $resizeH,
        'resizeMode' => $resizeMode,
        'quality'    => $quality,
    ]);

    // ── Imagick pipeline ─────────────────────────────────────────────────────
    try {
        logMessage("$tag opening with Imagick…", 'info');
        $image = new Imagick($source);

        // Flatten multi-layer images (e.g. GIF animation → single frame) to avoid
        // per-layer format issues when writing to single-image formats.
        if ($image->getNumberImages() > 1 && !in_array($format->value, ['gif'], true)) {
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $origW = $image->getImageWidth();
        $origH = $image->getImageHeight();
        logMessage("$tag opened", 'info', ['w' => $origW, 'h' => $origH]);

        // ── Resize ───────────────────────────────────────────────────────────
        if ($resizeW > 0 || $resizeH > 0) {
            $boundW = $resizeW ?: 99999;
            $boundH = $resizeH ?: 99999;

            if ($resizeMode === 'fill') {
                // Crop to exact dimensions — fill the bounding box
                $image->cropThumbnailImage($boundW, $boundH);
            } elseif ($resizeMode === 'stretch') {
                // Ignore aspect ratio — stretch to exact dimensions
                $image->resizeImage($boundW, $boundH, Imagick::FILTER_LANCZOS, 1, false);
            } else {
                // 'fit' (default) — preserve aspect ratio within bounding box
                $image->resizeImage($boundW, $boundH, Imagick::FILTER_LANCZOS, 1, true);
            }

            logMessage("$tag resized", 'info', [
                'from' => "{$origW}x{$origH}",
                'to'   => $image->getImageWidth() . 'x' . $image->getImageHeight(),
                'mode' => $resizeMode,
            ]);
        }

        // ── ICC / colour-profile preservation ────────────────────────────────
        // Normalise to sRGB to avoid colour shifts across profiles, then strip
        // the raw profile bytes so the output file is as small as possible.
        try {
            $profiles = $image->getImageProfiles('icc', false);
            if (!empty($profiles)) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (ImagickException) {
            // Profile handling is best-effort; log and continue
            logMessage("$tag colour-profile normalisation skipped", 'warn');
        }

        // ── Strip EXIF / metadata — reduces output size 10-30% ───────────────
        $image->stripImage();

        // ── Format & quality ─────────────────────────────────────────────────
        $image->setImageFormat($format->imagickFormat());

        if ($format->supportsQuality()) {
            $image->setImageCompressionQuality($quality);
            logMessage("$tag quality set", 'info', ['quality' => $quality]);
        }

        // ── Write ─────────────────────────────────────────────────────────────
        $fh = fopen($destination, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open destination for writing: $destination");
        }
        $image->writeImageFile($fh);
        fclose($fh);
        $image->destroy();

        $dstSize = file_exists($destination) ? filesize($destination) : -1;
        logMessage("$tag SUCCESS", 'info', ['output_bytes' => $dstSize]);

    } catch (Exception $e) {
        logMessage("$tag EXCEPTION", 'error', [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
        ]);
        throw new RuntimeException('Conversion failed: ' . $e->getMessage(), 0, $e);
    }
}
