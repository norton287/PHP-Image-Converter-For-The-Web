<?php
declare(strict_types=1);

/**
 * cleanup.php — Maintenance script for the image converter.
 *
 * - Purges expired download files from private/zips/
 * - Removes orphaned uploads/converted files (older than PURGE_MINUTES)
 *   left behind if a conversion was interrupted unexpectedly
 *
 * Run via cron — NOT accessible via the web (.htaccess + PHP_SAPI guard below).
 * Recommended cron entry (every 15 minutes):
 *
 *   *\/15 * * * *  php /var/www/html/convert/cleanup.php >> /dev/null 2>&1
 */

// Belt-and-suspenders: refuse execution from a web context
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/lib/functions.php';

// ── Advisory lock — prevent concurrent runs if cron fires while a previous
//    invocation is still running (e.g. very large number of files).
$lockFh = fopen(CLEANUP_LOCK, 'c');
if ($lockFh === false) {
    logMessage('cleanup: could not open lock file', 'warn');
    exit(1);
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    logMessage('cleanup: another instance is already running — skipping', 'info');
    fclose($lockFh);
    exit(0);
}

logMessage('=== Cleanup started ===', 'info');

// 1. Purge expired download files (ZIPs and single-file downloads)
purgeOldZipFiles();

// 2. Remove orphaned upload/converted files older than PURGE_MINUTES.
//    These should be deleted immediately after conversion but may persist
//    if the process was killed or crashed mid-conversion.
$orphanThreshold = PURGE_MINUTES * 60;
$now             = time();

foreach ([UPLOAD_DIR, CONVERTED_DIR] as $dir) {
    $pattern = glob($dir . '*');
    if ($pattern === false) {
        logMessage('cleanup: glob() failed', 'warn', ['dir' => $dir]);
        continue;
    }
    foreach ($pattern as $file) {
        if (!is_file($file)) {
            continue;
        }
        $age = $now - filemtime($file);
        if ($age > $orphanThreshold) {
            if (unlink($file)) {
                logMessage('Removed orphan', 'info', [
                    'file' => basename($file),
                    'dir'  => basename(rtrim($dir, '/')),
                    'age'  => $age,
                ]);
            } else {
                logMessage('Failed to remove orphan', 'warn', [
                    'file' => basename($file),
                ]);
            }
        }
    }
}

logMessage('=== Cleanup finished ===', 'info');

// Release lock
flock($lockFh, LOCK_UN);
fclose($lockFh);
