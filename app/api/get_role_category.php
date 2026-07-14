<?php
declare(strict_types=1);

/**
 * Get Role Category API Endpoint
 * 
 * Returns details of a specific role category including assigned users.
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

$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Category ID required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Get category details
    $stmt = $pdo->prepare("
        SELECT id, name, type, worksite, is_active, created_at, updated_at
        FROM role_categories
        WHERE id = ?
    ");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Category not found']);
        exit;
    }
    
    // Get assigned users
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email
        FROM sf_users u
        INNER JOIN user_role_categories urc ON u.id = urc.user_id
        WHERE urc.role_category_id = ?
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$categoryId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $category['users'] = $users;
    
    echo json_encode([
        'ok' => true,
        'category' => $category
    ]);
    
} catch (Throwable $e) {
    error_log('get_role_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}