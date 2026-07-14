<?php
declare(strict_types=1);

/**
 * Search Reviewers API Endpoint
 * 
 * Searches for active users to be assigned as reviewers.
 * 
 * Parameters:
 * - query: Search query string (searches name and email)
 * - limit: Maximum number of results (default: 10, max: 50)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/auth.php';

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Get parameters
    $query = isset($_GET['query']) ? trim($_GET['query']) : '';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
    
    if (empty($query)) {
        // Return empty results if no query
        echo json_encode([
            'ok' => true,
            'users' => []
        ]);
        exit;
    }
    
    // Search for active users with supervisor role category
    // Using LIKE with wildcards for flexible search
    $searchPattern = '%' . $query . '%';
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.role_id,
            r.name as role_name
        FROM sf_users u
        LEFT JOIN sf_roles r ON r.id = u.role_id
        INNER JOIN user_role_categories urc ON u.id = urc.user_id
        INNER JOIN role_categories rc ON urc.role_category_id = rc.id
        WHERE u.is_active = 1
        AND rc.type = 'supervisor'
        AND rc.is_active = 1
        AND (
            u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        )
        ORDER BY u.last_name, u.first_name
        LIMIT ?
    ");
    
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $limit]);
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results
    $results = array_map(function($user) {
        return [
            'id' => (int)$user['id'],
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'email' => $user['email'] ?? '',
            'role_name' => $user['role_name'] ?? ''
        ];
    }, $users);
    
    echo json_encode([
        'ok' => true,
        'users' => $results
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('search_reviewers error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}