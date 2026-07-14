<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_role([1]);

$userId = (int)($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
    exit;
}

$mysqli = sf_db();

$stmt = $mysqli->prepare("
    SELECT role_category_id 
    FROM user_role_categories 
    WHERE user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$categoryIds = [];
while ($row = $result->fetch_assoc()) {
    $categoryIds[] = (int)$row['role_category_id'];
}

$stmt->close();

echo json_encode(['ok' => true, 'category_ids' => $categoryIds]);