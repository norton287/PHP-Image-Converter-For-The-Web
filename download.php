<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/functions.php';

logMessage('Download requested', 'info');

if (empty($_GET['file'])) {
    logMessage('Download rejected — no file parameter', 'warn');
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid request.');
}

// Accept: exactly 32 hex chars + one of the supported extensions
$requested = basename(urldecode((string)$_GET['file']));
$validExtensions = implode('|', array_keys(DOWNLOAD_MIME));

if (!preg_match('/^[a-f0-9]{32}\.(' . $validExtensions . ')$/', $requested, $matches)) {
    logMessage('Download rejected — invalid filename pattern', 'warn', ['file' => $requested]);
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Access denied.');
}

$ext      = $matches[1];
$mimeType = DOWNLOAD_MIME[$ext] ?? 'application/octet-stream';

// Confirm the file is physically inside ZIP_DIR (block symlink / path traversal)
$zipBase  = realpath(ZIP_DIR);
$filePath = realpath(ZIP_DIR . $requested);

if ($zipBase === false || $filePath === false
    || strncmp($filePath, $zipBase, strlen($zipBase)) !== 0) {
    logMessage('Download rejected — path outside ZIP_DIR', 'warn', ['file' => $requested]);
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Access denied.');
}

if (!is_file($filePath)) {
    logMessage('Download failed — file not found', 'warn', ['file' => $requested]);
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('File not found or has expired.');
}

$fileSize = filesize($filePath);
if ($fileSize === false) {
    logMessage('Download failed — filesize() error', 'error', ['file' => $requested]);
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Server error.');
}

$displayName = ($ext === 'zip') ? 'converted_images.zip' : 'converted.' . $ext;

logMessage('Serving file', 'info', ['name' => $displayName, 'bytes' => $fileSize]);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $displayName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

// ── Offload to web server if configured (frees PHP worker immediately) ────────
// Apache mod_xsendfile: set XSENDFILE_ENABLED=1 in .env
if (XSENDFILE_ENABLED) {
    header('X-Sendfile: ' . $filePath);
    exit;
}
// nginx X-Accel-Redirect: set NGINX_ACCEL_PATH=/zips-internal/ in .env
// (requires matching `internal` location block in nginx.conf)
if (NGINX_ACCEL_PATH !== '') {
    header('X-Accel-Redirect: ' . NGINX_ACCEL_PATH . $requested);
    exit;
}

// Default: PHP streams the file
if (ob_get_level() > 0) {
    ob_clean();
}
flush();
readfile($filePath);
exit;
