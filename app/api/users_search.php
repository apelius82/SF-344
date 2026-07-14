<?php
declare(strict_types=1);

/**
 * Users Search API Endpoint
 *
 * Searches for active users by name or email. Used by the @mention autocomplete
 * in comment fields.
 *
 * Parameters (GET):
 * - query: Search query string (min 1 character)
 * - limit: Maximum number of results (default: 10, max: 20)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
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

    $query = isset($_GET['query']) ? trim($_GET['query']) : '';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 20) : 10;

    if (mb_strlen($query) < 1) {
        echo json_encode(['ok' => true, 'users' => []]);
        exit;
    }

    $searchPattern = '%' . addcslashes($query, '%_\\') . '%';

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email
        FROM sf_users u
        WHERE u.is_active = 1
          AND (
              u.first_name LIKE ?
              OR u.last_name LIKE ?
              OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
          )
        ORDER BY u.last_name, u.first_name
        LIMIT ?
    ");

    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(function (array $row): array {
        return [
            'id'   => (int)$row['id'],
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'users' => $results]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}