<?php
/**
 * Returns all currently active editing sessions
 * Used by list page to show real-time editing indicators
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/settings.php';

header('Content-Type: application/json');

// Check if feature is enabled
if (!sf_get_setting('editing_indicator_enabled', false)) {
    echo json_encode(['enabled' => false, 'editors' => []]);
    exit;
}

$timeout = (int)sf_get_setting('soft_lock_timeout', 15);

try {
    $pdo = Database::getInstance();
    
    // Get active editing sessions
    // Also return translation_group_id so we can show indicator on parent flash
    $sql = "SELECT f.id as flash_id, 
                   f.translation_group_id,
                   u.first_name, 
                   u.last_name, 
                   f.editing_started_at,
                   f.lang
            FROM sf_flashes f
            JOIN sf_users u ON f.editing_user_id = u.id
            WHERE f.editing_user_id IS NOT NULL
              AND f.editing_started_at > DATE_SUB(NOW(), INTERVAL :timeout MINUTE)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':timeout' => $timeout]);
    $rows = $stmt->fetchAll();
    
    $editors = [];
    foreach ($rows as $row) {
        $editorName = trim($row['first_name'] . ' ' . $row['last_name']);
        $lang = strtoupper($row['lang'] ?? 'FI');
        
        // Add the actual flash being edited
        $editors[] = [
            'flash_id' => (int)$row['flash_id'],
            'editor_name' => $editorName,
            'lang' => $lang,
            'started_at' => $row['editing_started_at']
        ];
        
        // If editing a translation, also add parent flash ID
        // so indicator shows on the list (which shows parents)
        if (!empty($row['translation_group_id']) && (int)$row['translation_group_id'] !== (int)$row['flash_id']) {
            $editors[] = [
                'flash_id' => (int)$row['translation_group_id'],
                'editor_name' => $editorName . ' (' . $lang . ')',
                'lang' => $lang,
                'started_at' => $row['editing_started_at'],
                'is_translation' => true
            ];
        }
    }
    
    echo json_encode([
        'enabled' => true,
        'editors' => $editors,
        'interval' => (int)sf_get_setting('editing_indicator_interval', 30)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}