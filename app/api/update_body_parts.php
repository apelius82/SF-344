<?php
/**
 * API Endpoint: Update Injured Body Parts
 *
 * Updates the injured body parts for any flash report type.
 * Requires user authentication and CSRF validation.
 * Uses body-part-specific permission rules so factual injury data can be corrected later too.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../services/FlashPermissionService.php';

// Initialize Database connection
global $config;
Database::setConfig($config['db'] ?? []);

// Require authentication
$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate CSRF token
if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$user['id'];
try {
    $flashId = (int)($_POST['flash_id'] ?? 0);
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid flash ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::getInstance();

    // Load the flash to verify it exists and is editable
    $stmt = $pdo->prepare("SELECT id, type, state, created_by, is_archived FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Archived flashes cannot be modified
    if (!empty($flash['is_archived'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot edit archived reports'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Permission via body-part-specific role/state hierarchy
    $permissionService = new FlashPermissionService();
    if (!$permissionService->canEditBodyParts($user, $flash)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Collect and validate injured_parts values
    $injuredParts = [];
    if (isset($_POST['injured_parts']) && is_array($_POST['injured_parts'])) {
        foreach ($_POST['injured_parts'] as $part) {
            if (!is_string($part)) {
                continue;
            }
            // Allow only safe svg_id characters: lowercase letters, digits, hyphens
            if (!preg_match('/^[a-z0-9-]+$/', $part) || strlen($part) > 50) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid body part value'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $injuredParts[] = $part;
        }
    }

    $pdo->beginTransaction();

    // Remove all previous selections for this incident
    $delStmt = $pdo->prepare("DELETE FROM incident_body_part WHERE incident_id = ?");
    $delStmt->execute([$flashId]);

    $savedParts = [];
    if (!empty($injuredParts)) {
        // Validate submitted svg_ids against the body_parts lookup table
        $placeholders = implode(',', array_fill(0, count($injuredParts), '?'));
        $bpStmt = $pdo->prepare(
            "SELECT id, svg_id FROM body_parts WHERE svg_id IN ({$placeholders})"
        );
        $bpStmt->execute($injuredParts);
        $bpRows = $bpStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($bpRows)) {
            $insertStmt = $pdo->prepare(
                "INSERT IGNORE INTO incident_body_part (incident_id, body_part_id) VALUES (?, ?)"
            );
            foreach ($bpRows as $bpRow) {
                $insertStmt->execute([$flashId, (int)$bpRow['id']]);
                $savedParts[] = $bpRow['svg_id'];
            }
        }
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'saved_parts' => $savedParts], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_body_parts: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    exit;
}