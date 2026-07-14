<?php
// app/api/save_system_notice.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Security token validation failed']);
    exit;
}

$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$allowedTypes = ['info', 'warning', 'danger', 'maintenance'];

$enabled = !empty($input['enabled']);
$type = trim((string)($input['type'] ?? 'info'));
$title = trim((string)($input['title'] ?? ''));
$message = trim((string)($input['message'] ?? ''));

if (!in_array($type, $allowedTypes, true)) {
    $type = 'info';
}

if (mb_strlen($title) > 160) {
    $title = mb_substr($title, 0, 160);
}

if (mb_strlen($message) > 1200) {
    $message = mb_substr($message, 0, 1200);
}

$userId = (int)$user['id'];

try {
    $oldValues = [
        'system_notice_enabled' => sf_get_setting('system_notice_enabled', false),
        'system_notice_type' => sf_get_setting('system_notice_type', 'info'),
        'system_notice_title' => sf_get_setting('system_notice_title', ''),
        'system_notice_message' => sf_get_setting('system_notice_message', ''),
    ];

    sf_set_setting('system_notice_enabled', $enabled, $userId);
    sf_set_setting('system_notice_type', $type, $userId);
    sf_set_setting('system_notice_title', $title, $userId);
    sf_set_setting('system_notice_message', $message, $userId);

    sf_audit_log(
        'system_notice_updated',
        'settings',
        null,
        [
            'old_values' => $oldValues,
            'new_values' => [
                'system_notice_enabled' => $enabled,
                'system_notice_type' => $type,
                'system_notice_title' => $title,
                'system_notice_message' => $message,
            ],
        ]
    );

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}