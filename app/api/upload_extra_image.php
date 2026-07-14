<?php
/**
 * Upload Extra Image API
 * 
 * Handles uploading additional images to the temp directory.
 * Images are immediately processed (resized) and thumbnails are generated.
 * Actual association with a flash happens in save_flash.php.
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
require_once __DIR__ . '/../includes/image_utils.php';
require_once __DIR__ . '/../includes/image_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function sf_convert_heic_to_jpeg(string $source, string $destination, int $quality = 85): bool
{
    if (!extension_loaded('imagick') || !class_exists('Imagick')) {
        error_log('sf_convert_heic_to_jpeg: Imagick extension is not available');
        return false;
    }

    try {
        $image = new Imagick();
        $image->readImage($source);

        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality($quality);
        $image->writeImage($destination);

        $image->clear();
        $image->destroy();

        return is_file($destination) && filesize($destination) > 0;
    } catch (Throwable $e) {
        error_log('sf_convert_heic_to_jpeg failed: ' . $e->getMessage());
        return false;
    }
}

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

    // Garbage collector: 10% probability of running
    if (rand(1, 100) <= 10) {
        $now = time();
        $maxAge = 24 * 60 * 60; // 24 hours
        
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

    // Validate file upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']);
        exit;
    }

    $file = $_FILES['image'];

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
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
        echo json_encode(['ok' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WEBP, HEIC, HEIF']);
        exit;
    }

    // Validate file size (max 20MB before processing)
    $maxFileSize = 20 * 1024 * 1024;
    if ($file['size'] > $maxFileSize) {
        echo json_encode(['ok' => false, 'error' => 'File too large. Maximum size: 20MB']);
        exit;
    }

    // Derive extension from validated MIME type
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'jpg',
        'image/heif' => 'jpg'
    ];
    $ext = $extensionMap[$mimeType] ?? 'jpg';

    // Create unique filename
    $sessionId = session_id() ?: 'anon';
    $filename = 'temp_extra_' . $sessionId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

    $destPath = $tempDir . $filename;

    // Move uploaded file to temporary location first
    $tempUploadPath = $destPath . '.tmp';
    if (!move_uploaded_file($file['tmp_name'], $tempUploadPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    $isHeic = ($mimeType === 'image/heic' || $mimeType === 'image/heif');

    if ($isHeic) {
        $convertedPath = $destPath . '.converted.jpg';

        if (!sf_convert_heic_to_jpeg($tempUploadPath, $convertedPath, 85)) {
            @unlink($tempUploadPath);
            echo json_encode(['ok' => false, 'error' => 'HEIC image conversion failed. Server Imagick/libheif support is required.']);
            exit;
        }

        $resizeSuccess = sf_resize_image($convertedPath, $destPath, 1920, 1920, 80);
        if (!$resizeSuccess) {
            if (!@rename($convertedPath, $destPath)) {
                @unlink($tempUploadPath);
                @unlink($convertedPath);
                echo json_encode(['ok' => false, 'error' => 'Failed to process converted HEIC image']);
                exit;
            }

            error_log("Failed to resize converted HEIC extra image, using converted JPG: $filename");
        }

        @unlink($tempUploadPath);
        @unlink($convertedPath);
    } else {
        // Process image: resize and compress (max 1920x1920, quality 80%)
        $resizeSuccess = sf_resize_image($tempUploadPath, $destPath, 1920, 1920, 80);
        if (!$resizeSuccess) {
            // If resize fails, try to use original file as fallback
            if (!@rename($tempUploadPath, $destPath)) {
                // Both resize and fallback failed - clean up and return error
                @unlink($tempUploadPath);
                echo json_encode(['ok' => false, 'error' => 'Failed to process image']);
                exit;
            }

            error_log("Failed to resize extra image, using original: $filename");
        } else {
            // Remove temporary file after successful processing
            @unlink($tempUploadPath);
        }
    }

    // Generate thumbnail
    $thumbFilename = sf_generate_thumbnail($destPath, 300, 300, 75);
    if ($thumbFilename === false) {
        error_log("Failed to generate thumbnail for extra image: $filename");
        // Continue anyway - thumbnail is optional
    }

    // Return success response
    $basePath = rtrim($config['base_url'] ?? '', '/');
    echo json_encode([
        'ok' => true,
        'filename' => $filename,
        'original_filename' => $file['name'],
        'url' => $basePath . '/uploads/temp/' . $filename,
        'thumb_url' => $thumbFilename ? ($basePath . '/uploads/temp/' . $thumbFilename) : null
    ]);
} catch (Throwable $e) {
    error_log('upload_extra_image.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
}