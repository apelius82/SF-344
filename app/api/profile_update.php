<?php
// app/api/profile_update.php
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

$user = sf_current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Ei kirjautunut']);
    exit;
}

$mysqli = sf_db();

$id = (int)$user['id'];

// Handle language change
if (isset($_POST['ui_lang'])) {
    $uiLang = trim($_POST['ui_lang']);
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    
    if (in_array($uiLang, $validLangs, true)) {
        $stmt = $mysqli->prepare('UPDATE sf_users SET ui_lang = ? WHERE id = ?');
        $stmt->bind_param('si', $uiLang, $id);
        
        if ($stmt->execute()) {
            // Update session
            $_SESSION['ui_lang'] = $uiLang;
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Kielen päivitys epäonnistui']);
        }
        
        $stmt->close();
        exit;
    }
}

// Initialize arrays for dynamic query building
$updateFields = [];
$updateValues = [];
$updateTypes = '';

// Handle personal info fields (Basics tab)
$hasPersonalInfo = isset($_POST['first_name']) || isset($_POST['last_name']) || isset($_POST['email']);

$first = '';
$last = '';
$email = '';

if ($hasPersonalInfo) {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validate personal info if any field is sent
    if (isset($_POST['first_name']) && $first === '') {
        echo json_encode(['ok' => false, 'error' => 'Etunimi on pakollinen']);
        exit;
    }
    if (isset($_POST['last_name']) && $last === '') {
        echo json_encode(['ok' => false, 'error' => 'Sukunimi on pakollinen']);
        exit;
    }
    if (isset($_POST['email']) && $email === '') {
        echo json_encode(['ok' => false, 'error' => 'Sähköposti on pakollinen']);
        exit;
    }
    
    // Check if email is in use by another user
    if (isset($_POST['email'])) {
        $stmt = $mysqli->prepare('SELECT id FROM sf_users WHERE email = ? AND id != ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('si', $email, $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            echo json_encode(['ok' => false, 'error' => 'Tällä sähköpostilla on jo toinen käyttäjä']);
            $stmt->close();
            exit;
        }
        $stmt->close();
    }
    
    // Add personal info fields to update
    if (isset($_POST['first_name'])) {
        $updateFields[] = 'first_name = ?';
        $updateValues[] = $first;
        $updateTypes .= 's';
    }
    if (isset($_POST['last_name'])) {
        $updateFields[] = 'last_name = ?';
        $updateValues[] = $last;
        $updateTypes .= 's';
    }
    if (isset($_POST['email'])) {
        $updateFields[] = 'email = ?';
        $updateValues[] = $email;
        $updateTypes .= 's';
    }
}

// Handle home worksite (Settings tab)
if (array_key_exists('home_worksite_id', $_POST)) {
    $homeWorksiteId = $_POST['home_worksite_id'];
    if ($homeWorksiteId === '' || $homeWorksiteId === null) {
        $homeWorksiteId = null;
    } else {
        $homeWorksiteId = (int)$homeWorksiteId;
        if ($homeWorksiteId <= 0) {
            $homeWorksiteId = null;
        }
    }
    $updateFields[] = 'home_worksite_id = ?';
    $updateValues[] = $homeWorksiteId;
    $updateTypes .= 'i';
}

// Handle email notifications (Settings tab) - legacy toggle kept for backward compat
if (array_key_exists('email_notifications_enabled', $_POST)) {
    $emailNotificationsEnabled = ($_POST['email_notifications_enabled'] == '1') ? 1 : 0;
    $updateFields[] = 'email_notifications_enabled = ?';
    $updateValues[] = $emailNotificationsEnabled;
    $updateTypes .= 'i';
}

// Handle per-category notification preferences (Notifications tab)
$allowedPreferenceCategories = [
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

$notifPrefInput = isset($_POST['notif_pref']) && is_array($_POST['notif_pref'])
    ? $_POST['notif_pref']
    : [];

$pushPrefInput = isset($_POST['push_pref']) && is_array($_POST['push_pref'])
    ? $_POST['push_pref']
    : [];

if (!empty($notifPrefInput)) {
    try {
        $pdo = Database::getInstance();

        foreach ($notifPrefInput as $category => $value) {
            $category = (string)$category;

            if (!in_array($category, $allowedPreferenceCategories, true)) {
                continue;
            }

            $enabled = ($value == '1' || $value === true || $value === 1) ? 1 : 0;

            $stmt = $pdo->prepare("
                INSERT INTO sf_user_notification_preferences (user_id, category, enabled)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
            ");
            $stmt->execute([$id, $category, $enabled, $enabled]);
        }

        sf_app_log("profile_update: Updated email notification preferences for user_id={$id}");
    } catch (Throwable $e) {
        sf_app_log('profile_update: notif_pref update ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

if (!empty($pushPrefInput)) {
    try {
        $pdo = Database::getInstance();

        foreach ($pushPrefInput as $category => $value) {
            $category = (string)$category;

            if (!in_array($category, $allowedPreferenceCategories, true)) {
                continue;
            }

            $enabled = ($value == '1' || $value === true || $value === 1) ? 1 : 0;

            $stmt = $pdo->prepare("
                INSERT INTO sf_user_push_preferences (user_id, category, enabled)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = ?, updated_at = NOW()
            ");
            $stmt->execute([$id, $category, $enabled, $enabled]);
        }

        sf_app_log("profile_update: Updated push notification preferences for user_id={$id}");
    } catch (Throwable $e) {
        sf_app_log('profile_update: push_pref update ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

// Check if there are any fields to update
if (empty($updateFields)) {
    if (!empty($notifPrefInput) || !empty($pushPrefInput)) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Ei päivitettäviä kenttiä']);
    }
    exit;
}

// Build and execute UPDATE query
$sql = 'UPDATE sf_users SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
$updateValues[] = $id;
$updateTypes .= 'i';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($updateTypes, ...$updateValues);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}
$stmt->close();

// Update session variables for fields that were updated
if (isset($_POST['first_name'])) {
    $_SESSION['sf_user']['first_name'] = $first;
}
if (isset($_POST['last_name'])) {
    $_SESSION['sf_user']['last_name'] = $last;
}
if (isset($_POST['email'])) {
    $_SESSION['sf_user']['email'] = $email;
}
if (array_key_exists('home_worksite_id', $_POST)) {
    $_SESSION['sf_user']['home_worksite_id'] = $homeWorksiteId;
}
if (array_key_exists('email_notifications_enabled', $_POST)) {
    $_SESSION['sf_user']['email_notifications_enabled'] = $emailNotificationsEnabled;
}

echo json_encode(['ok' => true]);