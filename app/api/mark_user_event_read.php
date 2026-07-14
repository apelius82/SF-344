<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

$user = sf_current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;

try {
    $pdo = Database::getInstance();

    if ($action === 'mark_all') {
        $stmt = $pdo->prepare("
            UPDATE sf_user_events
            SET is_read = 1,
                read_at = NOW()
            WHERE user_id = ?
              AND is_read = 0
        ");
        $stmt->execute([(int)$user['id']]);

        echo json_encode([
            'ok' => true,
            'updated' => $stmt->rowCount(),
        ]);
        exit;
    }

    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE sf_user_events
        SET is_read = 1,
            read_at = NOW()
        WHERE id = ?
          AND user_id = ?
    ");
    $stmt->execute([$eventId, (int)$user['id']]);

    echo json_encode(['ok' => true]);
    exit;
} catch (Throwable $e) {
    error_log('mark_user_event_read error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}