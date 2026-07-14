<?php
/**
 * API Endpoint: Save Additional Info Text
 *
 * Saves or updates a free-text entry in sf_flash_additional_info for the given flash.
 * The table is created automatically (CREATE TABLE IF NOT EXISTS) on first use.
 *
 * POST params:
 *   flash_id    (int, required)
 *   content     (string, required, max 10 000 chars)
 *   csrf_token  (string, required)
 *   id          (int, optional) — when provided, updates the existing entry instead of inserting
 *
 * Auth: owner, admin (role 1), or safety team (role 3).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../services/FlashPermissionService.php';

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

$userId = (int)$user['id'];
$roleId = (int)($user['role_id'] ?? 0);

try {
    $flashId = (int)($_POST['flash_id'] ?? 0);
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid flash ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Content is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($content) > 10000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Content too long (max 10000 characters)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::getInstance();

    // Ensure table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_flash_additional_info (
            id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            flash_id   INT UNSIGNED     NOT NULL,
            user_id    INT UNSIGNED     NOT NULL,
            content    TEXT             NOT NULL,
            created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_flash_id (flash_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Load flash to verify it exists and check permissions
    $stmt = $pdo->prepare("SELECT id, created_by, state, is_archived FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($flash['is_archived'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot edit archived reports'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isOwner = ($userId > 0 && (int)$flash['created_by'] === $userId);
    $permissionService = new FlashPermissionService();
    $hasGeneralEditPermission = $permissionService->canEdit($user, $flash);
    $isOwnerPublished = ($isOwner && (($flash['state'] ?? '') === 'published'));
    if (!$hasGeneralEditPermission && !$isOwnerPublished) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $editId = (int)($_POST['id'] ?? 0);

    // Shared SELECT for fetching the entry after save/update
    $fetchSql = "
        SELECT ai.id, ai.user_id, ai.content, ai.created_at,
               u.first_name, u.last_name
        FROM sf_flash_additional_info ai
        LEFT JOIN sf_users u ON u.id = ai.user_id
        WHERE ai.id = ?
        LIMIT 1
    ";

    if ($editId > 0) {
        // UPDATE existing entry — verify ownership
        $ownerStmt = $pdo->prepare("SELECT id, user_id FROM sf_flash_additional_info WHERE id = ? AND flash_id = ? LIMIT 1");
        $ownerStmt->execute([$editId, $flashId]);
        $existing = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Entry not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $entryOwner = (int)$existing['user_id'];
        $isCommsStateAllowed = ($roleId === 4 && in_array((string)($flash['state'] ?? ''), ['to_comms', 'published'], true));
        if ($entryOwner !== $userId && !in_array($roleId, [1, 3], true) && !$isCommsStateAllowed) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $upd = $pdo->prepare("UPDATE sf_flash_additional_info SET content = ? WHERE id = ?");
        $upd->execute([$content, $editId]);

        $rowId = $editId;

    } else {
        // INSERT new entry
        $ins = $pdo->prepare("
            INSERT INTO sf_flash_additional_info (flash_id, user_id, content, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $ins->execute([$flashId, $userId, $content]);
        $rowId = (int)$pdo->lastInsertId();
    }

    $sel = $pdo->prepare($fetchSql);
    $sel->execute([$rowId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'entry' => $row], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('save_additional_info.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    exit;
}