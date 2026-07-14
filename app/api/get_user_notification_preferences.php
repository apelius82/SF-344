<?php
// app/api/get_user_notification_preferences.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$currentUser = sf_current_user();
if (!$currentUser) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Admin may request another user's preferences via ?user_id=
$isAdmin = (int)($currentUser['role_id'] ?? 0) === 1;
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$currentUser['id'];

if ($targetUserId !== (int)$currentUser['id'] && !$isAdmin) {
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

if ($targetUserId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
    exit;
}

// All optional (non-mandatory) categories, grouped for the UI
$optionalCategories = [
    'sf_published_distribution',
    'sf_published_creator',
    'sf_published_participant',
    'sf_published_general',
    'sf_request_info',
    'sf_supervisor_approval',
    'sf_to_comms',
    'sf_worksite_notification',
    'comment_on_own_flash',
    'comment_reply',
    'comment_mention',
    'comment_subscribed',
    'comment_comms_to_safety',
    'product_updates',
    'service_announcements',
    'feedback_status_change',
    'feedback_comment',
];

try {
    $pdo = Database::getInstance();

    // Fetch existing preferences
    $placeholders = implode(',', array_fill(0, count($optionalCategories), '?'));
    $stmt = $pdo->prepare("
        SELECT category, enabled
        FROM sf_user_notification_preferences
        WHERE user_id = ? AND category IN ($placeholders)
    ");
    $stmt->execute(array_merge([$targetUserId], $optionalCategories));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build map of category → enabled
    $prefsMap = [];
    foreach ($rows as $row) {
        $prefsMap[$row['category']] = (bool)$row['enabled'];
    }

    // Fill defaults (true) for categories without a row
    $preferences = [];
    foreach ($optionalCategories as $cat) {
        $preferences[$cat] = $prefsMap[$cat] ?? true;
    }

    echo json_encode(['ok' => true, 'preferences' => $preferences]);

} catch (Throwable $e) {
    sf_app_log('get_user_notification_preferences ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}