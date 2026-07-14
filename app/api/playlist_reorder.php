<?php
/**
 * SafetyFlash - Playlist Reorder API
 *
 * Tallentaa ajolistan järjestyksen sf_flash_display_targets.sort_order-sarakkeeseen.
 * Vaatii admin- tai turvatiimi-oikeudet (role_id 1 tai 3).
 *
 * @package SafetyFlash
 * @subpackage API
 * @created 2026-02-22
 *
 * POST /app/api/playlist_reorder.php
 *   Body (JSON): { "display_key_id": 1, "order": [{ "flash_id": 5, "sort_order": 0 }, ...] }
 *   Returns: { "ok": true }
 */

declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', 1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

$user = sf_current_user();
if (!$user || !in_array((int)($user['role_id'] ?? 0), [1, 3], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token from JSON body
$csrfToken = $body['csrf_token'] ?? '';
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$displayKeyId = (int)($body['display_key_id'] ?? 0);
$order = $body['order'] ?? [];

if ($displayKeyId <= 0 || !is_array($order)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing display_key_id or order']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        UPDATE sf_flash_display_targets
        SET sort_order = :sort_order
        WHERE flash_id = :flash_id AND display_key_id = :display_key_id
    ");

    $pdo->beginTransaction();
    foreach ($order as $item) {
        $flashId = (int)($item['flash_id'] ?? 0);
        $sortOrder = (int)($item['sort_order'] ?? 0);
        if ($flashId <= 0) {
            continue;
        }
        $stmt->execute([
            ':sort_order' => $sortOrder,
            ':flash_id' => $flashId,
            ':display_key_id' => $displayKeyId,
        ]);
    }
    $pdo->commit();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}