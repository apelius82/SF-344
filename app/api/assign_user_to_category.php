<?php
declare(strict_types=1);

/**
 * Assign User to Category API Endpoint
 *
 * Assigns a user to a role category.
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

$userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
$categoryId = isset($data['category_id']) ? (int) $data['category_id'] : 0;

if ($userId <= 0 || $categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'User ID and Category ID are required']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM sf_users WHERE id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }

    // Check if category exists
    $stmt = $pdo->prepare("SELECT id FROM role_categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Category not found']);
        exit;
    }

    // Check if assignment already exists
    $stmt = $pdo->prepare("
        SELECT user_id FROM user_role_categories
        WHERE user_id = ? AND role_category_id = ?
    ");
    $stmt->execute([$userId, $categoryId]);
    if ($stmt->fetch()) {
        // Already assigned, return success
        echo json_encode([
            'ok' => true,
            'message' => 'User already assigned to category'
        ]);
        exit;
    }

    // Create assignment
    $stmt = $pdo->prepare("
        INSERT INTO user_role_categories (user_id, role_category_id)
        VALUES (?, ?)
    ");
    $stmt->execute([$userId, $categoryId]);

    echo json_encode([
        'ok' => true,
        'message' => 'User assigned to category successfully'
    ]);
    exit;

} catch (Throwable $e) {
    error_log('assign_user_to_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}