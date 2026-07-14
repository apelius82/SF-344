<?php
/**
 * API Endpoint: Update Feedback
 * 
 * Updates existing feedback entry (admin only).
 * Requires admin authentication and CSRF validation.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
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

// Require admin role (role_id = 1)
$roleId = (int)($user['role_id'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate CSRF token
if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$user['id'];

try {
    // Get POST data
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $adminNotes = trim($_POST['admin_notes'] ?? '');
    
    // Validate feedback ID
    if ($feedbackId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid feedback ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Validate status
    $validStatuses = ['new', 'in_progress', 'resolved', 'rejected'];
    if (!in_array($status, $validStatuses, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Check if feedback exists and get current status + reporter
    $db = Database::getInstance();
    $checkSql = "SELECT id, status, reported_by FROM sf_feedback WHERE id = :id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':id' => $feedbackId]);
    
    $existingFeedback = $checkStmt->fetch();
    if (!$existingFeedback) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Feedback not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $previousStatus = $existingFeedback['status'];
    $reportedBy = (int)$existingFeedback['reported_by'];
    
    // Prepare update query
    $updateFields = ['status = :status', 'admin_notes = :admin_notes', 'updated_at = NOW()'];
    $params = [
        ':id' => $feedbackId,
        ':status' => $status,
        ':admin_notes' => $adminNotes
    ];
    
    // If status is resolved, set resolved_by and resolved_at
    if ($status === 'resolved') {
        $updateFields[] = 'resolved_by = :resolved_by';
        $updateFields[] = 'resolved_at = NOW()';
        $params[':resolved_by'] = $userId;
    }
    
    // Update feedback
    $sql = "UPDATE sf_feedback SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    // Send status change notification if status changed and reporter is not the admin performing the update
    if ($status !== $previousStatus && $reportedBy > 0 && $reportedBy !== $userId) {
        require_once __DIR__ . '/../../assets/services/email_services.php';
        // Fetch feedback title for the email
        $titleStmt = $db->prepare("SELECT title FROM sf_feedback WHERE id = ?");
        $titleStmt->execute([$feedbackId]);
        $titleRow = $titleStmt->fetch();
        $feedbackTitle = $titleRow ? ($titleRow['title'] ?? '') : '';
        sf_send_feedback_status_notification($feedbackId, $feedbackTitle, $previousStatus, $status, $adminNotes, $reportedBy);
    }
    
    echo json_encode([
        'ok' => true,
        'message' => 'Feedback updated successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Feedback update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Feedback update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}