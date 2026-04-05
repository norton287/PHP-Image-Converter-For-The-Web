<?php
declare(strict_types=1);

/**
 * cleanup.php — Maintenance script for the image converter.
 *
 * Purges expired download files (ZIPs / single-file downloads) from the
 * private zips directory, and removes orphaned upload/converted files older
 * than PURGE_MINUTES that were left behind by interrupted conversions.
 *
 * ── How to run ────────────────────────────────────────────────────────────
 * This script is CLI-only.  It must NOT be accessible from the web
 * (.htaccess / nginx deny it; the PHP_SAPI guard below is a belt-and-suspenders).
 *
 * Find your PHP binary:
 *   which php8.4  ||  which php8.3  ||  which php
 *
 * Run manually:
 *   /usr/bin/php8.4 /var/www/html/convert/cleanup.php
 *
 * Recommended cron entry (every 15 minutes, errors logged):
 *   *\/15 * * * * /usr/bin/php8.4 /var/www/html/convert/cleanup.php \
 *                 >> /var/log/convert-cleanup.log 2>&1
 */

// ── CLI-only guard ─────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden.' . PHP_EOL);
}

require_once __DIR__ . '/lib/functions.php';

// ── Helper: write to both stdout and the structured app.log ───────────────
function say(string $message, string $level = 'info', array $context = []): void
{
    $ts   = date('Y-m-d H:i:s');
    $ctx  = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    $line = sprintf('[%s] [%-5s] %s%s', $ts, strtoupper($level), $message, $ctx);
    echo $line . PHP_EOL;
    logMessage($message, $level, $context);
}

// ── Ensure all private directories exist before we try to use them ─────────
foreach ([PRIVATE_ROOT, UPLOAD_DIR, CONVERTED_DIR, ZIP_DIR] as $dir) {
    $dir = rtrim((string)$dir, '/');
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $msg = "cleanup: could not create directory: $dir";
            fwrite(STDERR, $msg . PHP_EOL);
            logMessage($msg, 'error', ['path' => $dir]);
            exit(1);
        }
        echo "[bootstrap] Created directory: $dir" . PHP_EOL;
    }
}

// ── Advisory lock — prevent concurrent runs ────────────────────────────────
$lockPath = CLEANUP_LOCK;
$lockFh   = @fopen($lockPath, 'c');
if ($lockFh === false) {
    $msg = "cleanup: could not open lock file: $lockPath";
    fwrite(STDERR, $msg . PHP_EOL);
    logMessage($msg, 'error', ['path' => $lockPath]);
    exit(1);
}

if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    say('cleanup: another instance already running — skipping', 'info');
    fclose($lockFh);
    exit(0);
}

say('cleanup started', 'info', ['pid' => getmypid()]);

// ── 1. Purge expired download files (ZIPs and single-file downloads) ───────
say('scanning zip/download directory: ' . ZIP_DIR);
purgeOldZipFiles();
say('zip purge complete');

// ── 2. Remove orphaned upload / converted files ────────────────────────────
$threshold = PURGE_MINUTES * 60;
$now       = time();
$removed   = 0;
$failed    = 0;
$skipped   = 0;

say("scanning for orphaned files older than " . PURGE_MINUTES . " minutes", 'info', [
    'upload_dir'    => UPLOAD_DIR,
    'converted_dir' => CONVERTED_DIR,
]);

foreach ([UPLOAD_DIR, CONVERTED_DIR] as $dir) {
    if (!is_dir($dir)) {
        say("directory not found, skipping: $dir", 'warn');
        continue;
    }

    $entries = glob($dir . '*');
    if ($entries === false) {
        say("glob failed on directory: $dir", 'warn');
        continue;
    }

    foreach ($entries as $file) {
        if (!is_file($file)) {
            continue;
        }
        $age = $now - (int)filemtime($file);
        if ($age <= $threshold) {
            $skipped++;
            continue;
        }
        if (unlink($file)) {
            $removed++;
            say('orphan removed', 'info', [
                'file' => basename($file),
                'dir'  => basename(rtrim($dir, '/')),
                'age'  => $age,
            ]);
        } else {
            $failed++;
            say('could not remove orphan', 'warn', ['file' => basename($file)]);
        }
    }
}

say('cleanup finished', 'info', [
    'removed' => $removed,
    'skipped' => $skipped,
    'failed'  => $failed,
]);

// ── Release lock ────────────────────────────────────────────────────────────
flock($lockFh, LOCK_UN);
fclose($lockFh);

exit($failed > 0 ? 1 : 0);
