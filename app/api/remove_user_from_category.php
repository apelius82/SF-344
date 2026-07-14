<?php
declare(strict_types=1);

/**
 * Remove User from Category API Endpoint
 * 
 * Removes a user from a role category.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

// Allow admin and safety team
if (!sf_is_admin_or_safety()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied - Admin or Safety Team required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON data']);
    exit;
}

$userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$categoryId = isset($data['category_id']) ? (int)$data['category_id'] : 0;

if ($userId <= 0 || $categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'User ID and Category ID are required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Delete assignment
    $stmt = $pdo->prepare("
        DELETE FROM user_role_categories 
        WHERE user_id = ? AND role_category_id = ?
    ");
    $stmt->execute([$userId, $categoryId]);
    
    echo json_encode([
        'ok' => true,
        'message' => 'User removed from category successfully'
    ]);
    
} catch (Throwable $e) {
    error_log('remove_user_from_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}