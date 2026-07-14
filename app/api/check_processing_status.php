<?php
// app/api/check_processing_status.php
// Tarkistaa onko safetyflash vielä prosessoinnissa
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$flashId = isset($_GET['flash_id']) ? (int)$_GET['flash_id'] : 0;

if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid flash_id']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db']['host'] . 
        ';dbname=' . $config['db']['name'] .
        ';charset=' . $config['db']['charset'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare("SELECT id, is_processing, processing_status, preview_filename, state,
                                   TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_since_created 
                            FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch();

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['error' => 'Flash not found']);
        exit;
    }

    $isProcessing = (int)($flash['is_processing'] ?? 0) === 1;
    $status = $flash['processing_status'] ?? 'unknown';
    $secondsSinceCreated = (int)($flash['seconds_since_created'] ?? 0);

    // Laske progress processing_status perusteella
    $progressMap = [
        'pending' => 10,
        'uploading' => 25,
        'processing' => 50,
        'generating' => 75,
        'in_progress' => 60,
        'completed' => 100,
    ];
    $progress = $progressMap[$status] ?? 30;

    echo json_encode([
        'flash_id' => (int)$flash['id'],
        'is_processing' => $isProcessing,
        'processing_status' => $status,
        'progress' => $progress,
        'preview_filename' => $flash['preview_filename'] ?? null,
        'seconds_since_created' => $secondsSinceCreated
    ]);

} catch (Throwable $e) {
    error_log('check_processing_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}