<?php
/**
 * API Endpoint: Update Changelog Entry
 *
 * Updates an existing sf_changelog record. Admin only.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$updateId    = (int)($_POST['update_id'] ?? 0);
$rawJson     = trim($_POST['translations'] ?? '');
$isPublished = (int)($_POST['is_published'] ?? 0) === 1 ? 1 : 0;
$feedbackId  = (int)($_POST['feedback_id'] ?? 0);

// Process uploaded images (up to 3 slots)
$uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/') . '/uploads/changelog/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxBytes     = 2 * 1024 * 1024; // 2 MB
$images       = [];
for ($slot = 1; $slot <= 3; $slot++) {
    $fileKey = 'image_' . $slot;
    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $tmp  = $_FILES[$fileKey]['tmp_name'];
        $size = $_FILES[$fileKey]['size'];
        if ($size > $maxBytes) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Image ' . $slot . ' exceeds 2 MB limit'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        if (!in_array($mime, $allowedMimes, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid image type for slot ' . $slot], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ext      = (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime]) ?? 'jpg';
        $filename = 'changelog_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $destPath)) {
            $images[] = 'uploads/changelog/' . $filename;
        }
    } elseif (!empty($_POST['existing_image_' . $slot])) {
        $existing = trim($_POST['existing_image_' . $slot]);
        // Only keep paths that look like our own upload paths (security)
        if (preg_match('#^uploads/changelog/[a-zA-Z0-9._-]+$#', $existing)) {
            $images[] = $existing;
        }
    }
}
$imagesJson = !empty($images) ? json_encode($images, JSON_UNESCAPED_UNICODE) : null;

// Optional publish date override; validate format and actual date validity
$publishDateRaw = trim($_POST['publish_date'] ?? '');
$publishDate    = null;
if ($publishDateRaw !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $publishDateRaw);
    if ($dt && $dt->format('Y-m-d') === $publishDateRaw) {
        $publishDate = $publishDateRaw;
    }
}

if ($updateId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid update ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$translations = json_decode($rawJson, true);
if (!is_array($translations)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid translations JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();

    // Verify the record exists
    $check = $db->prepare("SELECT id FROM sf_changelog WHERE id = :id");
    $check->execute([':id' => $updateId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Update not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check current published state and email_sent_at before updating
    $stateCheck = $db->prepare("SELECT is_published, email_sent_at FROM sf_changelog WHERE id = :id LIMIT 1");
    $stateCheck->execute([':id' => $updateId]);
    $currentState = $stateCheck->fetch(PDO::FETCH_ASSOC);
    $wasPublished = (int)($currentState['is_published'] ?? 0) === 1;

    $stmt = $db->prepare(
        "UPDATE sf_changelog
         SET translations = :translations,
             images       = :images,
             is_published = :is_published,
             publish_date = :publish_date,
             feedback_id  = :feedback_id,
             updated_at   = NOW()
         WHERE id = :id"
    );
    $stmt->execute([
        ':translations' => json_encode($translations, JSON_UNESCAPED_UNICODE),
        ':images'       => $imagesJson,
        ':is_published' => $isPublished,
        ':publish_date' => $publishDate,
        ':feedback_id'  => $feedbackId > 0 ? $feedbackId : null,
        ':id'           => $updateId,
    ]);

    // Send product_update email only on the first publish (draft → published transition)
    // and only when email has not been sent yet
    $isFirstPublish = (!$wasPublished && $isPublished === 1 && empty($currentState['email_sent_at']));
    if ($isFirstPublish) {
        try {
            require_once __DIR__ . '/../../assets/services/email_services.php';
            if (function_exists('sf_mail_product_update')) {
                sf_mail_product_update($db, $updateId);
            }
        } catch (Throwable $e) {
            error_log('changelog_update: sf_mail_product_update ERROR: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('changelog_update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('changelog_update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}