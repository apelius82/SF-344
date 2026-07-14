<?php
declare(strict_types=1);

/**
 * ApprovalRouting Service
 * 
 * Handles supervisor and approval routing logic for SafetyFlash workflow.
 * Manages fetching supervisors based on worksite and storing/retrieving selected approvers.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */
class ApprovalRouting {
    
    /**
     * Fetch worksite supervisors automatically
     * 
     * @param PDO $pdo Database connection
     * @param string $worksite Worksite name (e.g., "Siilinjärvi")
     * @return array Supervisor information
     */
    public static function getWorksiteSupervisors(PDO $pdo, string $worksite): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                rc.name as category_name,
                rc.worksite
            FROM sf_users u
            INNER JOIN user_role_categories urc ON u.id = urc.user_id
            INNER JOIN role_categories rc ON urc.role_category_id = rc.id
            WHERE rc.type = 'supervisor'
            AND rc.is_active = 1
            AND u.is_active = 1
            AND (rc.worksite = :worksite OR rc.worksite IS NULL)
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->execute([':worksite' => $worksite]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Fetch all supervisors grouped by worksite (for dropdown)
     * 
     * @param PDO $pdo Database connection
     * @return array Supervisors grouped by worksite
     */
    public static function getAllSupervisorsByWorksite(PDO $pdo): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                rc.worksite,
                rc.name as category_name
            FROM sf_users u
            INNER JOIN user_role_categories urc ON u.id = urc.user_id
            INNER JOIN role_categories rc ON urc.role_category_id = rc.id
            WHERE rc.type = 'supervisor'
            AND rc.is_active = 1
            AND u.is_active = 1
            ORDER BY rc.worksite, u.last_name, u.first_name
        ");
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by worksite
        $grouped = [];
        foreach ($results as $row) {
            $worksite = $row['worksite'] ?? null;
            // Use NULL for "all worksites" to be handled by the view layer
            if ($worksite === null) {
                $worksite = '__all__'; // Special key for global supervisors
            }
            $grouped[$worksite][] = $row;
        }
        
        return $grouped;
    }
    
    /**
     * Fetch all supervisors from all worksites (flat list)
     * 
     * @param PDO $pdo Database connection
     * @return array All supervisors with worksite information
     */
    public static function getAllSupervisors(PDO $pdo): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                rc.worksite,
                rc.name as category_name
            FROM sf_users u
            INNER JOIN user_role_categories urc ON u.id = urc.user_id
            INNER JOIN role_categories rc ON urc.role_category_id = rc.id
            WHERE rc.type = 'supervisor'
            AND rc.is_active = 1
            AND u.is_active = 1
            ORDER BY rc.worksite, u.last_name, u.first_name
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Save selected approvers to flash
     * 
     * @param PDO $pdo Database connection
     * @param int $flashId Flash ID
     * @param array $approverIds Array of approver user IDs
     * @return void
     */
    public static function saveSelectedApprovers(PDO $pdo, int $flashId, array $approverIds): void {
        $stmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET selected_approvers = :approvers 
            WHERE id = :id
        ");
        $stmt->execute([
            ':approvers' => json_encode(array_map('intval', $approverIds)),
            ':id' => $flashId
        ]);
    }
    
    /**
     * Get selected approvers for a flash
     * 
     * @param PDO $pdo Database connection
     * @param int $flashId Flash ID
     * @return array Array of approver details
     */
    public static function getSelectedApprovers(PDO $pdo, int $flashId): array {
        $stmt = $pdo->prepare("
            SELECT selected_approvers FROM sf_flashes WHERE id = :id
        ");
        $stmt->execute([':id' => $flashId]);
        $result = $stmt->fetchColumn();
        
        if (empty($result)) {
            return [];
        }
        
        $approverIds = json_decode($result, true);
        if (!is_array($approverIds) || empty($approverIds)) {
            return [];
        }
        
        // Fetch approver details
        $placeholders = implode(',', array_fill(0, count($approverIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email 
            FROM sf_users 
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($approverIds);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if user is a supervisor
     * 
     * @param PDO $pdo Database connection
     * @param int $userId User ID
     * @return bool True if user is supervisor
     */
    public static function isUserSupervisor(PDO $pdo, int $userId): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_role_categories urc
            INNER JOIN role_categories rc ON urc.role_category_id = rc.id
            WHERE urc.user_id = :user_id 
            AND rc.type = 'supervisor'
            AND rc.is_active = 1
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Check if user is in selected approvers for a flash
     * 
     * @param PDO $pdo Database connection
     * @param int $flashId Flash ID
     * @param int $userId User ID
     * @return bool True if user is selected approver
     */
    public static function isUserSelectedApprover(PDO $pdo, int $flashId, int $userId): bool {
        $approvers = self::getSelectedApprovers($pdo, $flashId);
        foreach ($approvers as $approver) {
            if ((int)$approver['id'] === $userId) {
                return true;
            }
        }
        return false;
    }
}