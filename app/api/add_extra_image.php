<?php
/**
 * Add Extra Image API
 * 
 * Associates a temporary uploaded image with a flash and moves it to permanent storage.
 * This endpoint is used from the view page to add additional images directly.
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
require_once __DIR__ . '/../services/FlashPermissionService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getInstance();
    
    // Validate CSRF token
    if (!sf_csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    // Get parameters
    $flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
    $tempFilename = trim((string)($_POST['temp_filename'] ?? ''));
    $originalFilename = trim((string)($_POST['original_filename'] ?? ''));

    // Validate parameters
    if ($flashId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid flash ID']);
        exit;
    }

    // Security: Validate temp filename follows expected pattern from upload_extra_image.php
    // Expected format: 'temp_extra_{sessionId}_{timestamp}_{randomBytes}.{ext}'
    if ($tempFilename === '' || strpos($tempFilename, 'temp_extra_') !== 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid temp filename']);
        exit;
    }

    // Verify flash exists and user has permission to edit it
    $stmt = $pdo->prepare("SELECT id, created_by, state, is_archived FROM sf_flashes WHERE id = ?");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch();

    if (!$flash) {
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }

    if (!empty($flash['is_archived'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot edit archived reports']);
        exit;
    }

    // Check permissions - user must be able to edit the flash
    $currentUser = sf_current_user();
    $currentUserId = (int)($currentUser['id'] ?? 0);
    $isOwner = ($currentUserId > 0 && (int)$flash['created_by'] === $currentUserId);
    $permissionService = new FlashPermissionService();
$roleId = (int)($currentUser['role_id'] ?? 0);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);

$hasGeneralEditPermission = $permissionService->canEdit($currentUser, $flash);
$canAddExtraMedia = $hasGeneralEditPermission || $isOwner || $isAdmin || $isSafety;

if (!$canAddExtraMedia) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

    // Setup directories
    $tempDir = __DIR__ . '/../../uploads/temp/';
    $extraImagesDir = __DIR__ . '/../../uploads/extra_images/';
    
    if (!is_dir($extraImagesDir)) {
        @mkdir($extraImagesDir, 0755, true);
    }

    // Security: Use basename to prevent directory traversal
    $tempFilename = basename($tempFilename);
    $tempPath = $tempDir . $tempFilename;
    $tempThumbPath = $tempDir . 'thumb_' . $tempFilename;

    // Verify temp file exists
    if (!is_file($tempPath)) {
        echo json_encode(['ok' => false, 'error' => 'Temp file not found']);
        exit;
    }

    // Generate permanent filename with validated extension
    $ext = strtolower(pathinfo($tempFilename, PATHINFO_EXTENSION) ?: 'jpg');
    
    // Validate extension against allowlist
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedExtensions, true)) {
        // Clean up temp file
        @unlink($tempPath);
        @unlink($tempThumbPath);
        echo json_encode(['ok' => false, 'error' => 'Invalid file extension. Allowed: jpg, jpeg, png, gif, webp']);
        exit;
    }
    
    $permanentFilename = 'extra_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $permanentPath = $extraImagesDir . $permanentFilename;
    $permanentThumbPath = $extraImagesDir . 'thumb_' . $permanentFilename;

    // Move temp files to permanent location
    if (!rename($tempPath, $permanentPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to move file']);
        exit;
    }

    // Move thumbnail if it exists
    if (is_file($tempThumbPath)) {
        @rename($tempThumbPath, $permanentThumbPath);
    }

    // Insert into database
    $insertStmt = $pdo->prepare("
        INSERT INTO sf_flash_images (flash_id, filename, original_filename, created_at)
        VALUES (:flash_id, :filename, :original_filename, NOW())
    ");
    $insertStmt->execute([
        ':flash_id' => $flashId,
        ':filename' => $permanentFilename,
        ':original_filename' => $originalFilename
    ]);

    $insertedId = (int)$pdo->lastInsertId();

    // Build URLs for response
    $basePath = rtrim($config['base_url'] ?? '', '/');
    $imageUrl = $basePath . '/uploads/extra_images/' . $permanentFilename;
    $thumbUrl = file_exists($permanentThumbPath) 
        ? $basePath . '/uploads/extra_images/thumb_' . $permanentFilename 
        : $imageUrl;

    echo json_encode([
        'ok' => true,
        'id' => $insertedId,
        'flash_id' => $flashId,
        'filename' => $permanentFilename,
        'original_filename' => $originalFilename,
        'url' => $imageUrl,
        'thumb_url' => $thumbUrl,
        'caption' => ''
    ]);

} catch (Throwable $e) {
    error_log('add_extra_image.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}