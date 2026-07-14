<?php
/**
 * API Endpoint: Create Feedback
 * 
 * Creates a new feedback entry in the database.
 * Requires user authentication and CSRF validation.
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
if (!sf_current_user()) {
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

$user = sf_current_user();
$userId = (int)$user['id'];

try {
    // Get POST data
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'other');
    
    // Validate required fields
    if (empty($title)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Title is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($description)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Description is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Validate title length
    if (strlen($title) > 255) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Title must be 255 characters or less'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Validate category
    $validCategories = ['critical', 'visual', 'improvement', 'bug', 'other'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'other';
    }
    
    // Insert feedback into database
    $db = Database::getInstance();
    $sql = "INSERT INTO sf_feedback 
            (title, description, category, status, reported_by, created_at, updated_at) 
            VALUES (:title, :description, :category, 'new', :reported_by, NOW(), NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':title' => $title,
        ':description' => $description,
        ':category' => $category,
        ':reported_by' => $userId
    ]);
    
    $feedbackId = (int)$db->lastInsertId();
    
    // Save notification preferences if provided
    $notifyStatusChange = isset($_POST['notify_status_change']) ? 1 : 0;
    $notifyComment = isset($_POST['notify_comment']) ? 1 : 0;
    $prefStmt = $db->prepare("
        INSERT INTO sf_user_notification_preferences (user_id, category, enabled)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
    ");
    $prefStmt->execute([$userId, 'feedback_status_change', $notifyStatusChange, $notifyStatusChange]);
    $prefStmt->execute([$userId, 'feedback_comment', $notifyComment, $notifyComment]);
    
    echo json_encode([
        'ok' => true,
        'feedback_id' => $feedbackId,
        'message' => 'Feedback created successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Feedback creation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Feedback creation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}