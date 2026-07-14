<?php
// app/api/push_status.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

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

$publicKey = (string)(getenv('VAPID_PUBLIC_KEY') ?: '');

if ($publicKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'VAPID public key missing',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM sf_push_subscriptions
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([(int)$user['id']]);

    echo json_encode([
        'ok' => true,
        'public_key' => $publicKey,
        'has_active_subscription' => ((int)$stmt->fetchColumn() > 0),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('push_status ERROR: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error',
    ], JSON_UNESCAPED_UNICODE);
}