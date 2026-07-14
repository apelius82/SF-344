<?php
/**
 * app/api/get_library_images.php
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Lataa config ensin (määrittelee $config)
require __DIR__ . '/../../config.php';

// TÄRKEÄ: auth.php asettaa oikean session-nimen/cookieasetukset ja käynnistää session oikein
require_once __DIR__ . '/../includes/auth.php';

// Tarkista kirjautuminen
$currentUser = sf_current_user();
if (!$currentUser || empty($currentUser['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Yhdistä tietokantaan (sama tapa kuin muualla sovelluksessa)
$mysqli = sf_db();
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error'], JSON_UNESCAPED_UNICODE);
    exit;
}

$category = (string)($_GET['category'] ?? 'all');

$sqlBase = "SELECT id, filename, title, category, description
            FROM sf_image_library
            WHERE is_active = 1";

$orderBy = " ORDER BY category ASC, sort_order ASC, title ASC";

if ($category !== '' && $category !== 'all') {
    $sql = $sqlBase . " AND category = ?" . $orderBy;
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Query prepare failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt->bind_param('s', $category);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = $sqlBase . $orderBy;
    $result = $mysqli->query($sql);
}

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$basePath = __DIR__ . '/../../';

$images = [];
while ($row = $result->fetch_assoc()) {
    $filename = (string)($row['filename'] ?? '');

    // Polkujen tarkistus (uploads/library tai uploads/images)
    // BUG FIX 2: Return relative paths WITHOUT base_path to avoid duplication in JS
    $safeName   = $filename !== '' ? basename($filename) : '';

    if ($safeName !== '' && file_exists($basePath . 'uploads/library/' . $safeName)) {
        $url = '/uploads/library/' . rawurlencode($safeName);
    } elseif ($safeName !== '' && file_exists($basePath . 'uploads/images/' . $safeName)) {
        $url = '/uploads/images/' . rawurlencode($safeName);
    } else {
        $url = '/uploads/library/' . rawurlencode($safeName); // fallback
    }

    $images[] = [
        'id'          => (int)($row['id'] ?? 0),
        'filename'    => $filename,
        'title'       => (string)($row['title'] ?? ''),
        'category'    => (string)($row['category'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'url'         => $url,
    ];
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
$mysqli->close();

echo json_encode([
    'success' => true,
    'images'  => $images,
    'count'   => count($images),
], JSON_UNESCAPED_UNICODE);