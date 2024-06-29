<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: sans-serif;
        }

        .error-message {
            text-align: center;
            font-size: 1.2em;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="error-message">
        <?php 
            $errorMessage = isset($_GET['error']) ? $_GET['error'] : "An unknown error occurred.";
            echo htmlspecialchars($errorMessage); 
        ?>
    </div>
    <button onclick="window.location.href = 'https://convert.yoursite.com';">Return to Converter</button>
</body>
</html>
