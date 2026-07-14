<?php
// app/api/update_user_notification_preferences.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$currentUser = sf_current_user();
if (!$currentUser) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Admin may update another user's preferences via POST user_id
$isAdmin = (int)($currentUser['role_id'] ?? 0) === 1;
$targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (int)$currentUser['id'];

if ($targetUserId !== (int)$currentUser['id'] && !$isAdmin) {
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

if ($targetUserId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
    exit;
}

// Read notif_pref[] map from POST
$notifPref = isset($_POST['notif_pref']) && is_array($_POST['notif_pref'])
    ? $_POST['notif_pref']
    : [];

if (empty($notifPref)) {
    echo json_encode(['ok' => false, 'error' => 'No preferences provided']);
    exit;
}

// Mandatory categories may not be changed
$mandatoryCategories = ['system_welcome', 'system_password_reset', 'system_security'];

// Allowed optional categories
$allowedCategories = [
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

    $updated = 0;
    foreach ($notifPref as $category => $value) {
        $category = (string)$category;

        // Skip mandatory and unknown categories
        if (in_array($category, $mandatoryCategories, true)) {
            continue;
        }
        if (!in_array($category, $allowedCategories, true)) {
            sf_app_log("update_user_notification_preferences: Unknown category '{$category}' skipped", LOG_LEVEL_WARNING);
            continue;
        }

        $enabled = ($value == '1' || $value === true || $value === 1) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO sf_user_notification_preferences (user_id, category, enabled)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
        ");
        $stmt->execute([$targetUserId, $category, $enabled, $enabled]);
        $updated++;
    }

    sf_app_log("update_user_notification_preferences: Updated {$updated} preferences for user_id={$targetUserId}");

    echo json_encode(['ok' => true, 'updated' => $updated]);

} catch (Throwable $e) {
    sf_app_log('update_user_notification_preferences ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}