<?php
declare(strict_types=1);

/**
 * Save Role Category API Endpoint
 * 
 * Creates or updates a role category.
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
$name = isset($data['name']) ? trim($data['name']) : '';
$type = isset($data['type']) ? trim($data['type']) : '';
$worksite = isset($data['worksite']) ? trim($data['worksite']) : null;
$isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;

// Validate required fields
if (empty($name)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Name is required']);
    exit;
}

if (empty($type)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Type is required']);
    exit;
}

// Validate type
$validTypes = ['supervisor', 'approver', 'reviewer'];
if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid type. Must be: supervisor, approver, or reviewer']);
    exit;
}

// Empty worksite should be null
if ($worksite === '') {
    $worksite = null;
}

try {
    $pdo = Database::getInstance();
    
    if ($id > 0) {
        // Update existing category
        $stmt = $pdo->prepare("
            UPDATE role_categories 
            SET name = ?, type = ?, worksite = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $worksite, $isActive ? 1 : 0, $id]);
        
        echo json_encode([
            'ok' => true,
            'id' => $id,
            'message' => 'Category updated successfully'
        ]);
    } else {
        // Create new category
        $stmt = $pdo->prepare("
            INSERT INTO role_categories (name, type, worksite, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$name, $type, $worksite, $isActive ? 1 : 0]);
        
        $newId = (int)$pdo->lastInsertId();
        
        echo json_encode([
            'ok' => true,
            'id' => $newId,
            'message' => 'Category created successfully'
        ]);
    }
    
} catch (Throwable $e) {
    error_log('save_role_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}