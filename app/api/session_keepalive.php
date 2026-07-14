<?php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

function sf_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sf_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = sf_current_user();
if (!$user) {
    sf_json(['ok' => false, 'error' => 'Authentication required'], 401);
}

$isUserActivity = ($_SERVER['HTTP_X_SF_USER_ACTIVITY'] ?? '') === '1';

if (!$isUserActivity) {
    sf_json([
        'ok' => false,
        'error' => 'User activity header missing',
    ], 400);
}

$now = time();

$_SESSION['sf_last_activity'] = $now;

sf_json([
    'ok' => true,
    'message' => 'Session extended',
    'timestamp' => date('Y-m-d H:i:s', $now),
    'user_id' => (int)($user['id'] ?? 0)
]);