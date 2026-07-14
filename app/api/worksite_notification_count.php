<?php
/**
 * SafetyFlash – Worksite Notification Recipient Count API
 *
 * Palauttaa niiden henkilöiden lukumäärän, joille sähköposti-ilmoitus
 * lähetettäisiin, jos turvatiimi julkaisee SafetyFlashin valituille
 * infonäyttökohteille.
 *
 * POST /app/api/worksite_notification_count.php
 * Content-Type: application/json  TAI  application/x-www-form-urlencoded
 * Body: {
 *   "flash_id": 123,
 *   "display_key_ids": [1, 3, 5],
 *   "csrf_token": "..."
 * }
 *
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

// Vain POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Kirjautumistarkistus
$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Lue body (JSON tai form-encoded)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
} else {
    $body = $_POST;
}

// CSRF-validointi
$csrfToken = $body['csrf_token'] ?? '';
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Parametrit
$flashId = (int)($body['flash_id'] ?? 0);
if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
    exit;
}

$rawIds = $body['display_key_ids'] ?? [];
$displayKeyIds = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $displayKeyIds[] = $id;
        }
    }
}

// Laske vastaanottajat
try {
    $pdo        = Database::getInstance();
    $recipients = sf_get_worksite_notification_recipients($pdo, $flashId, $displayKeyIds);
    echo json_encode(['ok' => true, 'count' => count($recipients)]);
} catch (Throwable $e) {
    sf_app_log('worksite_notification_count ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}