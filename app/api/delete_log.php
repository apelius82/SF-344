<?php
/**
 * API Endpoint: Delete Log Entry
 * 
 * Deletes a log entry from safetyflash_logs table.
 * - Only admins (role_id = 1) can delete log entries
 * Requires user authentication and CSRF validation.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

// Initialize Database
global $config;
Database::setConfig($config['db'] ?? []);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Require login
$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Check admin permission
$isAdmin = ((int)($user['role_id'] ?? 0)) === 1;

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied'], JSON_UNESCAPED_UNICODE);
    
    // Audit log - access denied
    sf_audit_log(
        'permission_denied',
        'log_entry',
        0,
        [
            'attempted_action' => 'log_delete',
            'reason' => 'User is not admin'
        ],
        (int)$user['id'],
        'warning'
    );
    
    exit;
}

// Get input from JSON body
$input = json_decode(file_get_contents('php://input'), true);
$logId = (int)($input['log_id'] ?? 0);
$csrfToken = $input['csrf_token'] ?? '';

// Validate CSRF
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid log ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get log entry info before deletion (for audit)
    $stmt = $db->prepare("SELECT flash_id, event_type, description FROM safetyflash_logs WHERE id = ?");
    $stmt->execute([$logId]);
    $logEntry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$logEntry) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Log entry not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Delete the log entry
    $stmt = $db->prepare("DELETE FROM safetyflash_logs WHERE id = ?");
    $stmt->execute([$logId]);
    
    // Audit log - successful deletion
    sf_audit_log(
        'log_delete',
        'log_entry',
        $logId,
        [
            'flash_id' => $logEntry['flash_id'],
            'event_type' => $logEntry['event_type'],
            'description_preview' => mb_substr($logEntry['description'] ?? '', 0, 100)
        ],
        (int)$user['id'],
        'info'
    );
    
    echo json_encode(['ok' => true, 'message' => 'Log entry deleted successfully'], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('delete_log.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('delete_log.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}