<?php
// app/api/push_test.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/PushNotificationService.php';

header('Content-Type: application/json; charset=utf-8');

$user = sf_current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Authentication required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

$result = PushNotificationService::sendToUser(
    (int)$user['id'],
    'SafetyFlash test ilmoitus',
    'Push-ilmoituksen testilähetys onnistui.',
    $baseUrl . '/index.php?page=list'
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);