<?php
// app/api/update_user_notifications.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

$userId = (int)($_POST['user_id'] ?? 0);
$enabled = isset($_POST['email_notifications_enabled']) ? (int)$_POST['email_notifications_enabled'] : 1;

// Normalize to 0 or 1
$enabled = $enabled ? 1 : 0;

// Current user
$currentUser = sf_current_user();
if (!$currentUser) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Users can update their own settings, or admins can update any user
$isAdmin = sf_is_admin();
$isSelf = ($currentUser['id'] === $userId);

if (!$isAdmin && !$isSelf) {
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
    exit;
}

// Update email notification setting
$stmt = $mysqli->prepare('UPDATE sf_users SET email_notifications_enabled = ? WHERE id = ?');
$stmt->bind_param('ii', $enabled, $userId);

if (!$stmt->execute()) {
    sf_app_log("update_user_notifications: Failed to update user $userId", LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$stmt->close();

sf_app_log("update_user_notifications: User $userId email notifications set to $enabled");

echo json_encode(['ok' => true]);
