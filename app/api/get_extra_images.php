<?php
/**
 * Get Extra Images API
 * 
 * Returns all additional/extra images for a specific flash.
 * This is a wrapper around get_flash_images.php with parameter name normalization.
 * 
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getInstance();
    
    // Get id parameter (can be either 'id' or 'flash_id')
    $flashId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['flash_id']) ? (int)$_GET['flash_id'] : 0);
    
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid id parameter required']);
        exit;
    }
    
    // Verify flash exists
    $flashStmt = $pdo->prepare("SELECT id FROM sf_flashes WHERE id = ? LIMIT 1");
    $flashStmt->execute([$flashId]);
    if (!$flashStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    // Get images and videos for this flash
    $stmt = $pdo->prepare("
        SELECT 
            id,
            flash_id,
            filename,
            original_filename,
            COALESCE(media_type, 'image') AS media_type,
            caption,
            created_at,
            DATE_FORMAT(created_at, '%d.%m.%Y %H:%i') as created_at_formatted
        FROM sf_flash_images
        WHERE flash_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$flashId]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build URLs for each item
    $basePath = rtrim($config['base_url'] ?? '', '/');
    foreach ($images as &$image) {
        $mediaType = $image['media_type'] ?? 'image';
        $image['media_type'] = $mediaType;
        $image['url'] = $basePath . '/uploads/extra_images/' . $image['filename'];
        // Videos don't have thumbnails; images may have a thumb_ variant
        if ($mediaType === 'video') {
            $image['thumb_url'] = null;
        } else {
            $image['thumb_url'] = $basePath . '/uploads/extra_images/thumb_' . $image['filename'];
        }
    }
    unset($image); // Break reference
    
    echo json_encode([
        'ok' => true,
        'images' => $images
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('get_extra_images error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}