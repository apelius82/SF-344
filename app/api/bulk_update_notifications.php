<?php
// app/api/bulk_update_notifications.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins can perform bulk actions
sf_require_role([1]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

$userIds = $_POST['user_ids'] ?? [];
$enabled = isset($_POST['email_notifications_enabled']) ? (int)$_POST['email_notifications_enabled'] : 1;

// Normalize to 0 or 1
$enabled = $enabled ? 1 : 0;

// Validate user IDs array
if (!is_array($userIds) || empty($userIds)) {
    echo json_encode(['ok' => false, 'error' => 'No users selected']);
    exit;
}

// Convert to integers and filter invalid values
$userIds = array_map('intval', $userIds);
$userIds = array_filter($userIds, function($id) {
    return $id > 0;
});

if (empty($userIds)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user IDs']);
    exit;
}

// Optional: category-specific bulk update
// If 'category' is provided, update that specific category in sf_user_notification_preferences.
// Otherwise fall back to legacy bulk toggle on sf_users.email_notifications_enabled.
$category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';

$allowedCategories = [
    'sf_published_distribution', 'sf_published_creator', 'sf_published_participant', 'sf_published_general',
    'sf_request_info', 'sf_supervisor_approval', 'sf_to_comms',
    'sf_worksite_notification', 'comment_on_own_flash', 'comment_reply',
    'comment_mention', 'comment_subscribed', 'comment_comms_to_safety',
    'product_updates', 'service_announcements',
];

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    // Category-specific update: upsert into sf_user_notification_preferences
    try {
        $pdo = Database::getInstance();
        $affected = 0;
        foreach ($userIds as $uid) {
            $stmt = $pdo->prepare("
                INSERT INTO sf_user_notification_preferences (user_id, category, enabled)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
            ");
            $stmt->execute([$uid, $category, $enabled, $enabled]);
            $affected++;
        }
        sf_app_log("bulk_update_notifications: Updated {$affected} users for category={$category}, enabled={$enabled}");
        echo json_encode(['ok' => true, 'affected' => $affected]);
    } catch (Throwable $e) {
        sf_app_log('bulk_update_notifications: PDO error: ' . $e->getMessage(), LOG_LEVEL_ERROR);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

// Legacy: update all optional categories + the legacy sf_users column
// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$sql = "UPDATE sf_users SET email_notifications_enabled = ? WHERE id IN ($placeholders)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    sf_app_log("bulk_update_notifications: Failed to prepare statement", LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

// Build bind_param types string: 'i' for enabled + 'i' for each user ID
$types = 'i' . str_repeat('i', count($userIds));
$params = array_merge([$enabled], array_values($userIds));

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    sf_app_log("bulk_update_notifications: Failed to execute update", LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

// Also update all optional categories in sf_user_notification_preferences
try {
    $pdo = Database::getInstance();
    $optionalCategories = $allowedCategories;
    foreach ($userIds as $uid) {
        foreach ($optionalCategories as $cat) {
            $stmt2 = $pdo->prepare("
                INSERT INTO sf_user_notification_preferences (user_id, category, enabled)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
            ");
            $stmt2->execute([$uid, $cat, $enabled, $enabled]);
        }
    }
} catch (Throwable $e) {
    sf_app_log('bulk_update_notifications: PDO category update error: ' . $e->getMessage(), LOG_LEVEL_WARNING);
    // Non-fatal
}

sf_app_log("bulk_update_notifications: Updated $affectedRows users, enabled=$enabled");

echo json_encode(['ok' => true, 'affected' => $affectedRows]);
