<?php
// GYF Welfare - Vercel Blob storage helper (PHP, no npm required)
//
// Uses the Vercel Blob REST API via cURL. Activated only when the
// BLOB_READ_WRITE_TOKEN env var is present (i.e. on Vercel). Locally the
// app keeps using the local uploads/ folder, so dev behavior is unchanged.
//
// Stored value in `members.passport_photo`:
//   - Local/dev: just a filename, e.g. "a1b2c3d4.jpg"
//   - Vercel/prod: a full Blob URL, e.g. "https://xxxx.public.blob.vercel-storage.com/..."
// displayPhotoUrl() handles both transparently.

function blobIsEnabled() {
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    return !empty($token);
}

/**
 * Upload a local file to Vercel Blob.
 * @return array ['success'=>bool,'url'=>string,'message'=>string]
 */
function blobUploadFile($localPath, $filename) {
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    if (empty($token)) {
        return ['success' => false, 'url' => '', 'message' => 'Blob not configured'];
    }
    if (!file_exists($localPath)) {
        return ['success' => false, 'url' => '', 'message' => 'Source file missing'];
    }

    $blobPath = 'photos/' . $filename;
    $addUrl = 'https://blob.vercel-storage.com/?path=' . urlencode($blobPath);
    $ch = curl_init($addUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
            'x-api-version: 6'   // Vercel Blob REST API version header
        ],
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => file_get_contents($localPath)
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('Blob upload cURL error: ' . $err);
        return ['success' => false, 'url' => '', 'message' => $err];
    }
    if ($code < 200 || $code >= 300) {
        error_log('Blob upload failed (HTTP ' . $code . '): ' . $resp);
        return ['success' => false, 'url' => '', 'message' => 'Upload failed'];
    }
    $data = json_decode($resp, true);
    if (empty($data['url'])) {
        error_log('Blob upload: no url in response: ' . $resp);
        return ['success' => false, 'url' => '', 'message' => 'No URL returned'];
    }
    return ['success' => true, 'url' => $data['url'], 'message' => ''];
}

/**
 * Delete a Blob by its full URL. No-op for local filenames.
 */
function blobDeleteUrl($value) {
    if (empty($value) || strpos($value, 'http') !== 0) {
        return true; // not a blob URL (local filename) -> nothing to do
    }
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    if (empty($token)) return true;
    $ch = curl_init($value);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

/**
 * Return a displayable image URL for a stored passport_photo value.
 * Handles both local filenames and full Blob URLs.
 */
function displayPhotoUrl($value) {
    if (empty($value)) return '';
    if (strpos($value, 'http') === 0) {
        return $value; // already a Blob/public URL
    }
    return APP_URL . '/uploads/photos/' . basename($value);
}
