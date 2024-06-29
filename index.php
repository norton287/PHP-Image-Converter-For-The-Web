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

logMessage("New Convert!");

// Response echo
$resp = '';

$directories = ['uploads/', 'converted/', 'zips/']; // Array of directories
$owner = 'www-data';
$permissions = 0755; // Octal representation of 755

// Function to create and set permissions for directories
function createDirectory($directory, $owner, $permissions) {
	try {
		mkdir($directory, $permissions, true); // Recursive creation
		logMessage("Directory $directory created!");
		chown($directory, $owner);
		logMessage("Ownership of $directory changed to $owner");       
	} catch (Exception $e) {
    	logMessage("Error creating/modifying $directory: " . $e->getMessage());
    	header("Location: error.php?error=" . urlencode("System Error On Page Load"));
	}
}

// Iterate over the array and create directories if needed
foreach ($directories as $directory) {
	if (!file_exists($directory)) {
    	createDirectory($directory, $owner, $permissions);
    	logMessage("$directory does not exist! Making it now!");
    }
}

// Function to clean up old zip files
function cleanupZipFiles($zip_dir) {
    $files = glob($zip_dir . "*.zip");
    $now = time();
    $one_minute_ago = $now - 60; // 60 seconds = 1 minute

    foreach ($files as $file) {
        if (filemtime($file) < $one_minute_ago) {
            unlink($file);
            logMessage("Removed old zip file: " . $file);
        }
    }
}

// Call the cleanup function at the start of the script
$zip_dir = 'zips/'; // Assuming this is your zip directory

global $zip_dir;
global $upload_dir;
global $converted_dir;
global $allowed_formats;

$zip_dir='zips/';
$upload_dir='uploads/';
$converted_dir='converted/';

function purgeOldZipFiles() {
	logMessage("Purging Old Zips from the Server!");

    $directory = '/var/www/html/convert/zips/';
    $maxAgeMinutes = 15;
    $now = time();

    // Get all files in the directory
    $files = scandir($directory);

    // Iterate through the files
    foreach ($files as $file) {
        // Skip "." and ".."
        if ($file == '.' || $file == '..') {
            continue;
        }

        // Check if it's a zip file
        if (substr($file, -4) == '.zip') {
            $filePath = $directory . $file;

            // Get the last modification time of the file
            $lastModified = filemtime($filePath);

            // Check if it's older than the specified max age
            if ($now - $lastModified > $maxAgeMinutes * 60) {
                // Delete the file
                unlink($filePath);
            }
        }
    }
}

// Call the function when index.php loads
purgeOldZipFiles();


$user_files = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$resp = '';
	$format = $_POST['format'];
    logMessage("In POST");

    ob_start(); // Start output buffering

    logMessage("Creating new zip file");
    $zip = new ZipArchive();
    $zip_path = 'zips/' . time() . '.zip'; // Full path to the zip file

    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        header("Location: error.php?error=" . urlencode("Zip Creation Failed!"));
    }

foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
		logMessage("In foreach loop processing images!");
		$uploadedFile = [
			'name' => $_FILES['images']['name'][$key],
			'type' => $_FILES['images']['type'][$key],
			'tmp_name' => $_FILES['images']['tmp_name'][$key],
			'error' => $_FILES['images']['error'][$key],
			'size' => $_FILES['images']['size'][$key]
        ];
    	
		$file_name = $uploadedFile['name'];
		$file_tmp = $uploadedFile['tmp_name'];

		$allowed_formats = ['jpg', 'png', 'bmp', 'gif', 'ico'];
		$fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowed_formats)) {
			logMessage("File type not allowed!");
			header("Location: error.php?error=" . urlencode("File Type Not Allowed For " . $fileExtension));
		}
		     
        // Validate file
        if (!is_uploaded_file($file_tmp)) {
	    	logMessage("Error uploading file! " . $file_tmp);
        	header("Location: error.php?error=" . urlencode("File Upload Failed For " . $file_tmp));
        }

        // Validate image
        if (!getimagesize($file_tmp)) {
		    logMessage("Invalid image file! " . $file_tmp);
			header("Location: error.php?error=" . urlencode("Invalid Image File " . $file_tmp));        		    	
        }

    	    // Error handling for adding files to zip
    
        // Move uploaded file to upload directory
        move_uploaded_file($file_tmp, $upload_dir.$file_name);
		logMessage("Moved uploaded file!");

        // Convert image and save to converted directory
        convertImage($upload_dir.$file_name, $converted_dir.pathinfo($file_name, PATHINFO_FILENAME) . '.' . $format, $format);
		logMessage("Converted image to new format!");
        // Add converted image to zip
	    $local_file_name = pathinfo($file_name, PATHINFO_FILENAME) . '.' . $format; // Construct local file name
    	$zip->addFile($converted_dir . $local_file_name, $local_file_name); // Add with local name
	    logMessage("Image added to zip file!");

        // Add uploaded and converted files to user files array
        array_push($user_files, $upload_dir.$file_name);
        array_push($user_files, $converted_dir.pathinfo($file_name, PATHINFO_FILENAME) . '.' . $format);
    }

    // Close the zip file after all files have been added
    $zip->close();

    // Construct the download button HTML in $resp
    $resp .= '<button data-href="download.php?file=' . urlencode($zip_path) . '" id="downloadButton" class="button-class flex flex-wrap justify-center p-4 bg-blue-500 text-white rounded-md transform transition duration-500 ease-in-out hover:scale-105">Download ZIP file</button>';
    ob_end_clean();
    cleanupFiles($user_files);
    logMessage("Removing users files from server!");
}

function convertImage($source, $destination, $format) {
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
    $imageInfo = getimagesize($source);
    global $allowed_formats;

    if (!$imageInfo) {
        header("Location: error.php?error=" . urlencode("Invalid image file: " . $source));
        exit;
    }

    $originalFormat = strtolower($imageInfo[2]);
    $originalFormat = image_type_to_extension($originalFormat, false);

    if (!in_array($originalFormat, $allowedFormats)) {
        header("Location: error.php?error=" . urlencode("Unsupported image format: " . $originalFormat));
        exit;
    }

    if (!in_array($format, $allowedFormats)) {
        header("Location: error.php?error=" . urlencode("Invalid target format: " . $format));
        exit;
    }

    try {
        $image = new Imagick($source);
        $image->setImageFormat($format);
        file_put_contents($destination, $image);
        logMessage("Image converted from $originalFormat to $format!");
    } catch (ImagickException $e) {
        header("Location: error.php?error=" . urlencode("Error converting image: " . $e->getMessage()));
        exit;
    }
}

function cleanupFiles($files) {
    foreach ($files as $file) {
        unlink($file);
    	logMessage("Removed file " . $file);
    }
    logMessage("Source files deleted!");
}
?>

<!DOCTYPE html>
<html>
<head>
	<script defer src="https://umami.spindlecrank.com/script.js" data-website-id="c78b165b-efb2-48d4-b67d-a457db6e4ad9"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
	<link rel="manifest" href="/site.webmanifest">
	<link rel="mask-icon" href="/safari-pinned-tab.svg" color="#5bbad5">
	<meta name="msapplication-TileColor" content="#da532c">
	<meta name="theme-color" content="#ffffff">
	<meta name="google-site-verification" content="gu3duYB5OEsqTehyFOA1M1OOzJ--AfbTsk4dt_CVJTU" />
    <title>Image Converter</title>
	<meta name="description" content="Welcome to Image Format Converter! Convert your images to different formats quickly and easily.">
    <meta name="keywords" content="Image Converter, Spindlecrank, JPG, PNG, BMP, GIF, ICO">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
	<script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        #waiting {
           display: none;
            font-size: 1.5em;
            font-weight: bold;
			color: white;
            animation: bounce 1s infinite;
        }
    </style>
</head>
<body class="bg-blue-500 flex flex-col items-center justify-center min-h-screen w-3/4 sm:w-3/4 md:w-3/4 lg:w-1/2 xl:w-1/2 2xl:w-1/2 mx-auto">
   <div>
        <div class="shadow-lg p-6 bg-indigo-500 rounded-lg flex flex-col items-center">
        <h1 class="text-m sm:text-l md:text-xl lg:text-xl xl:text-xl text-white font-bold mb-3 animate__animated animate__rubberBand">Welcome to the Image Format Converter</h1>
        <h3 class="text-s sm:text-m md:text-m lg:text-m xl:text-m text-white font-bold mb-2">Currently converts BMP, JPG, PNG, GIF, and ICO Images.</h3>
       <div id="form-div" class="shadow-lg rounded-lg bg-white p-4 flex flex-col items-left space-y-4">
    	<form id="convertForm" method="POST" enctype="multipart/form-data">
            <label class="block mb-2">Select up to 10 images:</label>
            <input type="file" name="images[]" accept="image/*" multiple required class="border p-2 mb-2 w-full">
            <label class="block mb-2">Select format to convert to:</label>
            <select name="format" required class="border p-2 mb-2 w-full">
                <option value="">>--Please choose an option--<</option>
                <option value="jpg">JPG</option>
                <option value="png">PNG</option>
                <option value="bmp">BMP</option>
                <option value="gif">GIF</option>
                <option value="ico">ICO</option>
            </select>
            <button id="submit" type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Convert</button>
        </form>
        </div>
        <div id="result" class="ml-1 mr-6 mb-6 mt-6 flex flex-col items-center w-full"><?php echo $resp; ?></div>
        <div id="waiting" class="ml-1 mr-6 mb-6 mt-6 flex flex-col items-center text-center w-full"></div>
    </div>
   </div>
<script>
	document.getElementById('submit').addEventListener('click', function(e) {
   		var waitingDiv = document.getElementById('waiting');
    	var resultDiv = document.getElementById('result');
    	waitingDiv.innerHTML = "Converting Files!";
    	waitingDiv.style.display = 'block'; // Display the waiting message

    	var checkResult = setInterval(function() {
      		if (resultDiv.innerHTML.trim() !== "") {
        		waitingDiv.style.display = 'none'; // Hide the waiting message
      			resultDiv.style.display = 'block';
        		clearInterval(checkResult);
    		}
    	}, 1000); // checks every second
	});

	document.addEventListener('DOMContentLoaded', function() {
    	const form = document.getElementById('convertForm');
    	const dButton = document.getElementById('downloadButton');
    	const fileInput = document.getElementById('images');

      	dButton.addEventListener('click', function(event) { // Added event parameter
      		window.location.href = event.target.getAttribute('data-href');
	    	var resultDiv = document.getElementById('result');
     		resultDiv.innerHTML = "";
       		const formData = new FormData(form);
      		formData.delete('images[]'); 
      		form.reset();
		});
	});
</script>
</body>
</html>
