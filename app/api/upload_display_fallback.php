<?php
/**
 * Upload or remove display fallback image
 *
 * POST with multipart/form-data and field "image": upload new fallback image
 * POST with form field "action=remove": delete current fallback image
 *
 * Admin only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');

// Admin only
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden - Admin only']);
    exit;
}

$userId = (int)$user['id'];

// CSRF check (token sent as POST field or header)
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token validation failed']);
    exit;
}

$action = $_POST['action'] ?? 'upload';
$targetRaw = $_POST['target'] ?? 'display_fallback';
if (!is_string($targetRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid target']);
    exit;
}
$target = trim($targetRaw);

$targetMap = [
    'display_fallback' => [
        'setting_key' => 'display_fallback_image',
        'filename_prefix' => 'fallback_',
    ],
    'xibo_summary_background' => [
        'setting_key' => 'xibo_summary_background_image',
        'filename_prefix' => 'xibo_summary_bg_',
    ],
];

if (!isset($targetMap[$target])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid target']);
    exit;
}

$settingKey = $targetMap[$target]['setting_key'];
$filenamePrefix = $targetMap[$target]['filename_prefix'];

$uploadDir = __DIR__ . '/../../uploads/display/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

try {
    $pdo = Database::getInstance();

    if ($action === 'remove') {
        // Fetch current path from settings
        $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute([':key' => $settingKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentPath = $row['setting_value'] ?? '';

        if ($currentPath) {
            $filePath = __DIR__ . '/../../' . ltrim($currentPath, '/');
            if (file_exists($filePath) && !unlink($filePath)) {
                error_log('SafetyFlash: failed to delete fallback image: ' . $filePath);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO sf_settings (setting_key, setting_value, setting_type, updated_by, updated_at)
            VALUES (:key, '', 'string', :uid, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->execute([':key' => $settingKey, ':uid' => $userId]);

        echo json_encode(['ok' => true, 'removed' => true]);
        exit;
    }

    // Upload action
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['image']['error'] ?? -1;
        echo json_encode(['ok' => false, 'error' => 'Upload failed (code ' . $uploadError . ')']);
        exit;
    }

    $file = $_FILES['image'];

    // Validate MIME type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to initialize file type checker']);
        exit;
    }
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mimeType === false || !in_array($mimeType, $allowedMimes, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, WEBP']);
        exit;
    }

    // Validate size (max 5MB)
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        echo json_encode(['ok' => false, 'error' => 'File too large. Maximum size: 5MB']);
        exit;
    }

    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extMap[$mimeType] ?? 'jpg';
    $filename = $filenamePrefix . uniqid('', true) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    $relativePath = 'uploads/display/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    // Remove old file if exists
    $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = :key LIMIT 1");
    $stmt->execute([':key' => $settingKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldPath = $row['setting_value'] ?? '';
    if ($oldPath) {
        $oldFilePath = __DIR__ . '/../../' . ltrim($oldPath, '/');
        if (file_exists($oldFilePath) && !unlink($oldFilePath)) {
            error_log('SafetyFlash: failed to delete old fallback image: ' . $oldFilePath);
        }
    }

    // Save new path
    $stmt = $pdo->prepare("
        INSERT INTO sf_settings (setting_key, setting_value, setting_type, updated_by, updated_at)
        VALUES (:key, :val, 'string', :uid, NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");
    $stmt->execute([':key' => $settingKey, ':val' => $relativePath, ':uid' => $userId]);

    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    echo json_encode([
        'ok' => true,
        'path' => $relativePath,
        'url' => $baseUrl . '/' . $relativePath,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}