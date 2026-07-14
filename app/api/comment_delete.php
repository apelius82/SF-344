<?php
/**
 * API Endpoint: Delete Comment
 * 
 * Deletes a comment from safetyflash_logs table.
 * - Only comment owner or admin can delete
 * Requires user authentication and CSRF validation.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

// Initialize Database
global $config;
Database::setConfig($config['db'] ?? []);

// Require login
$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate CSRF token
if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$user['id'];
$isAdmin = (int)($user['role_id'] ?? 0) === 1;

try {
    // Get POST data
    $commentId = (int)($_POST['comment_id'] ?? 0);
    
    // Validate comment ID
    if ($commentId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid comment ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Check if comment exists and get details
    $db = Database::getInstance();
    $checkSql = "SELECT id, user_id, event_type, description, flash_id FROM safetyflash_logs WHERE id = :id AND event_type = 'comment_added'";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':id' => $commentId]);
    $comment = $checkStmt->fetch();
    
    if (!$comment) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comment not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Check permissions: admin OR owner
    $isOwner = (int)$comment['user_id'] === $userId;
    
    if (!$isAdmin && !$isOwner) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to delete this comment'], JSON_UNESCAPED_UNICODE);
        
        // Audit log - access denied
        sf_audit_log(
            'permission_denied',
            'comment',
            $commentId,
            [
                'attempted_action' => 'comment_delete',
                'reason' => 'User is not admin or owner'
            ],
            $userId,
            'warning'
        );
        
        exit;
    }
    
    // Delete comment
    $deleteSql = "DELETE FROM safetyflash_logs WHERE id = :id";
    $deleteStmt = $db->prepare($deleteSql);
    $deleteStmt->execute([':id' => $commentId]);
    
    // Audit log - successful deletion
    $deletedBy = $isAdmin ? 'admin' : 'owner';
    sf_audit_log(
        'comment_delete',
        'comment',
        $commentId,
        [
            'flash_id' => $comment['flash_id'],
            'deleted_by' => $deletedBy,
            'is_admin' => $isAdmin,
            'is_owner' => $isOwner,
            'description_preview' => mb_substr($comment['description'], 0, 100)
        ],
        $userId,
        'info'
    );
    
    echo json_encode([
        'ok' => true,
        'message' => 'Comment deleted successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Comment deletion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Comment deletion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}