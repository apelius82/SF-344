<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/protect.php';

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$period = $_GET['period'] ?? '';
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
$year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
$site = isset($_GET['site']) && is_string($_GET['site']) ? trim($_GET['site']) : '';

$uiLang = isset($_SESSION['ui_lang']) && is_string($_SESSION['ui_lang']) ? $_SESSION['ui_lang'] : 'fi';

if (!in_array($uiLang, ['fi', 'sv', 'en', 'it', 'el'], true)) {
    $uiLang = 'fi';
}

$allowedPeriods = ['thismonth', '3months', '6months', 'thisyear', 'all'];

if ($period !== '' && !in_array($period, $allowedPeriods, true)) {
    $period = '';
}

$dateFilter = '';
$params = [];
$siteFilter = '';
$monthlySiteFilter = '';

if ($site !== '') {
    $siteFilter = 'AND site = :site';
    $monthlySiteFilter = 'AND f.site = :site';
    $params[':site'] = $site;
}

if ($month !== null && $year !== null) {
    $dateFilter = "AND created_at >= :start_date AND created_at < :end_date";
    $params[':start_date'] = sprintf('%04d-%02d-01', $year, $month);
    $params[':end_date'] = date('Y-m-01', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' +1 month'));
} elseif ($year !== null) {
    $dateFilter = "AND created_at >= :start_date AND created_at < :end_date";
    $params[':start_date'] = sprintf('%04d-01-01', $year);
    $params[':end_date'] = sprintf('%04d-01-01', $year + 1);
} elseif ($period === 'thismonth') {
    $dateFilter = "AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($period === '3months') {
    $dateFilter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
} elseif ($period === '6months') {
    $dateFilter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
} elseif ($period === 'thisyear') {
    $dateFilter = "AND created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
}

$originalStats = ['red' => 0, 'yellow' => 0, 'green' => 0, 'total' => 0];

$stmt = $pdo->prepare("
    SELECT COALESCE(original_type, type) AS original_type,
           COUNT(DISTINCT COALESCE(translation_group_id, id)) AS count
    FROM sf_flashes
    WHERE state = 'published'
    $dateFilter
    $siteFilter
    GROUP BY COALESCE(original_type, type)
");
$stmt->execute($params);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = (string)($row['original_type'] ?? '');
    $count = (int)($row['count'] ?? 0);

    if (isset($originalStats[$type])) {
        $originalStats[$type] = $count;
    }

    if ($type !== 'green') {
        $originalStats['total'] += $count;
    }
}

if ($site === '') {
    $stmt = $pdo->prepare("
        SELECT site,
               COUNT(DISTINCT COALESCE(translation_group_id, id)) AS count
        FROM sf_flashes
        WHERE state = 'published'
        AND site IS NOT NULL
        AND site <> ''
        $dateFilter
        GROUP BY site
        ORDER BY count DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $worksiteStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $locationParams = $params;
    $locationParams[':ui_lang'] = $uiLang;

    $stmt = $pdo->prepare("
        SELECT
            id,
            COALESCE(translation_group_id, id) AS flash_group_id,
            COALESCE(NULLIF(site_detail, ''), 'Ei tarkennettua paikkaa') AS site_detail_label,
            lang,
            CASE
                WHEN lang = :ui_lang THEN 0
                WHEN lang = 'fi' THEN 1
                ELSE 2
            END AS language_priority
        FROM sf_flashes
        WHERE state = 'published'
        $dateFilter
        $siteFilter
        ORDER BY
            COALESCE(translation_group_id, id) ASC,
            language_priority ASC,
            id ASC
    ");

    $stmt->execute($locationParams);

    $preferredByGroup = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupId = (string)($row['flash_group_id'] ?? $row['id'] ?? '');

        if ($groupId === '' || isset($preferredByGroup[$groupId])) {
            continue;
        }

        $label = trim((string)($row['site_detail_label'] ?? ''));

        if ($label === '') {
            $label = 'Ei tarkennettua paikkaa';
        }

        $preferredByGroup[$groupId] = $label;
    }

    $locationCounts = [];

    foreach ($preferredByGroup as $label) {
        $locationCounts[$label] = ($locationCounts[$label] ?? 0) + 1;
    }

    arsort($locationCounts, SORT_NUMERIC);

    $worksiteStats = [];

    foreach ($locationCounts as $label => $count) {
        $worksiteStats[] = [
            'site' => $label,
            'count' => $count,
        ];
    }

    $worksiteStats = array_slice($worksiteStats, 0, 50);
}

$monthlyStats = [];
$raw = [];

$start = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
$end = (new DateTimeImmutable('first day of this month'))->modify('+1 month');

try {
    $monthlySiteFilter = '';

    if ($site !== '') {
        $monthlySiteFilter = 'AND COALESCE(root.site, f.site) = :site';
    }

    $stmt = $pdo->prepare("
        SELECT
            f.id,
            COALESCE(NULLIF(f.translation_group_id, 0), f.id) AS flash_group_id,
            DATE_FORMAT(COALESCE(root.created_at, f.created_at), '%Y-%m') AS original_month_key,
            COALESCE(root.created_at, f.created_at) AS original_created_at,
            COALESCE(root.original_type, root.type, f.original_type, f.type) AS original_type,
            f.type AS current_type,
            DATE_FORMAT(f.created_at, '%Y-%m') AS current_month_key,
            f.created_at AS current_created_at,
            f.title,
            f.title_short,
            COALESCE(root.site, f.site) AS site,
            f.lang,
            CASE
                WHEN f.lang = :ui_lang THEN 0
                WHEN f.lang = 'fi' THEN 1
                ELSE 2
            END AS language_priority
        FROM sf_flashes f
        LEFT JOIN sf_flashes root
            ON root.id = NULLIF(f.translation_group_id, 0)
        WHERE f.state = 'published'
        AND (
            (
                COALESCE(root.created_at, f.created_at) >= :original_monthly_start
                AND COALESCE(root.created_at, f.created_at) < :original_monthly_end
            )
            OR (
                f.type = 'green'
                AND f.created_at >= :green_monthly_start
                AND f.created_at < :green_monthly_end
            )
        )
        $monthlySiteFilter
        ORDER BY
            COALESCE(NULLIF(f.translation_group_id, 0), f.id) ASC,
            language_priority ASC,
            f.id ASC
    ");

    $monthlyParams = [
        ':ui_lang' => $uiLang,
        ':original_monthly_start' => $start->format('Y-m-d 00:00:00'),
        ':original_monthly_end' => $end->format('Y-m-d 00:00:00'),
        ':green_monthly_start' => $start->format('Y-m-d 00:00:00'),
        ':green_monthly_end' => $end->format('Y-m-d 00:00:00'),
    ];

    if ($site !== '') {
        $monthlyParams[':site'] = $site;
    }

    $stmt->execute($monthlyParams);

    $monthlyGroups = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupId = (string)($row['flash_group_id'] ?? $row['id'] ?? '');

        if ($groupId === '') {
            continue;
        }

        if (!isset($monthlyGroups[$groupId])) {
            $monthlyGroups[$groupId] = [
                'original_type' => (string)($row['original_type'] ?? ''),
                'original_month_key' => (string)($row['original_month_key'] ?? ''),
                'original_created_at' => (string)($row['original_created_at'] ?? ''),
                'original_candidates' => [],
                'green_candidates' => [],
            ];
        }

        $currentType = (string)($row['current_type'] ?? '');

        if ($currentType !== 'green') {
            $monthlyGroups[$groupId]['original_candidates'][] = $row;
        }

        if ($currentType === 'green') {
            $monthlyGroups[$groupId]['green_candidates'][] = $row;
        }

        if (empty($monthlyGroups[$groupId]['fallback_candidates'])) {
            $monthlyGroups[$groupId]['fallback_candidates'] = [];
        }

        $monthlyGroups[$groupId]['fallback_candidates'][] = $row;
    }

    $pickPreferredMonthlyRow = static function (array $rows): ?array {
        if (empty($rows)) {
            return null;
        }

        usort($rows, static function (array $a, array $b): int {
            $priorityA = (int)($a['language_priority'] ?? 99);
            $priorityB = (int)($b['language_priority'] ?? 99);

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        return $rows[0];
    };

    foreach ($monthlyGroups as $groupId => $group) {
        $originalType = (string)($group['original_type'] ?? '');
        $originalMonthKey = (string)($group['original_month_key'] ?? '');

        if (in_array($originalType, ['red', 'yellow'], true) && $originalMonthKey !== '') {
            $preferredOriginalRow = $pickPreferredMonthlyRow(
                !empty($group['original_candidates'])
                    ? $group['original_candidates']
                    : ($group['fallback_candidates'] ?? [])
            );

            if ($preferredOriginalRow !== null) {
                if (!isset($raw[$originalMonthKey])) {
                    $raw[$originalMonthKey] = [
                        'red' => 0,
                        'yellow' => 0,
                        'green' => 0,
                        'total' => 0,
                        'items' => [
                            'red' => [],
                            'yellow' => [],
                            'green' => [],
                        ],
                    ];
                }

                $raw[$originalMonthKey][$originalType]++;
                $raw[$originalMonthKey]['total']++;

                $raw[$originalMonthKey]['items'][$originalType][] = [
                    'id' => (int)$preferredOriginalRow['id'],
                    'title' => (string)($preferredOriginalRow['title_short'] ?: $preferredOriginalRow['title'] ?: ''),
                    'site' => (string)($preferredOriginalRow['site'] ?? ''),
                    'created_at' => (string)($group['original_created_at'] ?? $preferredOriginalRow['current_created_at'] ?? ''),
                ];
            }
        }

        if (!empty($group['green_candidates'])) {
            usort($group['green_candidates'], static function (array $a, array $b): int {
                return strcmp((string)($a['current_created_at'] ?? ''), (string)($b['current_created_at'] ?? ''));
            });

            $firstGreenRow = $group['green_candidates'][0];
            $greenMonthKey = (string)($firstGreenRow['current_month_key'] ?? '');

            if ($greenMonthKey !== '') {
                $preferredGreenRow = $pickPreferredMonthlyRow($group['green_candidates']);

                if ($preferredGreenRow !== null) {
                    if (!isset($raw[$greenMonthKey])) {
                        $raw[$greenMonthKey] = [
                            'red' => 0,
                            'yellow' => 0,
                            'green' => 0,
                            'total' => 0,
                            'items' => [
                                'red' => [],
                                'yellow' => [],
                                'green' => [],
                            ],
                        ];
                    }

                    $raw[$greenMonthKey]['green']++;

                    $raw[$greenMonthKey]['items']['green'][] = [
                        'id' => (int)$preferredGreenRow['id'],
                        'title' => (string)($preferredGreenRow['title_short'] ?: $preferredGreenRow['title'] ?: ''),
                        'site' => (string)($preferredGreenRow['site'] ?? ''),
                        'created_at' => (string)($firstGreenRow['current_created_at'] ?? $preferredGreenRow['current_created_at'] ?? ''),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    $raw = [];
}

for ($i = 0; $i < 12; $i++) {
    $date = $start->modify('+' . $i . ' months');
    $key = $date->format('Y-m');

    $monthlyStats[] = [
        'month' => $key,
        'label' => $date->format('m/Y'),
        'red' => $raw[$key]['red'] ?? 0,
        'yellow' => $raw[$key]['yellow'] ?? 0,
        'green' => $raw[$key]['green'] ?? 0,
        'total' => $raw[$key]['total'] ?? 0,
        'items' => $raw[$key]['items'] ?? [
            'red' => [],
            'yellow' => [],
            'green' => [],
        ],
    ];
}

echo json_encode([
    'originalStats' => $originalStats,
    'worksiteStats' => $worksiteStats,
    'monthlyStats' => $monthlyStats,
    'selectedSite' => $site,
    'locationMode' => $site !== '',
], JSON_UNESCAPED_UNICODE);