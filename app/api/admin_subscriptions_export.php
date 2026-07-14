<?php
// app/api/admin_subscriptions_export.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

sf_require_login();

$currentUser = sf_current_user();
if (!$currentUser || !sf_is_admin_or_safety()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();

// Optional filters
$search   = isset($_GET['search'])   ? trim((string)$_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$roleId   = isset($_GET['role_id'])  ? (int)$_GET['role_id']           : 0;

// Fetch all active users with role info
$userSql = "SELECT u.id, u.first_name, u.last_name, u.email, r.name AS role_name, u.role_id
            FROM sf_users u
            LEFT JOIN sf_roles r ON r.id = u.role_id
            WHERE u.is_active = 1";
$userParams = [];

if ($roleId > 0) {
    $userSql    .= " AND u.role_id = ?";
    $userParams[] = $roleId;
}

$userSql .= " ORDER BY u.first_name, u.last_name";

$userStmt = $db->prepare($userSql);
$userStmt->execute($userParams);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Apply name/email search filter
if ($search !== '') {
    $searchLower = strtolower($search);
    $users = array_filter($users, static function (array $u) use ($searchLower): bool {
        $name = strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')));
        $email = strtolower($u['email'] ?? '');
        return str_contains($name, $searchLower) || str_contains($email, $searchLower);
    });
}

$userIds = array_column($users, 'id');

if (empty($userIds)) {
    // Output empty CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscriptions_export.csv"');
    echo "\xEF\xBB\xBF"; // BOM for Excel
    echo "user_id,first_name,last_name,email,role\n";
    exit;
}

// Fetch preferences for these users
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$prefSql = "SELECT user_id, category, enabled
            FROM sf_user_notification_preferences
            WHERE user_id IN ({$placeholders})";
$prefParams = array_values($userIds);

if ($category !== '') {
    $prefSql    .= " AND category = ?";
    $prefParams[] = $category;
}

$prefStmt = $db->prepare($prefSql);
$prefStmt->execute($prefParams);

$prefsByUser = [];
$allCategories = [];
foreach ($prefStmt->fetchAll(PDO::FETCH_ASSOC) as $pref) {
    $uid = (int)$pref['user_id'];
    $cat = $pref['category'];
    $prefsByUser[$uid][$cat] = (bool)$pref['enabled'];
    if (!in_array($cat, $allCategories, true)) {
        $allCategories[] = $cat;
    }
}
sort($allCategories);

// If category filter is active, only include users with that category enabled
if ($category !== '') {
    $users = array_filter($users, static function (array $u) use ($prefsByUser, $category): bool {
        $uid = (int)$u['id'];
        return !empty($prefsByUser[$uid][$category]);
    });
}

// Output CSV
$safeDateSuffix = preg_replace('/[^0-9_]/', '', date('Ymd_His'));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="subscriptions_export_' . $safeDateSuffix . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}

// BOM for Excel UTF-8 compatibility
fwrite($out, "\xEF\xBB\xBF");

// Header row
$headerRow = ['user_id', 'first_name', 'last_name', 'email', 'role'];
foreach ($allCategories as $cat) {
    $headerRow[] = $cat;
}
fputcsv($out, $headerRow);

// Data rows
foreach ($users as $user) {
    $uid = (int)$user['id'];
    $row = [
        $uid,
        $user['first_name'] ?? '',
        $user['last_name']  ?? '',
        $user['email']      ?? '',
        $user['role_name']  ?? '',
    ];

    foreach ($allCategories as $cat) {
        $pref = $prefsByUser[$uid][$cat] ?? null;
        if ($pref === null) {
            $row[] = '';
        } else {
            $row[] = $pref ? '1' : '0';
        }
    }

    fputcsv($out, $row);
}

fclose($out);
exit;