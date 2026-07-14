<?php
// app/api/get_flash_translations.php
// API endpoint to get language versions for a SafetyFlash group

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/render_services.php';

header('Content-Type: application/json; charset=utf-8');

function sf_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Get group_id from query parameter
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

if ($groupId <= 0) {
    sf_json(['ok' => false, 'error' => 'Missing or invalid group_id'], 400);
}

try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Get translations using the sf_get_flash_translations function
    $translations = sf_get_flash_translations($pdo, $groupId);

    sf_json([
        'ok' => true,
        'translations' => $translations,
        'count' => count($translations)
    ]);

} catch (Throwable $e) {
    sf_json(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}