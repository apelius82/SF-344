<?php
// app/api/push_unsubscribe.php
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
$endpoint = is_array($data) ? trim((string)($data['endpoint'] ?? '')) : '';

try {
    $pdo = Database::getInstance();

    if ($endpoint !== '') {
        $stmt = $pdo->prepare("
            UPDATE sf_push_subscriptions
            SET is_active = 0, updated_at = NOW()
            WHERE user_id = ? AND endpoint_hash = ?
        ");
        $stmt->execute([
            (int)$user['id'],
            hash('sha256', $endpoint),
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE sf_push_subscriptions
            SET is_active = 0, updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([
            (int)$user['id'],
        ]);
    }

    echo json_encode([
        'ok' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('push_unsubscribe ERROR: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error',
    ], JSON_UNESCAPED_UNICODE);
}