<?php
// app/api/analytics_event.php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/AnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = sf_current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    $data['user_id'] = (int)($user['id'] ?? 0);

    AnalyticsService::track($pdo, $data);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Analytics event failed: ' . $e->getMessage());

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}