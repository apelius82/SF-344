<?php
// app/api/profile_get.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

$user = sf_current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Ei kirjautunut']);
    exit;
}

$mysqli = sf_db();

// Hae roolin nimi
$roleStmt = $mysqli->prepare("SELECT name FROM sf_roles WHERE id = ?");
$roleStmt->bind_param('i', $user['role_id']);
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
$roleName = $roleResult->fetch_assoc()['name'] ?? '-';
$roleStmt->close();

// Hae työmaat
$worksitesRes = $mysqli->query("SELECT id, name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

// Hae ilmoituspreferenssit
$optionalCategories = [
    'sf_published_distribution', 'sf_published_creator', 'sf_published_participant', 'sf_published_general',
    'sf_request_info', 'sf_supervisor_approval', 'sf_to_comms',
    'sf_worksite_notification', 'comment_on_own_flash', 'comment_reply',
    'comment_mention', 'comment_subscribed', 'comment_comms_to_safety',
    'product_updates', 'service_announcements',
    'feedback_status_change', 'feedback_comment',
];

$notificationPreferences = [];

try {
    $pdo = Database::getInstance();
    $placeholders = implode(',', array_fill(0, count($optionalCategories), '?'));
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare("
        SELECT category, enabled
        FROM sf_user_notification_preferences
        WHERE user_id = ? AND category IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $optionalCategories));

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $prefsMap = [];

    foreach ($rows as $row) {
        $prefsMap[$row['category']] = (bool)$row['enabled'];
    }

    foreach ($optionalCategories as $cat) {
        $notificationPreferences[$cat] = $prefsMap[$cat] ?? true;
    }
} catch (Throwable $e) {
    foreach ($optionalCategories as $cat) {
        $notificationPreferences[$cat] = true;
    }
}

$pushPreferences = [];

try {
    $pdo = Database::getInstance();
    $placeholders = implode(',', array_fill(0, count($optionalCategories), '?'));
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare("
        SELECT category, enabled
        FROM sf_user_push_preferences
        WHERE user_id = ? AND category IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $optionalCategories));

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $prefsMap = [];

    foreach ($rows as $row) {
        $prefsMap[$row['category']] = (bool)$row['enabled'];
    }

    foreach ($optionalCategories as $cat) {
        $pushPreferences[$cat] = $prefsMap[$cat] ?? false;
    }
} catch (Throwable $e) {
    foreach ($optionalCategories as $cat) {
        $pushPreferences[$cat] = false;
    }
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'role_id' => $user['role_id'],
        'role_name' => $roleName,
        'home_worksite_id' => $user['home_worksite_id'],
        'email_notifications_enabled' => $user['email_notifications_enabled'] ?? 1,
    ],
    'worksites' => $worksites,
    'notification_preferences' => $notificationPreferences,
    'push_preferences' => $pushPreferences,
]);