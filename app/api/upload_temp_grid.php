<?php
// app/api/upload_temp_grid.php
// Accepts a grid bitmap uploaded as a file (via $_FILES['grid_image']),
// saves it as a temp file, and returns the filename.
// Follows the same pattern as upload_temp_image.php.

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

// Temporary directory (same as regular image uploads)
$tempDir = __DIR__ . '/../../uploads/temp/';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// Garbage collector: 10% probability of running (same as upload_temp_image.php)
if (rand(1, 100) <= 10) {
    $now = time();
    $maxAge = 24 * 60 * 60; // 24 hours in seconds

    $handle = @opendir($tempDir);
    if ($handle !== false) {
        try {
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $filePath = $tempDir . $file;
                if (is_file($filePath)) {
                    $fileAge = $now - filemtime($filePath);
                    if ($fileAge > $maxAge) {
                        @unlink($filePath);
                    }
                }
            }
        } finally {
            closedir($handle);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['grid_image']) || $_FILES['grid_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload failed']);
    exit;
}

$file = $_FILES['grid_image'];

// Ensure the temp file is a legitimate upload (guards against path manipulation)
if (!is_uploaded_file($file['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid upload']);
    exit;
}

// Validate MIME type using finfo (whitelist: common image types)
$allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to initialize file type checker']);
    exit;
}

$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mimeType === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to detect file type']);
    exit;
}

if (!in_array($mimeType, $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
    exit;
}

// Determine extension from MIME type
$mimeToExt = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$ext = $mimeToExt[$mimeType] ?? 'png';

// Session-based unique filename (mirrors upload_temp_image.php naming)
$sessionId = session_id();
if (!$sessionId) {
    // No active session — refuse rather than allow unauthenticated uploads
    // (protect.php already enforces login, so this is an extra safeguard)
    echo json_encode(['ok' => false, 'error' => 'No active session']);
    exit;
}
$filename = 'temp_grid_' . $sessionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $tempDir . $filename;

// Ensure the destination resolves within the intended temp directory
$resolvedTempDir = realpath($tempDir);
$resolvedDest    = realpath(dirname($destPath)) . DIRECTORY_SEPARATOR . basename($destPath);
if ($resolvedTempDir === false || strpos($resolvedDest, $resolvedTempDir . DIRECTORY_SEPARATOR) !== 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid destination path']);
    exit;
}

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

@chmod($destPath, 0644);

echo json_encode([
    'ok'       => true,
    'filename' => $filename,
]);