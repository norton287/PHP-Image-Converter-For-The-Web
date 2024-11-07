<?php
date_default_timezone_set('America/Chicago');

$downloadDirectory = 'zips/';

// Define the time threshold for deletion (e.g., 5 minutes)
$threshold = time() - (5 * 60); // 5 minutes in seconds

// Get a list of all files in the directory
$files = glob($downloadDirectory . '*');

foreach ($files as $file) {
    if (is_file($file) && filemtime($file) < $threshold) {
        unlink($file); // Delete files older than the threshold
    }
}
?>