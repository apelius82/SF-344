<?php
/**
 * API Endpoint: Save Report Settings
 *
 * Saves editable per-flash settings:
 *   - original_type: The original Safetyflash category before any type change
 *
 * POST params:
 *   flash_id      (int, required)
 *   original_type (string: 'red'|'yellow'|'green'|'', required)
 *   csrf_token    (string, required)
 *
 * Auth: owner, admin (role 1), or safety team (role 3).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../services/FlashPermissionService.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user   = sf_current_user();
$userId = (int)$user['id'];

// Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF
if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Validate flash_id
    $flashId = (int)($_POST['flash_id'] ?? 0);
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid flash ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::getInstance();

    // Load flash
    $stmt = $pdo->prepare("SELECT id, created_by, is_archived, state, original_type, translation_group_id FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Authorization via centralized role/state hierarchy
    $permissionService = new FlashPermissionService();
    if (!$permissionService->canEdit($user, $flash)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Archived check
    if (!empty($flash['is_archived'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot edit archived reports'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Published check — original_type is locked only when the report is published AND a value has already been saved
    $isPublished    = ($flash['state'] ?? '') === 'published';
    $hasOriginalType = !empty($flash['original_type']);
    if ($isPublished && $hasOriginalType) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot change original_type after report is published'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validate original_type
    $allowedTypes = ['red', 'yellow', 'green', ''];
    $originalType = trim((string)($_POST['original_type'] ?? ''));
    if (!in_array($originalType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid original_type value'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $originalTypeValue = $originalType === '' ? null : $originalType;

    // Persist
    $upd = $pdo->prepare("UPDATE sf_flashes SET original_type = :original_type WHERE id = :id");
    $upd->execute([':original_type' => $originalTypeValue, ':id' => $flashId]);

    // Sync original_type to all language versions in the same translation group
    $groupId = $flash['translation_group_id'] !== null ? (int)$flash['translation_group_id'] : $flashId;
    $sync = $pdo->prepare(
        "UPDATE sf_flashes SET original_type = :original_type
         WHERE (id = :group_id OR translation_group_id = :group_id2)
           AND id != :flash_id"
    );
    $sync->execute([
        ':original_type' => $originalTypeValue,
        ':group_id'      => $groupId,
        ':group_id2'     => $groupId,
        ':flash_id'      => $flashId,
    ]);

    // Log the original_type change
    $oldOriginalType = $flash['original_type'] ?? null;
    if ($oldOriginalType !== $originalTypeValue) {
        require_once __DIR__ . '/../../assets/lib/sf_terms.php';
        $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
        $logDesc = sf_term('log_original_type_changed', $currentUiLang)
            . ': ' . ($oldOriginalType ?? '') . ' → ' . ($originalTypeValue ?? '');
        sf_log_event($flashId, 'original_type_changed', $logDesc);

        sf_audit_log(
            'flash_update',
            'flash',
            $flashId,
            [
                'field'     => 'original_type',
                'old_value' => $oldOriginalType,
                'new_value' => $originalTypeValue,
            ]
        );
    }

    echo json_encode(['ok' => true, 'original_type' => $originalTypeValue], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('save_report_settings.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    exit;
}