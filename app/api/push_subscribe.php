<?php
// app/api/push_subscribe.php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid method',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Security token validation failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Authentication required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$endpoint = trim((string)($data['endpoint'] ?? ''));
$keys = $data['keys'] ?? [];

$p256dh = is_array($keys) ? trim((string)($keys['p256dh'] ?? '')) : '';
$auth = is_array($keys) ? trim((string)($keys['auth'] ?? '')) : '';

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid subscription',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    $endpointHash = hash('sha256', $endpoint);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

    $stmt = $pdo->prepare("
        INSERT INTO sf_push_subscriptions (
            user_id,
            endpoint,
            endpoint_hash,
            p256dh,
            auth,
            user_agent,
            is_active,
            last_error,
            created_at,
            updated_at
        ) VALUES (
            :user_id,
            :endpoint,
            :endpoint_hash,
            :p256dh,
            :auth,
            :user_agent,
            1,
            NULL,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            is_active = 1,
            last_error = NULL,
            updated_at = NOW()
    ");

    $stmt->execute([
        ':user_id' => (int)$user['id'],
        ':endpoint' => $endpoint,
        ':endpoint_hash' => $endpointHash,
        ':p256dh' => $p256dh,
        ':auth' => $auth,
        ':user_agent' => $userAgent,
    ]);

    echo json_encode([
        'ok' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('push_subscribe ERROR: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error',
    ], JSON_UNESCAPED_UNICODE);
}