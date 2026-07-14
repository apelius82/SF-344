<?php
// app/api/get_email_logs.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins and safety team can view email logs
if (!sf_is_admin_or_safety()) {
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$status = $_GET['status'] ?? '';
$recipient = $_GET['recipient'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build WHERE clause
$conditions = [];
$params = [];
$types = '';

if ($status !== '' && in_array($status, ['sent', 'failed', 'skipped'], true)) {
    $conditions[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
}

if ($recipient !== '') {
    $conditions[] = 'recipient_email LIKE ?';
    $params[] = '%' . $recipient . '%';
    $types .= 's';
}

if ($dateFrom !== '') {
    $conditions[] = 'sent_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}

if ($dateTo !== '') {
    $conditions[] = 'sent_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}

$whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count total
$countSql = "SELECT COUNT(*) as total FROM sf_email_logs $whereClause";
$countStmt = $mysqli->prepare($countSql);
if (count($params) > 0) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$total = (int)$countRes->fetch_assoc()['total'];
$countStmt->close();

// Fetch logs
$sql = "
    SELECT 
        id,
        flash_id,
        recipient_email,
        subject,
        status,
        skip_reason,
        error_message,
        sent_at
    FROM sf_email_logs
    $whereClause
    ORDER BY sent_at DESC
    LIMIT ? OFFSET ?
";

// Add pagination parameters
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        'id' => (int)$row['id'],
        'flash_id' => $row['flash_id'] ? (int)$row['flash_id'] : null,
        'recipient_email' => $row['recipient_email'],
        'subject' => $row['subject'],
        'status' => $row['status'],
        'skip_reason' => $row['skip_reason'],
        'error_message' => $row['error_message'],
        'sent_at' => $row['sent_at'],
    ];
}
$stmt->close();

echo json_encode([
    'ok' => true,
    'logs' => $logs,
    'total' => $total,
    'page' => $page,
    'perPage' => $perPage,
    'totalPages' => ceil($total / $perPage)
]);
