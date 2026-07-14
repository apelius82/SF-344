<?php
declare(strict_types=1);

/**
 * Delete Role Category API Endpoint
 * 
 * Deletes a role category. This will also remove all user assignments due to CASCADE.
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

$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Category ID required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Check if category exists
    $stmt = $pdo->prepare("SELECT id FROM role_categories WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Category not found']);
        exit;
    }
    
    // Delete category (user assignments will be deleted automatically via CASCADE)
    $stmt = $pdo->prepare("DELETE FROM role_categories WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Category deleted successfully'
    ]);
    
} catch (Throwable $e) {
    error_log('delete_role_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}