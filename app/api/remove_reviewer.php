<?php
declare(strict_types=1);

/**
 * Remove Reviewer API Endpoint
 * 
 * Removes a specific reviewer from a flash.
 * 
 * Parameters:
 * - flash_id: The ID of the flash (required)
 * - user_id: The ID of the user to remove as reviewer (required)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/ApprovalRouting.php';

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Get parameters
    $flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    
    if ($flashId <= 0 || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid flash_id and user_id required']);
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
    
    // Check if reviewer assignment exists
    $checkStmt = $pdo->prepare("SELECT id FROM flash_supervisors WHERE flash_id = ? AND user_id = ? LIMIT 1");
    $checkStmt->execute([$flashId, $userId]);
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Reviewer assignment not found']);
        exit;
    }
    
    // Remove reviewer using prepared statement for SQL injection prevention
    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare("DELETE FROM flash_supervisors WHERE flash_id = ? AND user_id = ?");
        $deleteStmt->execute([$flashId, $userId]);

        // Sync selected_approvers in sf_flashes so list.php visibility reflects the change
        $fsStmt = $pdo->prepare("SELECT user_id FROM flash_supervisors WHERE flash_id = ? ORDER BY user_id");
        $fsStmt->execute([$flashId]);
        $remainingApproverIds = $fsStmt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("UPDATE sf_flashes SET selected_approvers = :approvers WHERE id = :id")->execute([
            ':approvers' => empty($remainingApproverIds) ? null : json_encode(array_map('intval', $remainingApproverIds)),
            ':id' => $flashId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Reviewer removed successfully'
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('remove_reviewer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}