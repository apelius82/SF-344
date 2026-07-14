<?php
/**
 * API: Add comment to feedback
 * - Feedback author and admins can comment
 * - Sends email notification to feedback author when admin comments
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
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    if ($feedbackId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid feedback ID']);
        exit;
    }
    
    if ($comment === '' || mb_strlen($comment) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Comment is required (max 2000 chars)']);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check feedback exists and get author
    $stmt = $db->prepare("SELECT id, title, reported_by FROM sf_feedback WHERE id = ?");
    $stmt->execute([$feedbackId]);
    $feedback = $stmt->fetch();
    
    if (!$feedback) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Feedback not found']);
        exit;
    }
    
    // Check permission: admin or feedback author
    $isAuthor = (int)$feedback['reported_by'] === $userId;
    if (!$isAdmin && !$isAuthor) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Insert comment
    $stmt = $db->prepare("INSERT INTO sf_feedback_comments (feedback_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$feedbackId, $userId, $comment]);
    $commentId = (int)$db->lastInsertId();
    
    // Send email notification to feedback author if commenter is not the author
    if (!$isAuthor) {
        require_once __DIR__ . '/../../assets/services/email_services.php';
        sf_send_feedback_comment_notification($feedbackId, $feedback['title'], $comment, $user, (int)$feedback['reported_by']);
    }
    
    // Get comment with user info for response
    $stmt = $db->prepare("
        SELECT c.*, u.first_name, u.last_name 
        FROM sf_feedback_comments c 
        LEFT JOIN sf_users u ON c.user_id = u.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$commentId]);
    $newComment = $stmt->fetch();
    
    echo json_encode([
        'ok' => true,
        'comment' => $newComment,
        'message' => 'Comment added'
    ]);
    
} catch (Exception $e) {
    error_log('Feedback comment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}