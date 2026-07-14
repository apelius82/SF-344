<?php
/**
 * API Endpoint: Delete Feedback
 * 
 * Deletes a feedback entry from the database.
 * - Regular users can delete their own feedback
 * - Admins can delete any feedback
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
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    
    // Validate feedback ID
    if ($feedbackId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid feedback ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Check if feedback exists and get details
    $db = Database::getInstance();
    $checkSql = "SELECT id, title, reported_by FROM sf_feedback WHERE id = :id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':id' => $feedbackId]);
    $feedback = $checkStmt->fetch();
    
    if (!$feedback) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Feedback not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Check permissions: admin OR owner
    $isOwner = (int)$feedback['reported_by'] === $userId;
    
    if (!$isAdmin && !$isOwner) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to delete this feedback'], JSON_UNESCAPED_UNICODE);
        
        // Audit log - access denied
        sf_audit_log(
            'permission_denied',
            'feedback',
            $feedbackId,
            [
                'attempted_action' => 'feedback_delete',
                'reason' => 'User is not admin or owner'
            ],
            $userId,
            'warning'
        );
        
        exit;
    }
    
    // Delete feedback
    $deleteSql = "DELETE FROM sf_feedback WHERE id = :id";
    $deleteStmt = $db->prepare($deleteSql);
    $deleteStmt->execute([':id' => $feedbackId]);
    
    // Audit log - successful deletion
    $deletedBy = $isAdmin ? 'admin' : 'owner';
    sf_audit_log(
        'feedback_delete',
        'feedback',
        $feedbackId,
        [
            'title' => $feedback['title'],
            'deleted_by' => $deletedBy,
            'is_admin' => $isAdmin,
            'is_owner' => $isOwner
        ],
        $userId,
        'info'
    );
    
    echo json_encode([
        'ok' => true,
        'message' => 'Feedback deleted successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Feedback deletion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Feedback deletion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}