<?php
declare(strict_types=1);

/**
 * Get Flash Reviewers API Endpoint
 * 
 * Returns all reviewers assigned to a specific flash.
 * 
 * Parameters:
 * - flash_id: The ID of the flash to get reviewers for (required)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

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
    
    // Get reviewers for this flash
    $stmt = $pdo->prepare("
        SELECT 
            fs.id,
            fs.flash_id,
            fs.user_id,
            fs.assigned_at,
            u.first_name,
            u.last_name,
            u.email,
            DATE_FORMAT(fs.assigned_at, '%d.%m.%Y %H:%i') as assigned_at_formatted
        FROM flash_supervisors fs
        INNER JOIN sf_users u ON u.id = fs.user_id
        WHERE fs.flash_id = ?
        ORDER BY fs.assigned_at DESC
    ");
    $stmt->execute([$flashId]);
    $reviewers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'reviewers' => $reviewers
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('get_flash_reviewers error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}