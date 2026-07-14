<?php

declare(strict_types=1);

/**
 * Delete Flash Image API
 *
 * Deletes an additional image and its thumbnail.
 * Requires same permissions as editing the flash.
 *
 * @package SafetyFlash
 * @subpackage API
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/image_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Get image_id parameter
    $imageId = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
    
    if ($imageId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid image_id parameter required']);
        exit;
    }
    
    // Get image details and verify it exists
    $stmt = $pdo->prepare("
        SELECT fi.id, fi.flash_id, fi.filename, f.created_by, f.state
        FROM sf_flash_images fi
        INNER JOIN sf_flashes f ON f.id = fi.flash_id
        WHERE fi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$imageId]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$image) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }
    
    // Check permissions: user must be able to edit the flash
    $currentUser = sf_current_user();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $userId = (int)$currentUser['id'];
    $userRole = (int)($currentUser['role_id'] ?? 0);
    $flashCreator = (int)$image['created_by'];
    $flashState = $image['state'];
    
    // Permission check: Admin (1), Safety Team (3), or original creator
    $canEdit = ($userRole === 1 || $userRole === 3 || $userId === $flashCreator);
    
    if (!$canEdit) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
    
    // Delete from database
    $deleteStmt = $pdo->prepare("DELETE FROM sf_flash_images WHERE id = ?");
    $deleteStmt->execute([$imageId]);
    
    // Delete files from filesystem
    $imagesDir = __DIR__ . '/../../uploads/extra_images/';
    $imagePath = $imagesDir . $image['filename'];
    sf_delete_image_with_thumbnail($imagePath);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Image deleted successfully'
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('delete_flash_image error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
