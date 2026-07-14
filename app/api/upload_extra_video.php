<?php
/**
 * Upload Extra Video API
 *
 * Handles uploading additional videos to the temp directory.
 * Videos are stored as-is (no transcoding). Actual association
 * with a flash happens in add_extra_video.php (view page) or
 * save_flash.php (form submission).
 *
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
// Skip automatic CSRF validation since we validate manually below
define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure $config is available
if (!isset($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server configuration error. Please contact system administrator.']);
    exit;
}

try {
    // Validate CSRF token
    if (!sf_csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    // Temporary directory for uploads
    $tempDir = __DIR__ . '/../../uploads/temp/';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }

    // Garbage collector: 10% probability of running (removes files older than 24 h)
    if (rand(1, 100) <= 10) {
        $now = time();
        $maxAge = 24 * 60 * 60;
        $handle = @opendir($tempDir);
        if ($handle !== false) {
            try {
                while (($file = readdir($handle)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $filePath = $tempDir . $file;
                    if (is_file($filePath) && ($now - filemtime($filePath)) > $maxAge) {
                        @unlink($filePath);
                    }
                }
            } finally {
                closedir($handle);
            }
        }
    }

    // Validate file upload
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']);
        exit;
    }

    $file = $_FILES['video'];

    // Validate file type via MIME inspection
    $allowedTypes = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
    ];
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
        echo json_encode(['ok' => false, 'error' => 'Invalid file type. Allowed: MP4, WebM, OGG, MOV, AVI, MKV']);
        exit;
    }

    // Validate file size (max 200 MB)
    $maxFileSize = 200 * 1024 * 1024;
    if ($file['size'] > $maxFileSize) {
        echo json_encode(['ok' => false, 'error' => 'File too large. Maximum size: 200MB']);
        exit;
    }

    // Derive extension from validated MIME type
    $extensionMap = [
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/ogg'        => 'ogv',
        'video/quicktime'  => 'mov',
        'video/x-msvideo'  => 'avi',
        'video/x-matroska' => 'mkv',
    ];
    $ext = $extensionMap[$mimeType] ?? 'mp4';

    // Create unique filename with temp_video_ prefix so save_flash.php can identify it
    $sessionId = session_id() ?: 'anon';
    $filename = 'temp_video_' . $sessionId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

    $destPath = $tempDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    // Return success response
    $basePath = rtrim($config['base_url'] ?? '', '/');
    echo json_encode([
        'ok'                => true,
        'filename'          => $filename,
        'original_filename' => $file['name'],
        'url'               => $basePath . '/uploads/temp/' . $filename,
        'media_type'        => 'video',
    ]);
} catch (Throwable $e) {
    error_log('upload_extra_video.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
}