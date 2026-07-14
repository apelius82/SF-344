<?php
/**
 * Upload Flash Image API
 * 
 * Handles authenticated uploads for the "Additional Images" feature.
 * Images are resized and compressed before being associated with sf_flash_images table.
 * 
 * This endpoint requires authentication and processes images with the same
 * optimization strategy as temporary uploads to ensure consistency and storage efficiency.
 * 
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/image_utils.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Flash images directory
$flashDir = __DIR__ . '/../../uploads/flash_images/';
if (!is_dir($flashDir)) {
    @mkdir($flashDir, 0755, true);
}

// Validate file upload
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload failed']);
    exit;
}

$file = $_FILES['image'];

// Get optional flash_id parameter (for associating with a specific flash report)
$flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : null;

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
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
    echo json_encode(['ok' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WEBP']);
    exit;
}

// Validate file size (e.g., max 20MB before processing)
$maxFileSize = 20 * 1024 * 1024; // 20MB
if ($file['size'] > $maxFileSize) {
    echo json_encode(['ok' => false, 'error' => 'File too large. Maximum size: 20MB']);
    exit;
}

// Generate unique filename with secure extension based on MIME type
$extensionMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp'
];
$ext = $extensionMap[$mimeType] ?? 'jpg';
$userId = $_SESSION['user_id'];
$filename = 'flash_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

$destPath = $flashDir . $filename;

// Move uploaded file to temporary location first
$tempUploadPath = $destPath . '.tmp';
if (!move_uploaded_file($file['tmp_name'], $tempUploadPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Process image: resize and compress (max 1920x1920, quality 80%)
if (!sf_resize_image($tempUploadPath, $destPath, 1920, 1920, 80)) {
    // If resize fails, use original file
    @rename($tempUploadPath, $destPath);
    error_log("Failed to resize flash image, using original: $filename");
} else {
    // Remove temporary file after successful processing
    @unlink($tempUploadPath);
}

// Note: In a complete implementation, you would insert a record into sf_flash_images table here
// Example:
// $stmt = $pdo->prepare("INSERT INTO sf_flash_images (flash_id, user_id, filename, uploaded_at) VALUES (?, ?, ?, NOW())");
// $stmt->execute([$flashId, $userId, $filename]);
// $imageId = $pdo->lastInsertId();

// For now, return null for image_id since this is a simulated implementation
$imageId = null;

// Return success response with file info
$basePath = rtrim($config['base_path'] ?? '', '/');
echo json_encode([
    'ok' => true,
    'image_id' => $imageId,
    'filename' => $filename,
    'url' => $basePath . '/uploads/flash_images/' . $filename,
    'flash_id' => $flashId,
    'message' => 'Image uploaded and processed successfully'
]);