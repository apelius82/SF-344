<?php
// app/api/site-detail-suggestions.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/protect.php';

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'suggestions' => [],
        'error' => 'Database connection failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$site = isset($_GET['site']) && is_string($_GET['site'])
    ? trim($_GET['site'])
    : '';

$q = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

$lang = isset($_GET['lang']) && is_string($_GET['lang'])
    ? trim($_GET['lang'])
    : '';

if ($site === '') {
    echo json_encode([
        'ok' => true,
        'suggestions' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$params = [
    ':site' => $site,
];

$langSql = '';

if ($lang !== '') {
    $langSql = 'AND lang = :lang';
    $params[':lang'] = $lang;
}

$searchSql = '';

if ($q !== '') {
    $searchSql = 'AND site_detail LIKE :q';
    $params[':q'] = '%' . addcslashes($q, '%_\\') . '%';
}

try {
    $stmt = $pdo->prepare("
        SELECT
            TRIM(site_detail) AS label,
            COUNT(*) AS usage_count,
            MAX(COALESCE(published_at, updated_at, created_at)) AS last_used_at
        FROM sf_flashes
        WHERE site = :site
          AND site_detail IS NOT NULL
          AND TRIM(site_detail) <> ''
          $langSql
          $searchSql
        GROUP BY TRIM(site_detail)
        ORDER BY usage_count DESC, last_used_at DESC, label ASC
        LIMIT 10
    ");

    $stmt->execute($params);

    $suggestions = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = trim((string)($row['label'] ?? ''));

        if ($label === '') {
            continue;
        }

        $suggestions[] = [
            'label' => $label,
            'usage_count' => (int)($row['usage_count'] ?? 0),
        ];
    }

    echo json_encode([
        'ok' => true,
        'suggestions' => $suggestions,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('site-detail-suggestions.php error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'suggestions' => [],
        'error' => 'Query failed',
    ], JSON_UNESCAPED_UNICODE);
}