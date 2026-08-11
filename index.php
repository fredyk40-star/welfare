<?php
// Root index.php router for Vercel
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If it's a direct file request that exists, let the filesystem handle it
if ($requestUri !== '/' && file_exists(__DIR__ . $requestUri)) {
    return false; 
}

// Otherwise, route requests to your main dashboard or login page
require_once __DIR__ . '/login.php';
