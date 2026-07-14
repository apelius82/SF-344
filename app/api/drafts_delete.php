<?php
// app/api/drafts_delete.php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Validate CSRF token
$token = $data['csrf_token'] ?? '';
if (!sf_csrf_validate($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token validation failed']);
    exit;
}

// Get current user
$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$currentUser['id'];
$draftId = isset($data['draft_id']) ? (int)$data['draft_id'] : 0;

if (!$draftId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Draft ID required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Delete draft, verify it belongs to user
    $stmt = $pdo->prepare("
        DELETE FROM sf_drafts 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$draftId, $userId]);
    
    $deletedRows = $stmt->rowCount();
    
    if ($deletedRows === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft not found']);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'message' => 'Draft deleted'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('drafts_delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to delete draft'
    ], JSON_UNESCAPED_UNICODE);
}