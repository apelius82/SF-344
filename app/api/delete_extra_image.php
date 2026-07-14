<?php
/**
 * Delete Extra Image API
 * 
 * Deletes a specific extra image from a flash.
 * 
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
// Skip automatic CSRF validation since we validate manually on line 29
define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getInstance();
    
    // Require POST method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!sf_csrf_validate($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    
    // Get image id parameter
    $imageId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($imageId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid image id required']);
        exit;
    }
    
    // Get current user
    $currentUser = sf_current_user();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    // Get image details
    $stmt = $pdo->prepare("
        SELECT i.*, f.created_by 
        FROM sf_flash_images i
        JOIN sf_flashes f ON i.flash_id = f.id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$imageId]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$image) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Image not found']);
        exit;
    }
    
    // Check permissions: admin, safety team, or flash creator
    $userRoleId = (int)($currentUser['role_id'] ?? 0);
    $userId = (int)($currentUser['id'] ?? 0);
    $flashCreatorId = (int)($image['created_by'] ?? 0);
    
    $canDelete = ($userRoleId === 1 || $userRoleId === 3 || $userId === $flashCreatorId);
    
    if (!$canDelete) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    // Delete physical files
    $uploadDir = __DIR__ . '/../../uploads/extra_images/';
    $filename = $image['filename'];
    
    if ($filename && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        $mainFile = $uploadDir . $filename;
        $thumbFile = $uploadDir . 'thumb_' . $filename;
        
        if (file_exists($mainFile)) {
            @unlink($mainFile);
        }
        if (file_exists($thumbFile)) {
            @unlink($thumbFile);
        }
    }
    
    // Delete from database
    $deleteStmt = $pdo->prepare("DELETE FROM sf_flash_images WHERE id = ?");
    $deleteStmt->execute([$imageId]);
    
    echo json_encode(['ok' => true]);
    exit;
    
} catch (Throwable $e) {
    error_log('delete_extra_image error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}