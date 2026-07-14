<?php
/**
 * API Endpoint: Update Comment
 * 
 * Updates existing comment in safetyflash_logs table.
 * - Only comment owner or admin can update
 * Requires user authentication and CSRF validation.
 */

declare(strict_types=1);

// Comment label prefix used in safetyflash_logs.description
define('COMMENT_LABEL_PREFIX', 'log_comment_label: ');

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
    $message = trim($_POST['message'] ?? '');
    
    // Validate comment ID
    if ($commentId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid comment ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Validate message
    if ($message === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Comment message cannot be empty'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Limit message length
    $message = mb_substr($message, 0, 2000);
    
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
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit this comment'], JSON_UNESCAPED_UNICODE);
        
        // Audit log - access denied
        sf_audit_log(
            'permission_denied',
            'comment',
            $commentId,
            [
                'attempted_action' => 'comment_update',
                'reason' => 'User is not admin or owner'
            ],
            $userId,
            'warning'
        );
        
        exit;
    }
    
    // Update comment - store with comment label prefix
    $description = COMMENT_LABEL_PREFIX . $message;
    $updateSql = "UPDATE safetyflash_logs SET description = :description WHERE id = :id";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([
        ':id' => $commentId,
        ':description' => $description
    ]);
    
    // Audit log - successful update
    $updatedBy = $isAdmin ? 'admin' : 'owner';
    sf_audit_log(
        'comment_update',
        'comment',
        $commentId,
        [
            'flash_id' => $comment['flash_id'],
            'updated_by' => $updatedBy,
            'is_admin' => $isAdmin,
            'is_owner' => $isOwner,
            'message_preview' => mb_substr($message, 0, 100)
        ],
        $userId,
        'info'
    );
    
    echo json_encode([
        'ok' => true,
        'message' => 'Comment updated successfully',
        'comment' => [
            'id' => $commentId,
            'text' => $message
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Comment update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Comment update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}