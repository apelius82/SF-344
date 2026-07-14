<?php
/**
 * Update Image Caption API
 * Updates caption for main images or extra images
 */
declare(strict_types=1);

// Skip automatic CSRF validation since we validate manually below
define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = Database::getInstance();
    
    // Validate request method
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }
    
    // Validate CSRF token - check both POST and header
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!sf_csrf_validate($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    
    $flashId = (int)($_POST['flash_id'] ?? 0);
    $imageType = trim($_POST['image_type'] ?? ''); // 'main1', 'main2', 'main3', or 'extra'
    $imageId = (int)($_POST['image_id'] ?? 0); // For extra images
    $caption = trim($_POST['caption'] ?? '');
    
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
        exit;
    }
    
    // Verify flash exists
    $stmt = $pdo->prepare("SELECT id FROM sf_flashes WHERE id = ?");
    $stmt->execute([$flashId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    $currentUser = sf_current_user();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    // Limit caption length
    $caption = mb_substr($caption, 0, 500);
    
    // Update caption based on image type
    if ($imageType === 'main1') {
        $stmt = $pdo->prepare("UPDATE sf_flashes SET image1_caption = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$caption, $flashId]);
    } elseif ($imageType === 'main2') {
        $stmt = $pdo->prepare("UPDATE sf_flashes SET image2_caption = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$caption, $flashId]);
    } elseif ($imageType === 'main3') {
        $stmt = $pdo->prepare("UPDATE sf_flashes SET image3_caption = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$caption, $flashId]);
    } elseif ($imageType === 'extra' && $imageId > 0) {
        $stmt = $pdo->prepare("UPDATE sf_flash_images SET caption = ? WHERE id = ? AND flash_id = ?");
        $stmt->execute([$caption, $imageId, $flashId]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid image_type']);
        exit;
    }
    
    echo json_encode(['ok' => true, 'caption' => $caption]);
    
} catch (Throwable $e) {
    error_log("update_image_caption.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}