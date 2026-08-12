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
 * Upload a local/temp file to Vercel Blob via the server-upload REST API
 * (the same single PUT that @vercel/blob put() performs):
 *   PUT https://vercel.com/api/blob/?pathname=photos/<file>
 *     Authorization: Bearer <BLOB_READ_WRITE_TOKEN>
 *     x-api-version: 12
 *     x-vercel-blob-access: private   (store is private)
 *     x-content-type: <mime>
 *     x-add-random-suffix: 1
 *     body = raw bytes
 * Returns ['success'=>bool,'url'=>string,'message'=>string].
 */
function blobUploadFile($localPath, $filename, $mimeType = 'application/octet-stream') {
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    // Strip wrapping quotes that can end up in env files (e.g. "vercel_blob_...").
    $token = preg_replace('/^"(.*)"$/', '$1', (string) $token);
    if (empty($token)) {
        return ['success' => false, 'url' => '', 'message' => 'Blob not configured'];
    }
    if (!file_exists($localPath)) {
        return ['success' => false, 'url' => '', 'message' => 'Source file missing'];
    }

    $pathname = 'photos/' . $filename;
    $url = 'https://vercel.com/api/blob/?pathname=' . urlencode($pathname);
    $content = file_get_contents($localPath);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'x-api-version: 12',
            'x-vercel-blob-access: private',
            'x-content-type: ' . $mimeType,
            'x-add-random-suffix: 1',
        ],
        CURLOPT_CUSTOMREQUEST   => 'PUT',
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_POSTFIELDS      => $content,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('Blob upload cURL error: ' . $err);
        return ['success' => false, 'url' => '', 'message' => $err];
    }
    if ($code < 200 || $code >= 300) {
        error_log('Blob upload failed (HTTP ' . $code . '): ' . $resp);
        return ['success' => false, 'url' => '', 'message' => 'Upload failed (HTTP ' . $code . ')'];
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
