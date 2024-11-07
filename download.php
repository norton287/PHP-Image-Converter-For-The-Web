<?php

date_default_timezone_set('America/Chicago');

function logMessage($message)
{
    $logFile = __DIR__ . '/logfile.log';
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666); // Sets RW permissions for everyone
        chown($logFile, 'www-data');
    }
    // Format the log message with a timestamp
    $logMessage = ('Log Entry [' . date('Y-m-d H:i:s') . '] ' . $message) . PHP_EOL;
    // Append the log message to the log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

logMessage("Downloading!");


    // Check if the 'file' parameter is set in the URL
    if(isset($_GET['file']) && !empty($_GET['file'])) {
        $filePath = urldecode($_GET['file']);

        // Check if the file exists
        if(file_exists($filePath)) {
            // Set headers to force download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($filePath).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
			logMessage("Set Headers for Download!");
            // Clear output buffer
            ob_clean();

            // Flush the output buffer
            flush();

            // Read the file content and send it to the output
            readfile($filePath);
        	logMessage("Download path is " . $filePath);

            // Exit to prevent any additional output
            exit;
        } else {
            echo 'File not found.';
        	logMessage("File Not Found During Download!");
        }
    } else {
        echo 'Invalid request.';
    	logMessage("Invalid Server Request During Download!");
    }
?>