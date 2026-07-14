<?php
// app/api/check-status.php
declare(strict_types=1);

// Käytä samaa autentikointi-/sessio-logiikkaa kuin muissa API-endpointeissa
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

$flashId = (int)($_GET['flash_id'] ?? 0);
if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'progress' => 0,
        'message' => 'Invalid flash ID.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT processing_status FROM sf_flashes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $flashId]);
    $statusFromDb = $stmt->fetchColumn();

    if ($statusFromDb === false) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'progress' => 0,
            'message' => 'Flash not found.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $progress = 0;
    switch ($statusFromDb) {
        case 'pending':
            $progress = 10;
            break;
        case 'in_progress':
            $progress = 50;
            break;
        case 'completed':
            $progress = 100;
            break;
        case 'error':
        default:
            $progress = 0;
            break;
    }

    echo json_encode([
        'status' => $statusFromDb,
        'progress' => $progress,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('check-status.php ERROR: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'progress' => 0,
        'message' => 'Database error.',
    ], JSON_UNESCAPED_UNICODE);
}