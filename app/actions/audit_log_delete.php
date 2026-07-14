<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

sf_require_role([1]);

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $baseUrl . '/index.php?page=settings&tab=logs&sub=app');
    exit;
}

$redirect = (string)($_POST['redirect'] ?? '/index.php?page=settings&tab=logs&sub=app');

if ($redirect === '' || str_contains($redirect, '://') || !str_starts_with($redirect, '/')) {
    $redirect = '/index.php?page=settings&tab=logs&sub=app';
}

$logIds = [];

if (isset($_POST['log_ids']) && is_array($_POST['log_ids'])) {
    foreach ($_POST['log_ids'] as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $logIds[] = $id;
        }
    }
} else {
    $singleId = (int)($_POST['log_id'] ?? 0);
    if ($singleId > 0) {
        $logIds[] = $singleId;
    }
}

$logIds = array_values(array_unique($logIds));

if (!empty($logIds)) {
    $mysqli = sf_db();

    $placeholders = implode(',', array_fill(0, count($logIds), '?'));
    $types = str_repeat('i', count($logIds));

    $sql = "DELETE FROM sf_audit_log WHERE id IN ($placeholders)";
    $deleteStmt = $mysqli->prepare($sql);

    if ($deleteStmt) {
        $deleteStmt->bind_param($types, ...$logIds);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
}

header('Location: ' . $baseUrl . $redirect);
exit;