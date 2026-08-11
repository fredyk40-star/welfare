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

// Static asset serving.
// Because vercel-php@0.9.0 with a single `builds` entry emits only the Lambda
// (no static-file output), Vercel's `{ "handle": "filesystem" }` matches nothing
// and every /assets/* 404s. The Lambda does have filesystem access to the whole
// project, so we stream real static files here with correct Content-Type.
$staticMime = [
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'application/javascript; charset=utf-8',
    'mjs'   => 'application/javascript; charset=utf-8',
    'json'  => 'application/json; charset=utf-8',
    'map'   => 'application/json; charset=utf-8',
    'txt'   => 'text/plain; charset=utf-8',
    'html'  => 'text/html; charset=utf-8',
    'htm'   => 'text/html; charset=utf-8',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'svg'   => 'image/svg+xml',
    'webp'  => 'image/webp',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
    'eot'   => 'application/vnd.ms-fontobject',
    'pdf'   => 'application/pdf',
];
$normalizedStatic = ltrim($requestUri, '/');
// Reject traversal and anything outside the project.
if (strpos($normalizedStatic, '..') === false && $normalizedStatic !== '') {
    $staticPath = __DIR__ . '/../' . $normalizedStatic;
    if (is_file($staticPath)) {
        $ext = strtolower(pathinfo($staticPath, PATHINFO_EXTENSION));
        // Never serve PHP sources or sensitive config as static content.
        $blockedTop = ['includes', 'config', 'api', '.vercel', '.git'];
        $topDir = explode('/', $normalizedStatic)[0];
        if ($ext === 'php' || in_array(strtolower($topDir), $blockedTop, true)) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }
        if (isset($staticMime[$ext])) {
            header('Content-Type: ' . $staticMime[$ext]);
            header('Cache-Control: public, max-age=3600');
            readfile($staticPath);
            exit;
        }
    }
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
