<?php
/**
 * API: Delete feedback comment
 * - Users can delete their own comments
 * - Admins can delete any comment
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$userId = (int)$user['id'];
$isAdmin = (int)($user['role_id'] ?? 0) === 1;

try {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    
    if ($commentId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid comment ID']);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check comment exists
    $stmt = $db->prepare("SELECT id, user_id FROM sf_feedback_comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    
    if (!$comment) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comment not found']);
        exit;
    }
    
    // Check permission
    $isOwner = (int)$comment['user_id'] === $userId;
    if (!$isAdmin && !$isOwner) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Delete comment
    $stmt = $db->prepare("DELETE FROM sf_feedback_comments WHERE id = ?");
    $stmt->execute([$commentId]);
    
    echo json_encode(['ok' => true, 'message' => 'Comment deleted']);
    
} catch (Exception $e) {
    error_log('Feedback comment delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}