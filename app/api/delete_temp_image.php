<?php
// app/api/delete_temp_image.php
// Poistaa väliaikaisen kuvan kun käyttäjä poistaa kuvan lomakkeesta

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

$filename = isset($_POST['filename']) ? basename($_POST['filename']) : '';

if ($filename === '' || strpos($filename, 'temp_') !== 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid filename']);
    exit;
}

$tempDir = __DIR__ . '/../../uploads/temp/';
$filePath = $tempDir . $filename;

if (is_file($filePath)) {
    @unlink($filePath);
}

echo json_encode(['ok' => true]);