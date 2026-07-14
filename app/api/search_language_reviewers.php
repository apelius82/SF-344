<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

    $query = trim((string)($_GET['query'] ?? ''));
    $limit = min(max((int)($_GET['limit'] ?? 10), 1), 30);

    if (mb_strlen($query) < 2) {
        echo json_encode(['ok' => true, 'users' => []]);
        exit;
    }

    $search = '%' . $query . '%';

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.role_id,
            r.name AS role_name
        FROM sf_users u
        LEFT JOIN sf_roles r ON r.id = u.role_id
        WHERE u.is_active = 1
          AND (
              u.first_name LIKE ?
              OR u.last_name LIKE ?
              OR u.email LIKE ?
              OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
          )
        ORDER BY u.last_name, u.first_name
        LIMIT ?
    ");

    $stmt->execute([$search, $search, $search, $search, $limit]);

    $users = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'role_name' => (string)($row['role_name'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('search_language_reviewers error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}