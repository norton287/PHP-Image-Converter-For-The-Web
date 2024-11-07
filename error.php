<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex items-center justify-center h-screen">
    <div class="text-center">
        <div class="error-message text-3xl font-bold"> 
            <?php
                $errorMessage = isset($_GET['error']) ? $_GET['error'] : "An unknown error occurred.";
                echo htmlspecialchars($errorMessage); 
            ?>
        </div>
        <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded mt-6" onclick="window.location.href = 'https://convert.spindlecrank.com';">
            Return to Converter
        </button>
    </div>
</body>
</html>

