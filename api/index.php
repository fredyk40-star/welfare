<?php
// Single-entry PHP router for Vercel (vercel-php@0.9.0).
// All requests are routed here; we map the path to the real PHP page
// under the project root and include it. Relative requires inside those
// pages resolve from their own directory, so includes/header.php etc. work.

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestUri === '' || $requestUri === null) {
    $requestUri = '/';
}

// Root -> static landing page
if ($requestUri === '/') {
    $landing = __DIR__ . '/../index.html';
    if (file_exists($landing)) {
        readfile($landing);
        exit;
    }
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Only allow requests that map to a real .php file directly under the project root.
// Block traversal and anything outside the allowed top-level dirs.
$allowedTop = ['member', 'treasurer', 'api'];
$normalized = ltrim($requestUri, '/');

// Reject anything with ".." or that does not end in .php
if (strpos($normalized, '..') !== false || substr($normalized, -4) !== '.php') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$fullPath = __DIR__ . '/../' . $normalized;
if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Safety: only serve from allowed top-level directories
$top = explode('/', $normalized)[0];
if (!in_array($top, $allowedTop, true)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

require_once $fullPath;
