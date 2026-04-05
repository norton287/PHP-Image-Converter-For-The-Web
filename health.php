<?php
declare(strict_types=1);

/**
 * health.php — Service health-check endpoint.
 *
 * Returns a JSON object indicating whether all required extensions and
 * writable directories are available.  Suitable for uptime monitors and
 * deployment verification scripts.
 *
 * HTTP 200  — all critical checks pass
 * HTTP 503  — one or more critical checks failed
 */

require_once __DIR__ . '/lib/functions.php';

// Bootstrap directories so is_dir() / is_writable() checks are meaningful
foreach ([UPLOAD_DIR, CONVERTED_DIR, ZIP_DIR] as $dir) {
    createDirectory($dir, SERVER_OWNER, 0755);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$imagickOk = extension_loaded('imagick');
$zipOk     = extension_loaded('zip');

$dirs = [
    'uploads'   => is_dir(UPLOAD_DIR)   && is_writable(UPLOAD_DIR),
    'converted' => is_dir(CONVERTED_DIR) && is_writable(CONVERTED_DIR),
    'zips'      => is_dir(ZIP_DIR)       && is_writable(ZIP_DIR),
];

$allDirsOk = !in_array(false, $dirs, true);
$critical  = $imagickOk && $zipOk && $allDirsOk;

$payload = [
    'status'   => $critical ? 'ok' : 'degraded',
    'php'      => PHP_VERSION,
    'imagick'  => $imagickOk,
    'imagick_version' => $imagickOk ? Imagick::getVersion()['versionString'] : null,
    'zip'      => $zipOk,
    'apcu'     => extension_loaded('apcu') && apcu_enabled(),
    'dirs'     => $dirs,
    'limits'   => [
        'max_file_size'  => MAX_FILE_SIZE,
        'max_file_count' => MAX_FILE_COUNT,
        'rate_limit_max' => RATE_LIMIT_MAX,
        'rate_limit_win' => RATE_LIMIT_WIN,
        'purge_minutes'  => PURGE_MINUTES,
    ],
];

if (!$critical) {
    http_response_code(503);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
