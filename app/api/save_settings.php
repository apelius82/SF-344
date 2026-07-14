<?php
/**
 * Save system settings API
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

// CSRF protection
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Security token validation failed']);
    exit;
}

// Admin only
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$allowedKeys = ['editing_indicator_enabled', 'editing_indicator_interval', 'soft_lock_timeout'];
$userId = (int)$user['id'];

try {
    $pdo = Database::getInstance();
    
    // Read old values before updating
    $oldValues = [];
    $savedValues = [];
    foreach ($allowedKeys as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $oldValues[$key] = $stmt->fetchColumn();
    }
    
    foreach ($input as $key => $value) {
        if (in_array($key, $allowedKeys, true)) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } else {
                $value = (string)$value;
            }
            
            $stmt = $pdo->prepare("
                UPDATE sf_settings 
                SET setting_value = :value, updated_by = :user_id, updated_at = NOW()
                WHERE setting_key = :key
            ");
            
            $stmt->execute([
                ':key' => $key,
                ':value' => $value,
                ':user_id' => $userId
            ]);
            
            $savedValues[$key] = $value;
        }
    }
    
    if (!empty($savedValues)) {
        $changedOld = array_intersect_key($oldValues, $savedValues);
        sf_audit_log(
            'settings_updated',
            'settings',
            null,
            [
                'old_values' => $changedOld,
                'new_values' => $savedValues,
            ]
        );
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}