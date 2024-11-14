<?php

date_default_timezone_set('America/Chicago');

//Globals
global $zip_dir;
global $upload_dir;
global $converted_dir;
global $allowed_formats;

// Misc Vars
$zip_dir='zips/';
$upload_dir='uploads/';
$converted_dir='converted/';
$directories = ['uploads/', 'converted/', 'zips/']; // Array of directories
$owner = 'www-data';
$permissions = 0755; // Octal representation of 755
$user_files = [];
// Response echo
$resp = '';

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

function cleanupFiles($files) {
    foreach ($files as $file) {
        unlink($file);
    	logMessage("Removed file " . $file);
    }
    logMessage("Source files deleted!");
}

function convertImage($source, $destination, $format) {
    $allowedFormats = ['pdf', 'jpeg', 'jpg', 'png', 'bmp', 'gif', 'ico', 'tiff', 'webp', 'pdf'];
    $imageInfo = getimagesize($source);

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
        $image->writeImage($destination);
        logMessage("Image converted to format: " . $format);
    } catch (Exception $e) {
        logMessage("Error converting image: " . $e->getMessage());
        header("Location: error.php?error=" . urlencode("Conversion Error: " . $e->getMessage()));
        exit();
    }
}

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

		$allowed_formats = ['pdf', 'jpeg', 'jpg', 'png', 'bmp', 'gif', 'ico', 'tiff', 'webp', 'pdf'];
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
    $resp .= '<div class="mb-4 flex items-center justify-center"> <button data-href="download.php?file=' . urlencode($zip_path) . '" id="downloadButton" class="inline-flex items-center px-4 py-2 font-semibold leading-6 text-sm shadow rounded-md text-white bg-indigo-500 hover:bg-indigo-400 transition ease-in-out duration-150">
         <div class="animate-bounce bg-ingigo-800 dark:bg-slate-800 mr-3 p-2 w-10 h-10 ring-1 ring-slate-900/5 dark:ring-slate-200/20 shadow-lg rounded-full flex items-center justify-center"><svg class="w-6 h-6 text-white" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor"> <path d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg></div> Download Zip File </button></div>';

    //$resp .= '<button data-href="download.php?file=' . urlencode($zip_path) . '" id="downloadButton" class="button-class flex flex-wrap item-center p-1 mb-4 bg-green-500 hover:bg-green-800 text-white rounded-md transform transition duration-500 ease-in-out hover:scale-115 w-1/2">Download ZIP file</button>';
    ob_end_clean();
    cleanupFiles($user_files);
    logMessage("Removing users files from server!");
}

//Old Convert Code
/*
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
*/
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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.16/dist/tailwind.min.css" rel="stylesheet">
    <style>
        * {
            font-family: Arial, sans-serif, ui-sans-serif, ui-serif, serif;
        }
        
        @media (min-width: *tablet-min-width*px) and (max-width: *tablet-max-width*px) { 
 `           body { 
                width: 100%; /* Ensure body takes full width */
                /* Add other tablet-specific styles if needed */
             }
            .content-container { /* If you have a container for your form */
                width: 100%; 
            }
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        /* #waiting {
           display: none;
            font-size: 1.5em;
            font-weight: bold;
			color: red;
            animation: bounce 1s infinite;
        } */
    </style>
</head>
<body class="bg-gradient-to-r from-indigo-700 via-purple-500 to-blue-300 flex justify-center items-center min-h-screen w-full">
    <div class="w-3/4 p-6 bg-gray-600 shadow-md rounded-lg animate__animated animate__slideInRight focus:scale-115">
        <!-- Title Section -->
        <h1 class="text-3xl text-2xl text-center text-white mb-4 animate__animated animate__delay-1s animate__fadeInDown">Image Format Converter</h1>
        <p class="text-l text-center text-white mb-6">Convert your images to multiple formats easily and quickly.</p>
        <!-- Form Section -->
        <div id="form-div" class="flex flex-col w-3/4 justify-self-center">
            <form id="convertForm" method="POST" enctype="multipart/form-data" class="w-full space-y-4">
            
                <!-- File Upload Field -->
                <div id="upload-div" class="flex flex-col justify-left">
                    <label class="block text-white font-medium mb-1 justify-left">Select Images:</label>
                    <input type="file" name="images[]" accept="image/*" multiple required class="text-white w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 justify-left">
                    <p class="text-xs text-white mt-1">Upload up to 10 images at a time.</p>
                </div>

                <!-- Format Selection Field -->
                <div id="format-div" class="flex flex-col justify-left">
                    <label class="block text-white font-medium mb-1 justify-left">Select Format to Convert To:</label>
                    <select name="format" required class="text-black w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 justify-left">
                        <option value="">--Choose an option--</option>
                        <option value="jpg">JPG</option>
                        <option value="png">PNG</option>
                        <option value="bmp">BMP</option>
                        <option value="gif">GIF</option>
                        <option value="ico">ICO</option>
                        <option value="tiff">TIFF</option>
                        <option value="webp">WEBP</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
            </div>
            <div id="button-div" class="flex flex-col w-full items-center mt-8">
                <button type="submit" id="submit" class="mb-4 w-2/5 sm:w-2/5 md:w-2/5 l:w-2/5 xl:w-1/5 2xl:w-1/5 p-3 bg-blue-500 text-white font-semibold shadow rounded-md transform transition duration-500 hover:bg-blue-600 hover:scale-110">Convert</button>
            </div>
        </form>
        <!-- Working and Download Divs -->
        <div id="result" class="mt-6 flex flex-col items-center text-center w-full"><?php echo $resp; ?></div>
        <div id='waiting' class="mb-4 flex flex-row w-full items-center justify-center hidden">
            <div class="flex items-center justify-center"> 
                <button type="button" class="inline-flex items-center p-4 font-semibold leading-6 text-sm shadow rounded-md text-white bg-indigo-500 hover:bg-indigo-400 transition ease-in-out duration-150 cursor-not-allowed" disabled="">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing...
                </button>
            </div>
        </div>
        <!-- <div id="waiting" class="text-white ml-1 mr-6 mb-6 mt-6 flex flex-col items-center text-center w-full"></div> -->
        <div class="w-full flex flex-col items-center justify-center">
		    <p class="w-full text-center mt-4 mb-8 font-semibold text-l hover:text-green-500 text-white italic animate__animated animate__delay-3s animate__zoomInUp"><span id="powered">Proudly Powered By spindlecrank.com</span></p>
	    </div>
    </div>
<script>
	document.getElementById('submit').addEventListener('click', function(e) {
   		var waitingDiv = document.getElementById('waiting');
    	var resultDiv = document.getElementById('result');
    	//waitingDiv.innerHTML = "Converting Files!";
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
