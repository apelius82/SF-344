<?php
// app/api/analytics_push_click.php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../services/AnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$notificationId = trim((string)($data['notification_id'] ?? ''));

if ($notificationId === '' || strlen($notificationId) > 128) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid notification id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    AnalyticsService::track($pdo, [
        'user_id' => isset($data['user_id']) ? (int)$data['user_id'] : null,
        'session_id' => 'push_' . $notificationId,
        'event_type' => 'push_clicked',
        'page' => 'service_worker',
        'target_type' => isset($data['flash_id']) && (int)$data['flash_id'] > 0 ? 'flash' : 'push',
        'target_id' => isset($data['flash_id']) && (int)$data['flash_id'] > 0 ? (int)$data['flash_id'] : null,
        'device_type' => 'unknown',
        'platform' => 'service_worker',
        'browser' => 'service_worker',
        'is_pwa' => 1,
        'metadata' => [
            'notification_id' => $notificationId,
            'url' => (string)($data['url'] ?? ''),
            'title' => mb_substr((string)($data['title'] ?? ''), 0, 180, 'UTF-8'),
            'body' => mb_substr((string)($data['body'] ?? ''), 0, 240, 'UTF-8'),
            'source' => 'push_notification'
        ],
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Push analytics click failed: ' . $e->getMessage());
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}