<?php
/**
 * Save email/SMTP settings
 * Admin only endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/settings.php';

header('Content-Type: application/json');

// Admin only
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$allowedKeys = [
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
    'smtp_password',
    'smtp_from_email',
    'smtp_from_name'
];

$userId = (int)$user['id'];

try {
    $pdo = Database::getInstance();
    
    foreach ($input as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) {
            continue;
        }
        
        // Skip password if empty (don't overwrite existing password)
        if ($key === 'smtp_password' && trim((string)$value) === '') {
            continue;
        }
        
        // Convert value to string for storage
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        } else {
            $value = (string)$value;
        }
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both insert and update
        $stmt = $pdo->prepare("
            INSERT INTO sf_settings (setting_key, setting_value, setting_type, updated_by, updated_at)
            VALUES (:key, :value, 'string', :user_id, NOW())
            ON DUPLICATE KEY UPDATE 
                setting_value = :value2, 
                updated_by = :user_id2, 
                updated_at = NOW()
        ");
        
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':value2' => $value,
            ':user_id' => $userId,
            ':user_id2' => $userId
        ]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save settings: ' . $e->getMessage()]);
}