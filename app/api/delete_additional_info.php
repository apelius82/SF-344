<?php
/**
 * API Endpoint: Delete Additional Info Entry
 *
 * Deletes an entry from sf_flash_additional_info.
 * - Only the entry owner, admin, or safety team can delete
 * Requires user authentication and CSRF validation.
 *
 * POST params:
 *   id          (int, required) — entry ID to delete
 *   csrf_token  (string, required)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId  = (int)$user['id'];
$roleId  = (int)($user['role_id'] ?? 0);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);

try {
    $entryId = (int)($_POST['id'] ?? 0);

    if ($entryId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid entry ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id, user_id FROM sf_flash_additional_info WHERE id = ? LIMIT 1");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Entry not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isOwner = (int)$entry['user_id'] === $userId;

    if (!$isOwner && !$isAdmin && !$isSafety) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $del = $pdo->prepare("DELETE FROM sf_flash_additional_info WHERE id = ?");
    $del->execute([$entryId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('delete_additional_info.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    exit;
}