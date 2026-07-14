<?php
/**
 * System settings helper functions
 */

declare(strict_types=1);

/**
 * Get a system setting value
 * @param string $key Setting key
 * @param mixed $default Default value if not found
 * @return mixed Setting value
 */
function sf_get_setting(string $key, $default = null) {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM sf_settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        
        if (!$row) {
            return $default;
        }
        
        $value = $row['setting_value'];
        
        // Type conversion
        switch ($row['setting_type']) {
            case 'boolean':
                $value = ($value === 'true' || $value === '1');
                break;
            case 'number':
                // Preserve integer type if no decimal point
                $value = is_numeric($value) ? (strpos($value, '.') === false ? (int)$value : (float)$value) : $default;
                break;
            case 'json':
                $value = json_decode($value, true) ?? $default;
                break;
        }
        
        $cache[$key] = $value;
        return $value;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set a system setting value
 * @param string $key Setting key
 * @param mixed $value Setting value
 * @param int|null $userId User ID who made the change
 * @return bool Success
 */
function sf_set_setting(string $key, $value, ?int $userId = null): bool {
    try {
        $pdo = Database::getInstance();
        
        // Convert value to string for storage
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        } else {
            $value = (string)$value;
        }
        
        $stmt = $pdo->prepare("
            UPDATE sf_settings 
            SET setting_value = :value, updated_by = :user_id, updated_at = NOW()
            WHERE setting_key = :key
        ");
        
        return $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':user_id' => $userId
        ]);
    } catch (Exception $e) {
        return false;
    }
}