<?php
// app/api/analytics_stats.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/protect.php';

$user = sf_current_user();

if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    $period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'year';
    $selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
    $selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
    $userFilter = isset($_GET['user_filter']) ? trim((string)$_GET['user_filter']) : 'exclude_admins';

    if ($userFilter === '') {
        $userFilter = 'exclude_admins';
    }

    if ($selectedYear < 2020 || $selectedYear > ((int)date('Y') + 1)) {
        $selectedYear = (int)date('Y');
    }

    if ($selectedMonth < 0 || $selectedMonth > 12) {
        $selectedMonth = 0;
    }

    if (!in_array($period, ['year', 'month', '30', 'day'], true)) {
        $period = 'year';
    }

    if ($period === 'day') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = date('Y-m-d');
        } else {
            [$selectedDateYear, $selectedDateMonth, $selectedDateDay] = array_map('intval', explode('-', $selectedDate));

            if (!checkdate($selectedDateMonth, $selectedDateDay, $selectedDateYear)) {
                $selectedDate = date('Y-m-d');
            }
        }

        $start = new DateTimeImmutable($selectedDate . ' 00:00:00');
        $end = $start->modify('+1 day');
        $groupMode = 'day';
    } elseif ($period === '30') {
        $start = new DateTimeImmutable('-29 days');
        $end = new DateTimeImmutable('tomorrow');
        $groupMode = 'day';
    } elseif ($period === 'month' && $selectedMonth > 0) {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth));
        $end = $start->modify('first day of next month');
        $groupMode = 'day';
    } else {
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $selectedYear));
        $end = $start->modify('first day of January next year');
        $groupMode = 'month';
        $period = 'year';
    }

    $startDate = $start->format('Y-m-d H:i:s');
    $endDate = $end->format('Y-m-d H:i:s');

    $params = [
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];

    $analyticsFilterParams = [];

    $analyticsUserWhere = '';

    if ($userFilter === 'exclude_admins') {
        $analyticsUserWhere = "
            (
                e.user_id IS NULL
                OR e.user_id NOT IN (
                    SELECT su.id
                    FROM sf_users su
                    WHERE su.role_id = 1
                )
            )
        ";
    } elseif ($userFilter === 'admins') {
        $analyticsUserWhere = "
            e.user_id IN (
                SELECT su.id
                FROM sf_users su
                WHERE su.role_id = 1
            )
        ";
    } elseif (preg_match('/^role_([0-9]+)$/', $userFilter, $roleMatch)) {
        $analyticsRoleId = (int)$roleMatch[1];

        if ($analyticsRoleId > 0) {
            $analyticsUserWhere = "
                e.user_id IN (
                    SELECT su.id
                    FROM sf_users su
                    WHERE su.role_id = :analytics_role_id_primary

                    UNION

                    SELECT uar.user_id
                    FROM user_additional_roles uar
                    WHERE uar.role_id = :analytics_role_id_additional
                )
            ";

            $analyticsFilterParams[':analytics_role_id_primary'] = $analyticsRoleId;
            $analyticsFilterParams[':analytics_role_id_additional'] = $analyticsRoleId;

            $params[':analytics_role_id_primary'] = $analyticsRoleId;
            $params[':analytics_role_id_additional'] = $analyticsRoleId;
        }
    }

    $analyticsEventsSource = 'sf_analytics_events';

    if ($analyticsUserWhere !== '') {
        $analyticsEventsSource = "(SELECT e.* FROM {$analyticsEventsSource} e WHERE {$analyticsUserWhere}) AS sf_analytics_events";
    }

    $value = static function (string $sql, array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)($stmt->fetchColumn() ?: 0);
    };

    $rows = static function (string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $notificationKey = "
        COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_id')), ''),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.push_id')), ''),
            NULLIF(session_id, ''),
            CONCAT('event_', id)
        )
    ";

    $summary = [
        'active_today' => $value("
            SELECT COUNT(DISTINCT user_id)
            FROM {$analyticsEventsSource}
            WHERE created_at >= CURDATE()
              AND user_id IS NOT NULL
        ", $analyticsFilterParams),
        'active_period' => $value("
            SELECT COUNT(DISTINCT user_id)
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND user_id IS NOT NULL
        ", $params),
        'page_views' => $value("
            SELECT COUNT(*)
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND event_type = 'page_view'
        ", $params),
        'flash_views' => $value("
            SELECT COUNT(*)
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND event_type = 'flash_view'
        ", $params),
        'pwa_users' => $value("
            SELECT COUNT(DISTINCT user_id)
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND is_pwa = 1
              AND user_id IS NOT NULL
        ", $params),
        'push_sent' => $value("
            SELECT COUNT(DISTINCT {$notificationKey})
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND event_type = 'push_sent'
        ", $params),
        'push_clicked' => $value("
            SELECT COUNT(DISTINCT {$notificationKey})
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND event_type = 'push_clicked'
        ", $params),
        'push_opened' => $value("
            SELECT COUNT(DISTINCT {$notificationKey})
            FROM {$analyticsEventsSource}
            WHERE created_at >= :start_date   AND created_at < :end_date
              AND event_type = 'push_flash_open'
        ", $params),
    ];

    $summary['push_ctr'] = $summary['push_sent'] > 0
        ? round(($summary['push_clicked'] / $summary['push_sent']) * 100, 1)
        : 0;

    $summary['push_open_rate'] = $summary['push_sent'] > 0
        ? round(($summary['push_opened'] / $summary['push_sent']) * 100, 1)
        : 0;

    $summary['pwa_install_rate'] = $summary['active_period'] > 0
        ? round(($summary['pwa_users'] / $summary['active_period']) * 100, 1)
        : 0;

    $summary['push_permission_granted'] = $value("
        SELECT COUNT(DISTINCT user_id)
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND user_id IS NOT NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_permission')) = 'granted'
    ", $params);

    $summary['push_permission_denied'] = $value("
        SELECT COUNT(DISTINCT user_id)
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND user_id IS NOT NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_permission')) = 'denied'
    ", $params);

    $summary['push_permission_default'] = $value("
        SELECT COUNT(DISTINCT user_id)
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND user_id IS NOT NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_permission')) = 'default'
    ", $params);

    $summary['read_100_users'] = $value("
        SELECT COUNT(DISTINCT user_id)
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND event_type = 'flash_read_100'
          AND user_id IS NOT NULL
    ", $params);

    $summary['read_100_rate'] = $summary['flash_views'] > 0
        ? round(($summary['read_100_users'] / $summary['flash_views']) * 100, 1)
        : 0;

    $summary['avg_duration_seconds'] = $value("
        SELECT ROUND(AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.duration_seconds')) AS UNSIGNED)))
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND event_type = 'flash_view_duration'
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.duration_seconds')) IS NOT NULL
    ", $params);

    $seriesDateExpression = $groupMode === 'month'
        ? "DATE_FORMAT(created_at, '%Y-%m')"
        : "DATE(created_at)";

    $seriesRows = $rows("
        SELECT
            {$seriesDateExpression} AS day_key,
            SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
            SUM(CASE WHEN event_type = 'flash_view' THEN 1 ELSE 0 END) AS flash_views,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            SUM(CASE WHEN event_type = 'push_clicked' THEN 1 ELSE 0 END) AS push_clicks
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
        GROUP BY {$seriesDateExpression}
        ORDER BY day_key ASC
    ", $params);

    $seriesByDate = [];

    foreach ($seriesRows as $row) {
        $seriesByDate[(string)$row['day_key']] = [
            'date' => (string)$row['day_key'],
            'page_views' => (int)$row['page_views'],
            'flash_views' => (int)$row['flash_views'],
            'users' => (int)$row['users'],
            'push_clicks' => (int)$row['push_clicks'],
        ];
    }

    $series = [];

    if ($groupMode === 'month') {
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $selectedYear, $month);

            $series[] = $seriesByDate[$key] ?? [
                'date' => $key,
                'page_views' => 0,
                'flash_views' => 0,
                'users' => 0,
                'push_clicks' => 0,
            ];
        }
    } else {
        $periodStart = new DateTimeImmutable($startDate);
        $periodEnd = new DateTimeImmutable($endDate);

        for ($day = $periodStart; $day < $periodEnd; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');

            $series[] = $seriesByDate[$key] ?? [
                'date' => $key,
                'page_views' => 0,
                'flash_views' => 0,
                'users' => 0,
                'push_clicks' => 0,
            ];
        }
    }
    $interactionEventTypes = [
        'view_tab_comments_open',
        'view_tab_events_open',
        'view_tab_additional_info_open',
        'view_tab_versions_open',
        'view_tab_media_open',
        'view_playlist_modal_open',
        'view_image_copy',
        'view_image_copy_failed',
        'dashboard_module_copy_image',
        'dashboard_module_copy_image_failed',
        'dashboard_monthly_flash_open',
        'dashboard_injury_flash_open',
    ];

    $interactionEventPlaceholders = [];
    $interactionParams = $params;

    foreach ($interactionEventTypes as $index => $eventType) {
        $placeholder = ':interaction_event_' . $index;
        $interactionEventPlaceholders[] = $placeholder;
        $interactionParams[$placeholder] = $eventType;
    }

    $interactionEventsSql = implode(',', $interactionEventPlaceholders);

    $internalClicks = $rows("
        SELECT
            event_type,
            COUNT(*) AS clicks,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            MAX(created_at) AS last_clicked_at
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND event_type IN ({$interactionEventsSql})
        GROUP BY event_type
        ORDER BY clicks DESC
        LIMIT 20
    ", $interactionParams);

    $internalClickUsers = $rows("
        SELECT
            sf_analytics_events.user_id,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
            COALESCE(NULLIF(u.email, ''), '') AS email,
            COUNT(*) AS clicks,
            COUNT(DISTINCT sf_analytics_events.event_type) AS action_count,
            GROUP_CONCAT(DISTINCT sf_analytics_events.event_type ORDER BY sf_analytics_events.event_type SEPARATOR ',') AS action_types,
            MAX(sf_analytics_events.created_at) AS last_clicked_at
        FROM {$analyticsEventsSource}
        LEFT JOIN sf_users u ON u.id = sf_analytics_events.user_id
        WHERE sf_analytics_events.created_at >= :start_date
          AND sf_analytics_events.created_at < :end_date
          AND sf_analytics_events.event_type IN ({$interactionEventsSql})
          AND sf_analytics_events.user_id IS NOT NULL
        GROUP BY sf_analytics_events.user_id, u.first_name, u.last_name, u.email
        ORDER BY clicks DESC, last_clicked_at DESC
        LIMIT 20
    ", $interactionParams);

    $copyEventTypes = [
        'view_image_copy',
        'view_image_copy_failed',
        'dashboard_module_copy_image',
        'dashboard_module_copy_image_failed',
    ];

    $copyEventPlaceholders = [];
    $copyEventParams = $params;

    foreach ($copyEventTypes as $index => $eventType) {
        $placeholder = ':copy_event_' . $index;
        $copyEventPlaceholders[] = $placeholder;
        $copyEventParams[$placeholder] = $eventType;
    }

    $copyEventsSql = implode(',', $copyEventPlaceholders);

    $copyEvents = $rows("
        SELECT
            sf_analytics_events.id,
            sf_analytics_events.event_type,
            sf_analytics_events.target_id AS flash_id,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
            COALESCE(NULLIF(u.email, ''), '') AS email,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.source')), ''), '') AS source,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.preview_card')), ''), '') AS preview_card,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.module_key')), ''), '') AS module_key,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.module_title')), ''), '') AS module_title,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.site')), ''), '') AS site,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.flash_type')), ''), '') AS flash_type,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.result')), ''), '') AS result,
            sf_analytics_events.created_at
        FROM {$analyticsEventsSource}
        LEFT JOIN sf_users u ON u.id = sf_analytics_events.user_id
        WHERE sf_analytics_events.created_at >= :start_date
          AND sf_analytics_events.created_at < :end_date
          AND sf_analytics_events.event_type IN ({$copyEventsSql})
        ORDER BY sf_analytics_events.created_at DESC
        LIMIT 30
    ", $copyEventParams);
    $topPages = $rows("
        SELECT
            COALESCE(NULLIF(page, ''), 'unknown') AS page,
            COUNT(*) AS views,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date   AND created_at < :end_date
          AND event_type = 'page_view'
        GROUP BY COALESCE(NULLIF(page, ''), 'unknown')
        ORDER BY views DESC
        LIMIT 10
    ", $params);

    $devices = $rows("
        SELECT
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.operating_system')), ''),
                NULLIF(platform, ''),
                'Other'
            ) AS device_label,
            COUNT(*) AS events,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            COUNT(DISTINCT CONCAT(
                COALESCE(CAST(user_id AS CHAR), CONCAT('anon_', COALESCE(session_id, ip_hash, CAST(id AS CHAR)))),
                '|',
                COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.operating_system')), ''), NULLIF(platform, ''), 'Other'),
                '|',
                COALESCE(NULLIF(browser, ''), 'Other')
            )) AS user_devices
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date   AND created_at < :end_date
          AND event_type = 'page_view'
        GROUP BY device_label
        ORDER BY users DESC, user_devices DESC, events DESC
        LIMIT 8
    ", $params);

    $pwaUsage = $rows("
        SELECT
            CASE WHEN is_pwa = 1 THEN 'pwa' ELSE 'browser' END AS usage_mode,
            COUNT(*) AS events,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            COUNT(DISTINCT CONCAT(
                COALESCE(CAST(user_id AS CHAR), CONCAT('anon_', COALESCE(session_id, ip_hash, CAST(id AS CHAR)))),
                '|',
                CASE WHEN is_pwa = 1 THEN 'pwa' ELSE 'browser' END
            )) AS user_devices
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND event_type = 'page_view'
        GROUP BY usage_mode
        ORDER BY users DESC, user_devices DESC, events DESC
    ", $params);

    $topFlashes = $rows("
        SELECT
            target_id AS flash_id,
            CONCAT('SafetyFlash #', target_id) AS title,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.site')), ''), '') AS site,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.flash_type')), ''), '') AS type,
            SUM(CASE WHEN event_type = 'flash_view' THEN 1 ELSE 0 END) AS views,
            COUNT(DISTINCT CASE WHEN event_type = 'flash_view' AND user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            ROUND(AVG(CASE
                WHEN event_type = 'flash_view_duration'
                THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.duration_seconds')) AS UNSIGNED)
                ELSE NULL
            END)) AS avg_duration_seconds,
            COUNT(DISTINCT CASE WHEN event_type = 'flash_read_100' AND user_id IS NOT NULL THEN user_id ELSE NULL END) AS read_100_count,
            CASE
                WHEN COUNT(DISTINCT CASE WHEN event_type = 'flash_view' AND user_id IS NOT NULL THEN user_id ELSE NULL END) > 0
                THEN ROUND(
                    COUNT(DISTINCT CASE WHEN event_type = 'flash_read_100' AND user_id IS NOT NULL THEN user_id ELSE NULL END)
                    / COUNT(DISTINCT CASE WHEN event_type = 'flash_view' AND user_id IS NOT NULL THEN user_id ELSE NULL END)
                    * 100,
                    1
                )
                ELSE 0
            END AS read_100_rate
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND target_type = 'flash'
          AND target_id IS NOT NULL
          AND event_type IN ('flash_view', 'flash_view_duration', 'flash_read_100')
        GROUP BY target_id, site, type
        HAVING views > 0
        ORDER BY views DESC
        LIMIT 10
    ", $params);

    $trafficSources = $rows("

        SELECT

            COALESCE(

                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.navigation_source')), ''),

                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')), ''),

                'unknown'

            ) AS source,

            COUNT(*) AS events,

            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users

        FROM {$analyticsEventsSource}

        WHERE created_at >= :start_date   AND created_at < :end_date

          AND event_type IN (

              'flash_view',

              'list_flash_open',

              'dashboard_flash_open',

              'dashboard_waiting_flash_open',

              'dashboard_injury_flash_open',

              'dashboard_monthly_flash_open',

              'push_flash_open',

              'direct_flash_open'

          )

        GROUP BY source

        ORDER BY events DESC

        LIMIT 10

    ", $params);

    $worksites = $rows("
        SELECT
            CASE
                WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.site')) IS NULL THEN 'Tuntematon'
                WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.site')) = '' THEN 'Tuntematon'
                WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.site')) = 'null' THEN 'Tuntematon'
                ELSE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.site'))
            END AS site,
            COUNT(*) AS events,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            SUM(CASE WHEN event_type = 'flash_view' THEN 1 ELSE 0 END) AS flash_views,
            COUNT(DISTINCT CASE
                WHEN is_pwa = 1 AND user_id IS NOT NULL THEN user_id
                ELSE NULL
            END) AS pwa_users
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND event_type IN ('page_view', 'flash_view')
        GROUP BY site
        ORDER BY flash_views DESC, events DESC
        LIMIT 10
    ", $params);

    $dailyActivity = $rows("
        SELECT
            DATE(created_at) AS activity_date,
            COUNT(*) AS events,
            SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
            SUM(CASE WHEN event_type = 'flash_view' THEN 1 ELSE 0 END) AS flash_views,
            SUM(CASE WHEN event_type = 'flash_read_100' THEN 1 ELSE 0 END) AS full_reads,
            SUM(CASE WHEN event_type = 'push_clicked' THEN 1 ELSE 0 END) AS push_clicks,
            SUM(CASE WHEN event_type = 'push_flash_open' THEN 1 ELSE 0 END) AS push_opens,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id ELSE NULL END) AS users,
            COUNT(DISTINCT CASE WHEN is_pwa = 1 AND user_id IS NOT NULL THEN user_id ELSE NULL END) AS pwa_users
        FROM {$analyticsEventsSource}
        WHERE created_at >= :start_date
          AND created_at < :end_date
        GROUP BY DATE(created_at)
        ORDER BY activity_date DESC
        LIMIT 45
    ", $params);

    $userActivity = $rows("
        SELECT
            sf_analytics_events.user_id,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
            COALESCE(NULLIF(u.email, ''), '') AS email,
            COALESCE(NULLIF(r.name, ''), '') AS role_name,
            COUNT(*) AS events,
            SUM(CASE WHEN sf_analytics_events.event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
            SUM(CASE WHEN sf_analytics_events.event_type = 'flash_view' THEN 1 ELSE 0 END) AS flash_views,
            SUM(CASE WHEN sf_analytics_events.event_type = 'flash_read_100' THEN 1 ELSE 0 END) AS full_reads,
            SUM(CASE WHEN sf_analytics_events.event_type = 'push_clicked' THEN 1 ELSE 0 END) AS push_clicks,
            SUM(CASE WHEN sf_analytics_events.event_type = 'push_flash_open' THEN 1 ELSE 0 END) AS push_opens,
            COUNT(DISTINCT DATE(sf_analytics_events.created_at)) AS active_days,
            COUNT(DISTINCT CASE
                WHEN sf_analytics_events.target_type = 'flash'
                 AND sf_analytics_events.target_id IS NOT NULL
                 AND sf_analytics_events.event_type = 'flash_view'
                THEN sf_analytics_events.target_id
                ELSE NULL
            END) AS unique_flashes,
            MAX(sf_analytics_events.created_at) AS last_active_at,
            MAX(CASE WHEN sf_analytics_events.is_pwa = 1 THEN 1 ELSE 0 END) AS used_pwa,
            ROUND(AVG(CASE
                WHEN sf_analytics_events.event_type = 'flash_view_duration'
                THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(sf_analytics_events.metadata_json, '$.duration_seconds')) AS UNSIGNED)
                ELSE NULL
            END)) AS avg_duration_seconds
        FROM {$analyticsEventsSource}
        LEFT JOIN sf_users u ON u.id = sf_analytics_events.user_id
        LEFT JOIN sf_roles r ON r.id = u.role_id
        WHERE sf_analytics_events.created_at >= :start_date
          AND sf_analytics_events.created_at < :end_date
          AND sf_analytics_events.user_id IS NOT NULL
        GROUP BY sf_analytics_events.user_id, u.first_name, u.last_name, u.email, r.name
        ORDER BY events DESC, flash_views DESC, last_active_at DESC
        LIMIT 50
    ", $params);

    echo json_encode([
        'ok' => true,
        'period' => $period,
        'year' => $selectedYear,
        'month' => $selectedMonth,
        'group_mode' => $groupMode,
        'user_filter' => $userFilter,
        'summary' => $summary,
        'series' => $series,
        'internal_clicks' => $internalClicks,
        'internal_click_users' => $internalClickUsers,
        'copy_events' => $copyEvents,
        'top_pages' => $topPages,
        'devices' => $devices,
        'pwa_usage' => $pwaUsage,
        'top_flashes' => $topFlashes,
        'traffic_sources' => $trafficSources,
        'worksites' => $worksites,
        'daily_activity' => $dailyActivity,
        'user_activity' => $userActivity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Analytics stats failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Analytics query failed',
    ], JSON_UNESCAPED_UNICODE);
}