<?php
/**
 * API Endpoint: Create Changelog Entry
 *
 * Creates a new sf_changelog record. Admin only.
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

$userId      = (int)$user['id'];
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

// Optional publish date; validate format and actual date validity
$publishDateRaw = trim($_POST['publish_date'] ?? '');
$publishDate    = null;
if ($publishDateRaw !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $publishDateRaw);
    if ($dt && $dt->format('Y-m-d') === $publishDateRaw) {
        $publishDate = $publishDateRaw;
    }
}

// Validate translations JSON
$translations = json_decode($rawJson, true);
if (!is_array($translations)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid translations JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure at least one language has a title
$hasContent = false;
foreach ($translations as $lang => $t) {
    if (!empty($t['title']) || !empty($t['content'])) {
        $hasContent = true;
        break;
    }
}
if (!$hasContent) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'At least one language must have content'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare(
        "INSERT INTO sf_changelog (feedback_id, translations, images, is_published, publish_date, created_by, created_at, updated_at)
         VALUES (:feedback_id, :translations, :images, :is_published, :publish_date, :created_by, NOW(), NOW())"
    );
    $stmt->execute([
        ':feedback_id'  => $feedbackId > 0 ? $feedbackId : null,
        ':translations' => json_encode($translations, JSON_UNESCAPED_UNICODE),
        ':images'       => $imagesJson,
        ':is_published' => $isPublished,
        ':publish_date' => $publishDate,
        ':created_by'   => $userId,
    ]);

    $newId = (int)$db->lastInsertId();

    // Send product_update notification email when publishing directly on create
    if ($isPublished === 1) {
        try {
            require_once __DIR__ . '/../../assets/services/email_services.php';
            if (function_exists('sf_mail_product_update')) {
                sf_mail_product_update($db, $newId);
            }
        } catch (Throwable $e) {
            error_log('changelog_create: sf_mail_product_update ERROR: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('changelog_create error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('changelog_create error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}