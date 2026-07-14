<?php
/**
 * SafetyFlash - Display Targets Save API
 *
 * Tallentaa tai päivittää flashin infonäyttö-targetit julkaisun jälkeen.
 * Sallittu vain admin (1), turvatiimi (3) ja viestintä (4) -rooleille.
 *
 * POST /app/api/display_targets_save.php
 * Content-Type: application/json
 * Body: {
 *   "flash_id": 123,
 *   "display_targets": [1, 3, 5],
 *   "display_ttl_days": 30,
 *   "display_duration_seconds": 20,
 *   "csrf_token": "..."
 * }
 *
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

// JSON-body endpoint — CSRF validated manually below
define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Role check: admin (1), turvatiimi (3), viestintä (4)
$user = sf_current_user();
$roleId = (int)($user['role_id'] ?? 0);
if (!in_array($roleId, [1, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden. Admin, safety team or communications access required.']);
    exit;
}

// Parse JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Validate CSRF
$csrfToken = (string)($body['csrf_token'] ?? '');
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate flash_id
$flashId = (int)($body['flash_id'] ?? 0);
if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
    exit;
}

// Validate display_ttl_days (0 = no limit). If value is omitted/null, keep existing expiry unchanged.
$hasTtlDays = array_key_exists('display_ttl_days', $body)
    && $body['display_ttl_days'] !== null
    && $body['display_ttl_days'] !== '';
$ttlDays = 0;
if ($hasTtlDays) {
    $ttlDays = (int)$body['display_ttl_days'];
    if ($ttlDays < 0) {
        $ttlDays = 0;
    }
}

// Validate display_duration_seconds (5–120)
$durationSeconds = max(5, min(120, (int)($body['display_duration_seconds'] ?? 30)));

// Validate display_targets (array of positive ints)
$rawTargets = $body['display_targets'] ?? [];
$displayTargets = [];
if (is_array($rawTargets)) {
    foreach ($rawTargets as $t) {
        $t = (int)$t;
        if ($t > 0) {
            $displayTargets[] = $t;
        }
    }
}

try {
    $pdo = Database::getInstance();

    // Verify flash exists and is published
    $stmtFlash = $pdo->prepare("SELECT id, state FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmtFlash->execute([$flashId]);
    $flash = $stmtFlash->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }

    if ($flash['state'] !== 'published') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Flash is not published']);
        exit;
    }

    $userId = (int)($user['id'] ?? 0);

    $pdo->beginTransaction();

    // Replace-all strategy: delete all current active targets, then insert the new selection.
    // This simplifies partial updates and ensures the stored state always matches the user's choices.
    $pdo->prepare("DELETE FROM sf_flash_display_targets WHERE flash_id = ? AND is_active = 1")->execute([$flashId]);

    // Insert new active targets — use UPSERT to handle existing inactive entries
    if (!empty($displayTargets)) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO sf_flash_display_targets
            (flash_id, display_key_id, is_active, selected_by, selected_at, activated_at)
            VALUES (?, ?, 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                is_active = 1,
                selected_by = VALUES(selected_by),
                selected_at = NOW(),
                activated_at = NOW()
        ");
        foreach ($displayTargets as $displayKeyId) {
            $stmtInsert->execute([$flashId, $displayKeyId, $userId]);
        }
    }

    // Update TTL and duration on flash — wrapped in try/catch so a missing column
    // (migration not yet run) does not block the main display-target save operation.
    try {
        if ($hasTtlDays) {
            if ($ttlDays > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlDays} days"));
                $pdo->prepare("UPDATE sf_flashes SET display_expires_at = ?, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
                    ->execute([$expiresAt, $flashId]);
            } else {
                $pdo->prepare("UPDATE sf_flashes SET display_expires_at = NULL, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
                    ->execute([$flashId]);
            }
        }

        $pdo->prepare("UPDATE sf_flashes SET display_duration_seconds = ? WHERE id = ?")
            ->execute([$durationSeconds, $flashId]);
    } catch (Throwable $colErr) {
        error_log('display_targets_save.php: column update skipped (migration pending?): ' . $colErr->getMessage());
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'message' => 'Tallennettu!',
        'count'   => count($displayTargets),
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('display_targets_save.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}