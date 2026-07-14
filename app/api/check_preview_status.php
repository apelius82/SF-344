<?php
/**
 * Check preview generation status
 * Returns status and progress percentage for a flash
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$flashId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$flashId) {
    echo json_encode(['ok' => false, 'error' => 'Missing flash ID']);
    exit;
}

$pdo = Database::getInstance();

$stmt = $pdo->prepare("
    SELECT preview_status, preview_filename, preview_filename_2, created_at
    FROM sf_flashes 
    WHERE id = ?
");
$stmt->execute([$flashId]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    echo json_encode(['ok' => false, 'error' => 'Flash not found']);
    exit;
}

if (!isset($config['base_url'])) {
    echo json_encode(['ok' => false, 'error' => 'Configuration error']);
    exit;
}

$status = $flash['preview_status'] ?? 'completed';
$baseUrl = rtrim($config['base_url'], '/');

$sfCacheBust = static function (string $url, ?string $absPath): string {
    if (!empty($absPath) && is_file($absPath)) {
        $version = (string) filemtime($absPath);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($version);
    }
    return $url;
};

// Calculate progress percentage based on status and time
$progress = 0;
if ($status === 'pending') {
    $progress = 10;
} elseif ($status === 'processing') {
    // Estimate progress based on time (max 60 seconds expected)
    $createdAt = strtotime($flash['created_at']);
    $elapsed = time() - $createdAt;
    $progress = min(90, 20 + ($elapsed * 1.5)); // 20-90% over ~45 seconds
} elseif ($status === 'completed') {
    $progress = 100;
} elseif ($status === 'failed') {
    $progress = 0;
}

// --- Robust preview readiness: tarkista myös että tiedosto oikeasti löytyy ---
// Suojaa myös "Array" / ei-string -tapaukset, jotta ei muodostu /uploads/previews/Array -URL:ia
$fn1 = $flash['preview_filename'] ?? null;
$fn2 = $flash['preview_filename_2'] ?? null;

if (!is_string($fn1) || $fn1 === '' || $fn1 === 'Array') {
    $fn1 = null;
}
if (!is_string($fn2) || $fn2 === '' || $fn2 === 'Array') {
    $fn2 = null;
}

$path1 = $fn1 ? (__DIR__ . '/../../uploads/previews/' . basename($fn1)) : null;
$path2 = $fn2 ? (__DIR__ . '/../../uploads/previews/' . basename($fn2)) : null;

$has1 = $path1 && is_file($path1);
$has2 = $path2 && is_file($path2);

// Jos tiedosto löytyy, voidaan vastata valmiina vaikka status olisi jäänyt pendingiin
if ($has1) {
    $status = 'completed';
    $progress = 100;
}

$response = [
    'ok' => true,
    'status' => $status,
    'progress' => (int) $progress,
    'ready' => ($status === 'completed' && $has1),
    'failed' => ($status === 'failed'),
];

if ($has1) {
    $response['preview_url'] = $sfCacheBust(
        $baseUrl . '/uploads/previews/' . basename($fn1),
        $path1
    );

    if ($has2) {
        $response['preview_url_2'] = $sfCacheBust(
            $baseUrl . '/uploads/previews/' . basename($fn2),
            $path2
        );
    }
}

echo json_encode($response);