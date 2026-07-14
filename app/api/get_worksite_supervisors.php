<?php
declare(strict_types=1);

/**
 * Get Worksite Supervisors API Endpoint
 * 
 * Returns supervisors for a specific worksite or all supervisors from all worksites.
 * Used by form to auto-populate supervisors based on selected worksite.
 * 
 * Parameters:
 * - worksite: Specific worksite to filter (backwards compatibility)
 * - all: When set to "1", returns all supervisors from all worksites
 * - selected_worksite: When used with all=1, indicates which worksite is selected
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/ApprovalRouting.php';

try {
    $pdo = Database::getInstance();
    
    $showAll = isset($_GET['all']) && $_GET['all'] === '1';
    $selectedWorksite = $_GET['selected_worksite'] ?? $_GET['worksite'] ?? '';
    
    if ($showAll) {
        // Get ALL supervisors from ALL worksites using service method
        $supervisors = ApprovalRouting::getAllSupervisors($pdo);
    } else {
        // Get supervisors for specific worksite only (backwards compatibility)
        $worksite = $_GET['worksite'] ?? '';
        
        if (empty($worksite)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Worksite parameter required']);
            exit;
        }
        
        $supervisors = ApprovalRouting::getWorksiteSupervisors($pdo, $worksite);
        $selectedWorksite = $worksite;
    }
    
    echo json_encode([
        'ok' => true,
        'supervisors' => $supervisors,
        'selected_worksite' => $selectedWorksite
    ]);
    
} catch (Throwable $e) {
    error_log('get_worksite_supervisors error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}