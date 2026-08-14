<?php
/**
 * Blob proxy: serves private Vercel Blob images through the server so browsers
 * never need the read token. Only proxies blob.vercel-storage.com URLs.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$url = cleanInput($_GET['url'] ?? '');
if (empty($url) || strpos($url, 'blob.vercel-storage.com') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid blob URL']);
    exit;
}

$token = getenv('BLOB_READ_WRITE_TOKEN');
if (empty($token)) {
    http_response_code(500);
    echo json_encode(['error' => 'Blob not configured']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HEADER => true,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $http_code < 200 || $http_code >= 300) {
    http_response_code($http_code >= 200 && $http_code < 600 ? $http_code : 502);
    echo json_encode(['error' => 'Blob fetch failed', 'details' => $err]);
    exit;
}

$body = substr($response, $header_size);
if (!empty($content_type)) {
    header('Content-Type: ' . $content_type);
}
header('Cache-Control: public, max-age=3600');
echo $body;
