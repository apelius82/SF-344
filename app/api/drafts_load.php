<?php
// app/api/drafts_load.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Get current user
$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$currentUser['id'];

try {
    $pdo = Database::getInstance();
    
    // Get all drafts for the user
    $stmt = $pdo->prepare("
        SELECT id, flash_type, form_data, created_at, updated_at 
        FROM sf_drafts 
        WHERE user_id = ?
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$userId]);
    $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'drafts' => $drafts
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('drafts_load.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load drafts'
    ], JSON_UNESCAPED_UNICODE);
}