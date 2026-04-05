<?php
declare(strict_types=1);

ini_set('memory_limit', '256M');
set_time_limit(120);

session_start();

require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/Converter.php';

// ── Bootstrap required directories ──────────────────────────────────────────
foreach ([UPLOAD_DIR, CONVERTED_DIR, ZIP_DIR] as $dir) {
    createDirectory($dir, SERVER_OWNER, 0755);
}

// ── Per-request CSP nonce (eliminates 'unsafe-inline' for scripts) ──────────
$nonce = base64_encode(random_bytes(16));

// ── CSRF token (generate once per session) ───────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Content-Security-Policy — emitted from PHP so it can embed the nonce ────
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'nonce-{$nonce}' https://umami.spindlecrank.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: blob:; "
    . "connect-src 'self' https://umami.spindlecrank.com; "
    . "font-src 'self' data:; "
    . "object-src 'none'; "
    . "frame-ancestors 'none';"
);

// ── Rate-limit headers helper ────────────────────────────────────────────────
function emitRateLimitHeaders(array $rl): void
{
    header('X-RateLimit-Limit: '     . RATE_LIMIT_MAX);
    header('X-RateLimit-Remaining: ' . $rl['remaining']);
    header('X-RateLimit-Reset: '     . $rl['reset']);
}

// ── JSON response (async path) ───────────────────────────────────────────────
function jsonResponse(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Abort helper ─────────────────────────────────────────────────────────────
function abortWithError(string $userMessage, bool $isAsync, string $logDetail = ''): never
{
    logMessage('Request error: ' . ($logDetail ?: $userMessage), 'warn');
    if ($isAsync) {
        jsonResponse(['success' => false, 'error' => $userMessage]);
    }
    header('Location: /error.php?error=' . urlencode($userMessage));
    exit;
}

// ============================================================================
// POST handler
// ============================================================================
$history = $_SESSION['download_history'] ?? [];
$resp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAsync  = ($_POST['_async'] ?? '') === '1';
    $clientIp = getClientIp();

    // ── CSRF check ────────────────────────────────────────────────────────────
    $submittedToken = $_POST['_csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (!hash_equals($csrfToken, (string)$submittedToken)) {
        abortWithError('Invalid request. Please refresh and try again.', $isAsync,
            'CSRF token mismatch');
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────
    $rl = checkRateLimit($clientIp);
    emitRateLimitHeaders($rl);
    if (!$rl['allowed']) {
        abortWithError('Too many requests. Please wait a moment and try again.', $isAsync);
    }

    // ── File presence check ───────────────────────────────────────────────────
    if (!isset($_FILES['images']) || !is_array($_FILES['images']['name'])) {
        abortWithError('No files were uploaded.', $isAsync);
    }

    // ── Format parsing ────────────────────────────────────────────────────────
    $fileFormats  = is_array($_POST['file_format'] ?? null) ? $_POST['file_format'] : [];
    $globalFormat = is_string($_POST['global_format'] ?? null) ? trim($_POST['global_format']) : '';

    if (!empty($fileFormats)) {
        foreach ($fileFormats as $fmt) {
            $fmtObj = ImageFormat::tryFrom((string)$fmt);
            if ($fmtObj === null || !$fmtObj->isTargetSupported()) {
                abortWithError('Invalid format selected.', $isAsync, "Invalid format: $fmt");
            }
        }
    } elseif (ImageFormat::tryFrom($globalFormat)?->isTargetSupported() !== true) {
        abortWithError('Invalid or missing target format.', $isAsync);
    }

    // ── Resize / quality options ──────────────────────────────────────────────
    $resizeW    = max(0, (int)($_POST['resize_width']  ?? 0));
    $resizeH    = max(0, (int)($_POST['resize_height'] ?? 0));
    $quality    = min(100, max(1, (int)($_POST['quality'] ?? 85)));
    $resizeMode = in_array($_POST['resize_mode'] ?? '', ['fit', 'fill', 'stretch'], true)
        ? (string)$_POST['resize_mode']
        : 'fit';

    // ── File count check ──────────────────────────────────────────────────────
    $names     = $_FILES['images']['name'];
    $fileCount = count(array_filter($names, static fn($n): bool => (string)$n !== ''));

    if ($fileCount === 0) {
        abortWithError('No files were uploaded.', $isAsync);
    }
    if ($fileCount > MAX_FILE_COUNT) {
        abortWithError('Too many files. Maximum is ' . MAX_FILE_COUNT . '.', $isAsync);
    }

    logMessage('POST received', 'info', [
        'ip_hash'    => md5($clientIp),
        'files'      => $fileCount,
        'format'     => $globalFormat ?: '(per-file)',
        'resizeMode' => $resizeMode,
        'quality'    => $quality,
    ]);

    // ── Normalise file list ───────────────────────────────────────────────────
    $fileList = [];
    foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
        $name = basename((string)$_FILES['images']['name'][$key]);
        if ($name === '') {
            continue;
        }
        $fileList[$key] = [
            'name'  => $name,
            'tmp'   => (string)$_FILES['images']['tmp_name'][$key],
            'error' => (int)$_FILES['images']['error'][$key],
            'size'  => (int)$_FILES['images']['size'][$key],
        ];
    }

    // ── Run conversion ────────────────────────────────────────────────────────
    $converter = new Converter([
        'resize_width'  => $resizeW,
        'resize_height' => $resizeH,
        'resize_mode'   => $resizeMode,
        'quality'       => $quality,
    ]);

    try {
        $result = $converter->run($fileList, $fileFormats, $globalFormat);
    } catch (Exception $e) {
        $converter->cleanup();
        abortWithError('Conversion error. Please try again.', $isAsync, $e->getMessage());
    }

    $converter->cleanup();
    logMessage('Batch finished', 'info', [
        'success'  => $result->successCount,
        'failures' => count($result->failures),
    ]);

    // ── All files failed ──────────────────────────────────────────────────────
    if (!$result->hasDownload()) {
        $firstError = $result->failures[0]['error'] ?? 'Check that your files are valid images.';
        abortWithError('No files could be converted. ' . $firstError, $isAsync);
    }

    // ── Session history ───────────────────────────────────────────────────────
    $historyEntry = [
        'file'        => $result->downloadFile,
        'url'         => $result->downloadUrl,
        'label'       => $result->downloadLabel,
        'count'       => $result->successCount,
        'format'      => !empty($fileFormats)
            ? implode(', ', array_unique(array_values($fileFormats)))
            : $globalFormat,
        'output_size' => $result->outputBytes,
        'time'        => time(),
    ];
    $_SESSION['download_history'] = array_slice(
        array_merge([$historyEntry], $_SESSION['download_history'] ?? []),
        0,
        5
    );
    $history = $_SESSION['download_history'];

    // ── Async JSON response ───────────────────────────────────────────────────
    if ($isAsync) {
        jsonResponse([
            'success'     => true,
            'url'         => $result->downloadUrl,
            'label'       => $result->downloadLabel,
            'count'       => $result->successCount,
            'is_zip'      => $result->isZip,
            'output_size' => $result->outputBytes,
            'failures'    => $result->failures,
            'history'     => $history,
        ]);
    }

    // ── Non-async fallback ────────────────────────────────────────────────────
    $dlUrlSafe = htmlspecialchars($result->downloadUrl,   ENT_QUOTES, 'UTF-8');
    $lblSafe   = htmlspecialchars($result->downloadLabel, ENT_QUOTES, 'UTF-8');
    $resp = '<div class="mb-4 flex items-center justify-center">
        <a href="' . $dlUrlSafe . '" id="downloadButton" class="btn-download success-enter">
            <div class="btn-download-icon animate-bounce">
                <svg class="w-5 h-5" fill="none" stroke-linecap="round" stroke-linejoin="round"
                     stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">
                    <path d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                </svg>
            </div>
            ' . $lblSafe . '
        </a>
    </div>';
}

logMessage($_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST handled' : 'GET page load', 'info');

// ── Format label map ──────────────────────────────────────────────────────────
$FORMAT_LABELS = [];
foreach (ImageFormat::targetOptions() as $fmt) {
    $FORMAT_LABELS[$fmt->value] = $fmt->label();
}
$FORMAT_LABELS_JSON = json_encode($FORMAT_LABELS, JSON_HEX_TAG | JSON_HEX_AMP);

$jsConfig = json_encode([
    'maxFiles'     => MAX_FILE_COUNT,
    'maxBytes'     => MAX_FILE_SIZE,
    'formats'      => $FORMAT_LABELS,
    'csrfToken'    => $csrfToken,
    'purgeSeconds' => PURGE_MINUTES * 60,
], JSON_HEX_TAG | JSON_HEX_AMP);

$nonceSafe = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
$csrfSafe  = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <script nonce="<?= $nonceSafe ?>">
    (function(){
        try {
            var t = localStorage.getItem('app-theme') ||
                (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', t);
        } catch(e) {}
    }());
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Image Converter — Spindlecrank</title>
    <meta name="description" content="Convert your images to different formats quickly and easily. Supports JPG, PNG, BMP, GIF, ICO, TIFF, WEBP, PDF, and SVG.">
    <meta name="keywords" content="Image Converter, Spindlecrank, JPG, PNG, BMP, GIF, ICO, WEBP, TIFF, PDF, SVG">
    <meta name="google-site-verification" content="gu3duYB5OEsqTehyFOA1M1OOzJ--AfbTsk4dt_CVJTU">
    <meta name="theme-color" content="#ffffff">
    <meta name="msapplication-TileColor" content="#da532c">

    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#5bbad5">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"
          integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJaA=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.16/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">

    <script defer src="https://umami.spindlecrank.com/script.js"
            data-website-id="8b98fc8b-d862-4c6e-92ec-65775d0fbca7"></script>
</head>
<body class="app-page">

    <div class="app-card animate__animated animate__slideInRight">

        <button id="themeToggle" class="theme-toggle" type="button"
                title="Toggle light/dark mode" aria-label="Toggle light/dark mode">
            <svg class="icon-sun w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>
            </svg>
            <svg class="icon-moon w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
        </button>

        <h1 class="text-3xl text-center font-bold mb-1 c-primary animate__animated animate__delay-1s animate__fadeInDown">
            Image Format Converter
        </h1>
        <p class="text-base text-center mb-6 c-secondary">
            Convert images to JPG, PNG, WEBP, PDF, SVG, and more — instantly.
        </p>

        <div id="errorBanner" role="alert" class="hidden error-banner mb-4">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <span id="errorMessage" class="flex-1"></span>
            <button type="button" class="error-close"
                    onclick="document.getElementById('errorBanner').classList.add('hidden')"
                    aria-label="Dismiss">&times;</button>
        </div>

        <form id="convertForm" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="_async"      value="1">
            <input type="hidden" name="_csrf_token" value="<?= $csrfSafe ?>">

            <div id="dropZone" class="drop-zone">
                <input type="file" id="imageInput" name="images[]"
                       accept="image/*,.pdf,.svg" multiple class="hidden">

                <div id="dropPrompt">
                    <svg class="w-12 h-12 mx-auto mb-3 text-indigo-300 opacity-80" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="font-medium mb-1 c-primary">
                        Drop images here or <span class="c-accent underline">tap to browse</span>
                    </p>
                    <p class="text-xs c-muted">
                        Up to <?= MAX_FILE_COUNT ?> files &bull; max 20&nbsp;MB each &bull;
                        JPG, PNG, BMP, GIF, ICO, TIFF, WEBP, PDF, SVG
                    </p>
                </div>

                <div id="fileList" class="hidden space-y-2 text-left"></div>

                <div id="fileControls" class="hidden mt-3 flex gap-3 justify-center">
                    <button type="button" id="addMoreBtn"   class="add-files-btn">+ Add more files</button>
                    <button type="button" id="clearFilesBtn" class="clear-files-btn">Clear all</button>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <label for="globalFormat" class="c-label font-medium text-sm whitespace-nowrap flex-shrink-0">
                    Convert all to:
                </label>
                <select id="globalFormat" name="global_format" class="app-select flex-1">
                    <option value="">-- Choose a format --</option>
                    <?php foreach ($FORMAT_LABELS as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val,   ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="section-hint hidden sm:block">(or set per-file below)</span>
            </div>

            <details class="advanced-panel" id="advancedPanel">
                <summary>
                    <span>Advanced Options</span>
                    <svg class="chevron w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </summary>
                <div class="panel-body space-y-4">
                    <div>
                        <p class="panel-section-label">Resize (0 = keep original)</p>
                        <div class="flex gap-4">
                            <div class="flex-1">
                                <label for="resizeWidth" class="input-label">Max Width (px)</label>
                                <input type="number" id="resizeWidth" name="resize_width"
                                       min="0" max="16000" value="0" class="app-number">
                            </div>
                            <div class="flex-1">
                                <label for="resizeHeight" class="input-label">Max Height (px)</label>
                                <input type="number" id="resizeHeight" name="resize_height"
                                       min="0" max="16000" value="0" class="app-number">
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="panel-section-label">Resize Mode</p>
                        <div class="flex gap-4 flex-wrap">
                            <label class="resize-mode-label">
                                <input type="radio" name="resize_mode" value="fit" checked class="resize-mode-radio">
                                <span class="resize-mode-text">
                                    <strong>Fit</strong>
                                    <span class="resize-mode-hint">Preserve ratio within bounds</span>
                                </span>
                            </label>
                            <label class="resize-mode-label">
                                <input type="radio" name="resize_mode" value="fill" class="resize-mode-radio">
                                <span class="resize-mode-text">
                                    <strong>Fill</strong>
                                    <span class="resize-mode-hint">Crop to exact size</span>
                                </span>
                            </label>
                            <label class="resize-mode-label">
                                <input type="radio" name="resize_mode" value="stretch" class="resize-mode-radio">
                                <span class="resize-mode-text">
                                    <strong>Stretch</strong>
                                    <span class="resize-mode-hint">Exact size, ignore ratio</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label for="qualitySlider" class="quality-label">
                            Quality — JPG / WEBP only:
                            <span id="qualityVal" class="quality-val">85</span>%
                        </label>
                        <input type="range" id="qualitySlider" name="quality"
                               min="1" max="100" value="85">
                    </div>
                </div>
            </details>

            <div class="flex justify-center pt-2">
                <button type="submit" id="submitBtn" class="btn-convert">Convert</button>
            </div>
        </form>

        <div id="waiting" class="mt-6 spinner-wrap" style="display:none">
            <div class="spinner-pill">
                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Converting&hellip;
            </div>
        </div>

        <div id="result" class="mt-6 flex flex-col items-center text-center w-full">
            <?= $resp ?>
        </div>

        <?php if (!empty($history)): ?>
        <div id="historySection" class="history-section">
            <h2 class="history-title">Recent Downloads</h2>
            <ul class="space-y-2" id="historyList">
                <?php
                $nowTs    = time();
                $purgeWin = PURGE_MINUTES * 60;
                foreach ($history as $entry):
                    $expired = ($nowTs - (int)$entry['time']) > $purgeWin;
                    $sizeStr = !empty($entry['output_size'])
                        ? ' &middot; ' . number_format((int)$entry['output_size'] / 1024, 0) . '&nbsp;KB'
                        : '';
                    $timeStr = date('g:i a', (int)$entry['time']);
                    $urlSafe = htmlspecialchars((string)$entry['url'],    ENT_QUOTES, 'UTF-8');
                    $fmtSafe = htmlspecialchars(strtoupper((string)$entry['format']), ENT_QUOTES, 'UTF-8');
                ?>
                <li class="history-item<?= $expired ? ' expired' : '' ?>">
                    <span class="history-item-text">
                        <?= (int)$entry['count'] ?> file<?= (int)$entry['count'] !== 1 ? 's' : '' ?>
                        &rarr; <strong><?= $fmtSafe ?></strong><?= $sizeStr ?>
                        <span class="ts ml-1"><?= $timeStr ?></span>
                    </span>
                    <?php if ($expired): ?>
                        <span class="history-expired">Expired</span>
                    <?php else: ?>
                        <a href="<?= $urlSafe ?>" class="history-link">Re-download</a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <p class="footer-text mt-8 animate__animated animate__delay-3s animate__zoomInUp">
            Proudly Powered By <a href="https://spindlecrank.com">spindlecrank.com</a>
        </p>
    </div>

    <script nonce="<?= $nonceSafe ?>">
    window.AppConfig = <?= $jsConfig ?>;
    </script>
    <script src="app.js"></script>
</body>
</html>
