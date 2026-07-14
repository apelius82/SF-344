<?php
/**
 * Get Flash Images API
 * 
 * Returns all additional images for a specific flash (for lazy loading).
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
    
    // Get flash_id parameter
    $flashId = isset($_GET['flash_id']) ? (int)$_GET['flash_id'] : 0;
    
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid flash_id parameter required']);
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
    
    // Get images for this flash
    $stmt = $pdo->prepare("
        SELECT 
            id,
            flash_id,
            filename,
            original_filename,
            caption,
            created_at,
            DATE_FORMAT(created_at, '%d.%m.%Y %H:%i') as created_at_formatted
        FROM sf_flash_images
        WHERE flash_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$flashId]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build URLs for each image
    $basePath = rtrim($config['base_url'] ?? '', '/');
    foreach ($images as &$image) {
        $image['url'] = $basePath . '/uploads/extra_images/' . $image['filename'];
        $image['thumb_url'] = $basePath . '/uploads/extra_images/thumb_' . $image['filename'];
    }
    unset($image); // Break reference
    
    echo json_encode([
        'ok' => true,
        'images' => $images
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('get_flash_images error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}