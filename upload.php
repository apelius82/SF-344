<?php
/**
 * SafetyFlash System - Secure Image Upload Endpoint
 * 
 * Handles authenticated image uploads with comprehensive security validation: 
 * - Authentication and role-based access control
 * - File type validation via MIME inspection and magic bytes
 * - Image dimension validation
 * - File size limits
 * - Secure file naming and storage
 * - Audit logging of all uploads
 * 
 * @package SafetyFlash
 * @subpackage API
 * @version 1.0.0
 * @author apelius82
 * @created 2025-12-30
 */

// Load bootstrap and configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/includes/bootstrap.php';

// ============================================================================
// CONFIGURATION
// ============================================================================

// Define upload configuration
define('SF_UPLOAD_DIR', $config['storage']['images_dir'] ?? (__DIR__ . '/uploads/images'));
define('SF_UPLOAD_URL', rtrim((string)($config['storage']['images_url'] ?? (($config['base_url'] ?? '') . '/uploads/images')), '/') . '/');
define('SF_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('SF_MAX_IMAGE_WIDTH', 6000);
define('SF_MAX_IMAGE_HEIGHT', 6000);
define('SF_MIN_IMAGE_WIDTH', 100);
define('SF_MIN_IMAGE_HEIGHT', 100);

// Allowed MIME types (must be validated with finfo)
$allowedMimeTypes = ['image/jpeg', 'image/png'];

// Allowed file extensions (uppercase and lowercase)
$allowedExtensions = ['jpg', 'jpeg', 'png'];

// ============================================================================
// RESPONSE HELPER FUNCTIONS
// ============================================================================

/**
 * Send JSON response
 * 
 * @param bool $success
 * @param string $message
 * @param string $errorCode
 * @param array $additionalData
 * @return void
 */
function sendResponse($success, $message = '', $errorCode = '', $additionalData = [])
{
    http_response_code($success ? 200 :  400);
    
    $response = [
        'success' => $success,
        'message' => $message,
    ];
    
    if (!empty($errorCode)) {
        $response['error_code'] = $errorCode;
    }
    
    $response = array_merge($response, $additionalData);
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * 
 * @param string $message
 * @param string $errorCode
 * @param int $httpCode
 * @return void
 */
function sendError($message, $errorCode = 'UNKNOWN_ERROR', $httpCode = 400)
{
    http_response_code($httpCode);
    
    // Log security violation
    SecurityEventLogger::log('upload_error', [
        'error_code' => $errorCode,
        'message' => $message
    ], 'warning');
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error_code' => $errorCode
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// REQUEST VALIDATION
// ============================================================================

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Validate request content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'multipart/form-data') === false) {
    sendError('Invalid content type', 'INVALID_CONTENT_TYPE', 400);
}

// Validate API request (authentication and CSRF)
$validation = APIRequestMiddleware::validate(true, true);

if (!$validation['success']) {
    sendError(
        $validation['error'],
        $validation['code'] ?? 'VALIDATION_FAILED',
        401
    );
}

// ============================================================================
// AUTHORIZATION CHECK
// ============================================================================

// Get authenticated user
$user = AuthenticationMiddleware::getAuthenticatedUser();
if (!$user) {
    sendError('User not authenticated', 'NOT_AUTHENTICATED', 401);
}

// Check user role - only admin and writer can upload
$allowedRoles = ['admin', 'writer'];
$userRole = $user['role'] ?? 'user';

if (!in_array($userRole, $allowedRoles, true)) {
    SecurityEventLogger::log('upload_unauthorized', [
        'user_id' => $user['user_id'],
        'user_role' => $userRole
    ], 'warning');
    
    sendError('Insufficient permissions for file upload', 'UNAUTHORIZED', 403);
}

// ============================================================================
// FILE VALIDATION
// ============================================================================

// Check if file is present in request
if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    sendError('No file provided in request', 'NO_FILE_PROVIDED', 400);
}

$file = $_FILES['image'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
        UPLOAD_ERR_PARTIAL => 'File upload incomplete',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload blocked by extension',
    ];
    
    $errorMessage = $uploadErrors[$file['error']] ?? 'Unknown upload error';
    sendError($errorMessage, "UPLOAD_ERROR_{$file['error']}", 400);
}

// Validate temporary file exists
if (!is_uploaded_file($file['tmp_name'])) {
    sendError('Invalid uploaded file', 'INVALID_UPLOAD', 400);
}

// Validate file size
$fileSize = filesize($file['tmp_name']);
if ($fileSize === false || $fileSize > SF_MAX_FILE_SIZE) {
    sendError(
        'File exceeds maximum size limit (' . (SF_MAX_FILE_SIZE / 1024 / 1024) . 'MB)',
        'FILE_TOO_LARGE',
        400
    );
}

if ($fileSize < 1000) { // Minimum 1KB
    sendError('File is too small', 'FILE_TOO_SMALL', 400);
}

// ============================================================================
// MIME TYPE VALIDATION
// ============================================================================

// Use finfo to detect actual MIME type (not just extension)
if (!function_exists('finfo_open')) {
    sendError('MIME type detection not available', 'FINFO_UNAVAILABLE', 500);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($detectedMimeType === false) {
    sendError('Unable to determine file type', 'MIME_DETECTION_FAILED', 400);
}

// Validate detected MIME type
if (!in_array($detectedMimeType, $allowedMimeTypes, true)) {
    SecurityEventLogger::log('upload_invalid_mime', [
        'detected_mime' => $detectedMimeType,
        'filename' => $file['name'] ?? 'unknown'
    ], 'warning');
    
    sendError('File type not allowed', 'INVALID_MIME_TYPE', 400);
}

// ============================================================================
// EXTENSION VALIDATION
// ============================================================================

// Extract and validate file extension
$originalFilename = basename($file['name'] ?? '');
$fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

if (empty($fileExtension)) {
    sendError('File has no extension', 'NO_EXTENSION', 400);
}

// Validate extension against whitelist
if (!in_array($fileExtension, $allowedExtensions, true)) {
    sendError('File extension not allowed', 'INVALID_EXTENSION', 400);
}

// Map MIME type to expected extension
$mimeToExtension = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
];

$expectedExtensions = $mimeToExtension[$detectedMimeType] ?? [];
if (!in_array($fileExtension, $expectedExtensions, true)) {
    SecurityEventLogger::log('upload_extension_mismatch', [
        'detected_mime' => $detectedMimeType,
        'file_extension' => $fileExtension
    ], 'warning');
    
    sendError('File extension does not match file type', 'EXTENSION_MISMATCH', 400);
}

// ============================================================================
// IMAGE VALIDATION
// ============================================================================

// Validate that file is actually an image using getimagesize()
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false || ! is_array($imageInfo) || count($imageInfo) < 2) {
    sendError('File is not a valid image', 'INVALID_IMAGE', 400);
}

$imageWidth = $imageInfo[0] ?? 0;
$imageHeight = $imageInfo[1] ??  0;
$detectedImageMime = $imageInfo['mime'] ?? '';

if ($imageWidth < SF_MIN_IMAGE_WIDTH || $imageHeight < SF_MIN_IMAGE_HEIGHT) {
    sendError(
        'Image dimensions too small (minimum ' . SF_MIN_IMAGE_WIDTH . 'x' . SF_MIN_IMAGE_HEIGHT . 'px)',
        'IMAGE_TOO_SMALL',
        400
    );
}

if ($imageWidth > SF_MAX_IMAGE_WIDTH || $imageHeight > SF_MAX_IMAGE_HEIGHT) {
    sendError(
        'Image dimensions too large (maximum ' .  SF_MAX_IMAGE_WIDTH . 'x' . SF_MAX_IMAGE_HEIGHT . 'px)',
        'IMAGE_TOO_LARGE',
        400
    );
}

// Validate that detected image MIME matches finfo detection
if (!in_array($detectedImageMime, $allowedMimeTypes, true)) {
    SecurityEventLogger::log('upload_getimagesize_mime_mismatch', [
        'finfo_mime' => $detectedMimeType,
        'getimagesize_mime' => $detectedImageMime
    ], 'warning');
    
    sendError('Image MIME type validation failed', 'IMAGE_MIME_MISMATCH', 400);
}

// ============================================================================
// SECURE FILE NAMING AND STORAGE
// ============================================================================

// Create upload directory if it doesn't exist
if (!is_dir(SF_UPLOAD_DIR)) {
    if (!@mkdir(SF_UPLOAD_DIR, 0750, true)) {
        SecurityEventLogger::log('upload_mkdir_failed', [
            'directory' => SF_UPLOAD_DIR
        ], 'error');
        
        sendError('Cannot create upload directory', 'MKDIR_FAILED', 500);
    }
}

// Verify directory permissions
if (!is_writable(SF_UPLOAD_DIR)) {
    SecurityEventLogger::log('upload_dir_not_writable', [
        'directory' => SF_UPLOAD_DIR
    ], 'error');
    
    sendError('Upload directory is not writable', 'DIR_NOT_WRITABLE', 500);
}

// Generate secure filename
// Format: sf_USERID_TIMESTAMP_RANDOMHEX. ext
$userId = $user['user_id'] ?? 0;
$timestamp = time();
$randomHex = bin2hex(random_bytes(8));
$secureFilename = sprintf(
    'sf_%d_%d_%s.%s',
    $userId,
    $timestamp,
    $randomHex,
    $fileExtension
);

// Ensure filename doesn't contain any path traversal attempts
$secureFilename = basename($secureFilename);

$targetPath = SF_UPLOAD_DIR .  DIRECTORY_SEPARATOR . $secureFilename;

// Verify target path is within upload directory (prevent directory traversal)
$realUploadDir = realpath(SF_UPLOAD_DIR);
if ($realUploadDir === false) {
    sendError('Upload directory path invalid', 'UPLOAD_DIR_INVALID', 500);
}
$realUploadDir = rtrim($realUploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$realTargetDir = realpath(dirname($targetPath));
if ($realTargetDir === false) {
    sendError('Target directory path invalid', 'TARGET_DIR_INVALID', 500);
}
$realTargetPath = rtrim($realTargetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($targetPath);

if (strpos($realTargetPath, $realUploadDir) !== 0) {
    SecurityEventLogger::log('upload_path_traversal_attempt', [
        'target_path' => $targetPath,
        'real_target' => $realTargetPath,
        'real_upload' => $realUploadDir,
    ], 'error');

    sendError('Invalid target path', 'PATH_TRAVERSAL', 400);
}

// ============================================================================
// FILE UPLOAD AND STORAGE
// ============================================================================

// Move uploaded file to destination
if (! move_uploaded_file($file['tmp_name'], $targetPath)) {
    SecurityEventLogger::log('upload_move_failed', [
        'source' => $file['tmp_name'],
        'target' => $targetPath
    ], 'error');
    
    sendError('Failed to save uploaded file', 'SAVE_FAILED', 500);
}

// Set restrictive file permissions (owner read/write only)
@chmod($targetPath, 0640);

// ============================================================================
// AUDIT LOGGING
// ============================================================================

// Log successful upload
SecurityEventLogger::log('image_uploaded', [
    'filename' => $secureFilename,
    'original_filename' => $originalFilename,
    'file_size' => $fileSize,
    'image_width' => $imageWidth,
    'image_height' => $imageHeight,
    'mime_type' => $detectedMimeType,
    'user_id' => $userId
], 'info');

// ============================================================================
// SUCCESS RESPONSE
// ============================================================================

// Build public URL (relative to web root)
$publicUrl = SF_UPLOAD_URL . $secureFilename;

sendResponse(true, 'File uploaded successfully', '', [
    'filename' => $secureFilename,
    'original_filename' => $originalFilename,
    'url' => $publicUrl,
    'size' => $fileSize,
    'width' => $imageWidth,
    'height' => $imageHeight,
    'mime_type' => $detectedMimeType,
    'uploaded_at' => date('Y-m-d H:i:s'),
]);