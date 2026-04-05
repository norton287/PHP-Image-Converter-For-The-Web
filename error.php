<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/functions.php';

// Per-request nonce for the inline script (eliminates 'unsafe-inline' CSP requirement)
$nonce = base64_encode(random_bytes(16));

header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'nonce-{$nonce}'; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    . "img-src 'self' data:; "
    . "font-src 'self' data:; "
    . "object-src 'none'; "
    . "frame-ancestors 'none';"
);

$nonceSafe    = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
$errorMessage = isset($_GET['error']) && is_string($_GET['error'])
    ? $_GET['error']
    : 'An unknown error occurred.';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <!-- FOUC prevention: apply saved theme before first paint -->
    <script nonce="<?= $nonceSafe ?>">
    (function(){
        try {
            var t = localStorage.getItem('app-theme') ||
                (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', t);
        } catch(e) {}
    }());
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error — Image Converter</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.16/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-page">
    <div class="app-card" style="max-width:32rem; text-align:center;">

        <button id="themeToggle" class="theme-toggle" type="button"
                title="Toggle light/dark mode" aria-label="Toggle light/dark mode">
            <svg class="icon-sun w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>
            </svg>
            <svg class="icon-moon w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
        </button>

        <div style="font-size:3rem; color:#f87171; margin-bottom:1rem;">&#9888;</div>

        <p class="text-2xl font-bold c-primary mb-6">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="/" class="btn-download" style="display:inline-flex;">
            Return to Converter
        </a>
    </div>

    <script nonce="<?= $nonceSafe ?>">
    (function(){
        var btn = document.getElementById('themeToggle');
        if (!btn) return;
        btn.addEventListener('click', function(){
            var cur  = document.documentElement.getAttribute('data-theme') || 'dark';
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('app-theme', next); } catch(e) {}
        });
    }());
    </script>
</body>
</html>
