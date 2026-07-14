<?php
/**
 * Generate Dashboard PDF Report API Endpoint
 *
 * Generates an A4 PDF dashboard report with statistics, worksite data,
 * injury heatmap and recent injuries.
 *
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

// Require authentication (also validates CSRF for POST via protect.php)
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check Composer autoload
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Composer dependencies not installed']);
    exit;
}
require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

$uiLang = $_SESSION['ui_lang'] ?? 'fi';

// ---- Input parameters ----
$startDate       = isset($_POST['start_date'])       ? trim($_POST['start_date'])       : '';
$endDate         = isset($_POST['end_date'])         ? trim($_POST['end_date'])         : '';
$site            = isset($_POST['site'])             ? trim($_POST['site'])             : '';
$includeStats    = (($_POST['include_stats']     ?? '1') === '1');
$includeWorksites= (($_POST['include_worksites'] ?? '1') === '1');
$includeInjuries = (($_POST['include_injuries']  ?? '1') === '1');
$includeRecent   = (($_POST['include_recent']    ?? '1') === '1');

// Validate dates
if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = '';
}
if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = '';
}
// Ensure start <= end
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// ---- Build date filter ----
$dateFilter = '';
$params     = [];

if ($startDate !== '' && $endDate !== '') {
    $dateFilter          = 'AND created_at >= :start_date AND created_at <= :end_date_eod';
    $params[':start_date']   = $startDate . ' 00:00:00';
    $params[':end_date_eod'] = $endDate   . ' 23:59:59';
} elseif ($startDate !== '') {
    $dateFilter        = 'AND created_at >= :start_date';
    $params[':start_date'] = $startDate . ' 00:00:00';
} elseif ($endDate !== '') {
    $dateFilter          = 'AND created_at <= :end_date_eod';
    $params[':end_date_eod'] = $endDate . ' 23:59:59';
}

// Site filter for stats/worksites
$siteFilter = '';
$siteParam  = [];
if ($site !== '') {
    $siteFilter      = 'AND site = :site';
    $siteParam[':site'] = $site;
}

$allParams = array_merge($params, $siteParam);

// ---- 1. Statistics ----
$originalStats = ['red' => 0, 'yellow' => 0, 'total' => 0];
if ($includeStats) {
    try {
        $sql = "
            SELECT
                COALESCE(original_type, type) AS original_type,
                COUNT(DISTINCT COALESCE(translation_group_id, id)) AS count
            FROM sf_flashes
            WHERE state = 'published'
            $dateFilter
            $siteFilter
            GROUP BY COALESCE(original_type, type)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $t = $row['original_type'] ?? '';
            $c = (int)($row['count'] ?? 0);
            if (isset($originalStats[$t])) {
                $originalStats[$t] = $c;
            }
            if ($t !== 'green') {
                $originalStats['total'] += $c;
            }
        }
    } catch (Throwable $e) {
        // silent fail
    }
}

// ---- 2. Worksite statistics ----
$worksiteStats = [];
$maxWorksiteCount = 0;
if ($includeWorksites) {
    try {
        $sql = "
            SELECT site, COUNT(DISTINCT COALESCE(translation_group_id, id)) AS count
            FROM sf_flashes
            WHERE state = 'published'
            AND site IS NOT NULL AND site != ''
            $dateFilter
            $siteFilter
            GROUP BY site
            ORDER BY count DESC
            LIMIT 15
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        $worksiteStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($worksiteStats as $ws) {
            $maxWorksiteCount = max($maxWorksiteCount, (int)($ws['count'] ?? 0));
        }
    } catch (Throwable $e) {
        // silent fail
    }
}

// ---- 3. Body-part injury counts (for heatmap) ----
$bodyPartCounts = [];
$categoryTotals = [];

// Build date filter for injury queries (joins use f.created_at)
$injuryDateFilter = '';
$injuryParams     = [];
if ($startDate !== '' && $endDate !== '') {
    $injuryDateFilter              = 'AND f.created_at >= :start_date AND f.created_at <= :end_date_eod';
    $injuryParams[':start_date']   = $startDate . ' 00:00:00';
    $injuryParams[':end_date_eod'] = $endDate   . ' 23:59:59';
} elseif ($startDate !== '') {
    $injuryDateFilter          = 'AND f.created_at >= :start_date';
    $injuryParams[':start_date'] = $startDate . ' 00:00:00';
} elseif ($endDate !== '') {
    $injuryDateFilter              = 'AND f.created_at <= :end_date_eod';
    $injuryParams[':end_date_eod'] = $endDate . ' 23:59:59';
}

$injurySiteFilter = '';
$injurySiteParam  = [];
if ($site !== '') {
    $injurySiteFilter      = 'AND f.site = :site';
    $injurySiteParam[':site'] = $site;
}
$injuryAllParams = array_merge($injuryParams, $injurySiteParam);

if ($includeInjuries || $includeRecent) {
    try {
        $sql = "
            SELECT
                bp.svg_id,
                bp.category,
                COUNT(DISTINCT COALESCE(f.translation_group_id, f.id)) AS cnt
            FROM body_parts bp
            LEFT JOIN incident_body_part ibp ON ibp.body_part_id = bp.id
            LEFT JOIN sf_flashes f
                ON  f.id    = ibp.incident_id
                AND f.state = 'published'
                $injuryDateFilter
                $injurySiteFilter
            GROUP BY bp.id, bp.svg_id, bp.category
            ORDER BY bp.sort_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($injuryAllParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $svgId    = $row['svg_id']   ?? '';
            $category = $row['category'] ?? '';
            $count    = (int)($row['cnt'] ?? 0);
            $bodyPartCounts[] = [
                'svg_id'   => $svgId,
                'name'     => sf_bp_term($svgId, $uiLang),
                'category' => $category,
                'cat_name' => sf_bp_category_term($category, $uiLang),
                'count'    => $count,
            ];
            if ($count > 0) {
                if (!isset($categoryTotals[$category])) {
                    $categoryTotals[$category] = ['name' => sf_bp_category_term($category, $uiLang), 'count' => 0];
                }
                $categoryTotals[$category]['count'] += $count;
            }
        }
    } catch (Throwable $e) {
        // silent fail
    }
}

// ---- 4. Recent flashes with injuries ----
$recentInjuryFlashes = [];
if ($includeRecent) {
    try {
        $sql = "
            SELECT
                f.id,
                grp.parent_id,
                f.type,
                COALESCE(NULLIF(f.title_short, ''), f.title) AS title,
                f.site,
                f.updated_at
            FROM sf_flashes f
            INNER JOIN (
                SELECT
                    COALESCE(translation_group_id, id) AS group_id,
                    MIN(CASE WHEN lang = :ui_lang THEN id END) AS ui_lang_id,
                    MIN(CASE WHEN translation_group_id IS NULL OR translation_group_id = id THEN id END) AS parent_id,
                    MIN(id) AS any_id
                FROM sf_flashes
                WHERE state = 'published'
                GROUP BY COALESCE(translation_group_id, id)
            ) grp ON f.id = COALESCE(grp.ui_lang_id, grp.parent_id, grp.any_id)
            INNER JOIN incident_body_part ibp ON ibp.incident_id = grp.parent_id
            WHERE f.state = 'published'
              $injuryDateFilter
              $injurySiteFilter
            ORDER BY f.updated_at DESC
            LIMIT 30
        ";
        $recentParams = $injuryAllParams;
        $recentParams[':ui_lang'] = $uiLang;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($recentParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $parentIds    = array_values(array_unique(array_map(static fn($r) => (int)$r['parent_id'], $rows)));
            $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
            $bpSql        = "
                SELECT ibp.incident_id, bp.svg_id, bp.category
                FROM   incident_body_part ibp
                INNER JOIN body_parts bp ON bp.id = ibp.body_part_id
                WHERE  ibp.incident_id IN ($placeholders)
            ";
            $bpStmt = $pdo->prepare($bpSql);
            $bpStmt->execute($parentIds);
            $bpMap = [];
            foreach ($bpStmt->fetchAll(PDO::FETCH_ASSOC) as $bpRow) {
                $bpMap[(int)$bpRow['incident_id']][] = sf_bp_term($bpRow['svg_id'], $uiLang);
            }
            foreach ($rows as $row) {
                $fid = (int)$row['id'];
                $parentId = (int)$row['parent_id'];
                $recentInjuryFlashes[] = [
                    'id'         => $fid,
                    'type'       => $row['type']       ?? '',
                    'title'      => $row['title']      ?? '',
                    'site'       => $row['site']       ?? '',
                    'updated_at' => $row['updated_at'] ?? '',
                    'body_parts' => $bpMap[$parentId]  ?? [],
                ];
            }
        }
    } catch (Throwable $e) {
        // silent fail
    }
}

// ---- Period label ----
$periodLabel = '';
if ($startDate !== '' || $endDate !== '') {
    $fmt = static function (string $d): string {
        try {
            return (new DateTime($d))->format('d.m.Y');
        } catch (Throwable $e) {
            return $d;
        }
    };
    if ($startDate !== '' && $endDate !== '') {
        $periodLabel = $fmt($startDate) . ' – ' . $fmt($endDate);
    } elseif ($startDate !== '') {
        $periodLabel = $fmt($startDate) . ' –';
    } else {
        $periodLabel = '– ' . $fmt($endDate);
    }
} else {
    $periodLabel = sf_term('dashboard_report_all_time', $uiLang);
}

// ---- Current user name ----
$reportUser = sf_current_user();
$reportUserName = trim(($reportUser['first_name'] ?? '') . ' ' . ($reportUser['last_name'] ?? ''));
if ($reportUserName === '') {
    $reportUserName = $reportUser['email'] ?? '–';
}

// ---- Load SVG body-map content ----
$appRoot    = dirname(__DIR__, 2);
$bpDir      = $appRoot . '/assets/img/body-map/';

function dashboardReportLoadSvg(string $path, string $requiredViewBox = ''): string
{
    if (!file_exists($path) || !is_readable($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }

    $dom = new DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($dom);
    foreach (['metadata', 'title', 'desc', 'text', 'script', 'style'] as $tagName) {
        $nodes = $xpath->query('//*[local-name()="' . $tagName . '"]');
        if (!$nodes) {
            continue;
        }
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $svgNodes = $xpath->query('/*[local-name()="svg"]');
    if (!$svgNodes || $svgNodes->length === 0) {
        return '';
    }
    $svgRoot = $svgNodes->item(0);
    if (!$svgRoot instanceof DOMElement) {
        return '';
    }

    if (
        $requiredViewBox !== '' &&
        // Validate standard SVG viewBox format: "min-x min-y width height"
        preg_match('/^\s*-?\d+(?:\.\d+)?(?:\s+-?\d+(?:\.\d+)?){3}\s*$/', $requiredViewBox)
    ) {
        $svgRoot->setAttribute('viewBox', trim($requiredViewBox));
        $svgRoot->setAttribute('preserveAspectRatio', 'xMidYMid meet');
    }

    $svgXml = $dom->saveXML($svgRoot);
    return $svgXml !== false ? $svgXml : '';
}

$frontSvgRaw = dashboardReportLoadSvg($bpDir . 'front.svg', '0 0 261.58 620.34');
$backSvgRaw  = dashboardReportLoadSvg($bpDir . 'back.svg', '0 0 261.58 597.52');

function dashboardReportHeatmapColor(float $intensity): string
{
    if ($intensity <= 0) {
        return '#e5e7eb';
    }
    if ($intensity < 0.5) {
        $t = $intensity * 2.0;
        $r = (int)round(254 + (245 - 254) * $t);
        $g = (int)round(243 + (158 - 243) * $t);
        $b = (int)round(199 + (11 - 199) * $t);
    } else {
        $t = ($intensity - 0.5) * 2.0;
        $r = (int)round(245 + (220 - 245) * $t);
        $g = (int)round(158 + (38 - 158) * $t);
        $b = (int)round(11 + (38 - 11) * $t);
    }

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function dashboardReportColorSvg(string $svgRaw, array $bpCountMap, int $maxCount): string
{
    if ($svgRaw === '') {
        return '';
    }
    if ($maxCount <= 0) {
        return $svgRaw;
    }

    return preg_replace_callback(
        '/(<(?:path|ellipse|circle|rect)[^>]*\s)id="(bp-[^"]+)"([^>]*\/?>)/',
        static function (array $m) use ($bpCountMap, $maxCount): string {
            $svgId     = $m[2];
            $count     = $bpCountMap[$svgId] ?? 0;
            $intensity = $count / $maxCount;
            $color     = dashboardReportHeatmapColor((float)$intensity);

            $before = preg_replace('/\s+fill="[^"]*"/', '', $m[1]);
            $after  = preg_replace('/\s+fill="[^"]*"/', '', $m[3]);
            $before = preg_replace('/\s+stroke="[^"]*"/', '', $before);
            $after  = preg_replace('/\s+stroke="[^"]*"/', '', $after);

            return $before . 'id="' . $svgId . '" fill="' . $color . '" stroke="#9ca3af" stroke-width="0.5"' . $after;
        },
        $svgRaw
    );
}

function dashboardReportSvgDataUri(string $svgContent, string $viewBox): string
{
    if ($svgContent === '') {
        return '';
    }

    $safeSvgContent = $svgContent;
    foreach ([
        '/<\s*\/?\s*(script|foreignObject)\b[^>]*>/i',
        '/\son[a-z]+\s*=\s*"[^"]*"/i',
        '/\s(?:xlink:)?href\s*=\s*"javascript:[^"]*"/i',
    ] as $sanitizePattern) {
        $replaced = preg_replace($sanitizePattern, '', $safeSvgContent);
        if (is_string($replaced)) {
            $safeSvgContent = $replaced;
        }
    }

    $fullSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . htmlspecialchars($viewBox, ENT_QUOTES, 'UTF-8') . '" preserveAspectRatio="xMidYMid meet">' . $safeSvgContent . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($fullSvg);
}

$bpCountMap = [];
$maxBpCount = 0;
foreach ($bodyPartCounts as $bp) {
    $svgId = $bp['svg_id'] ?? '';
    if ($svgId === '') {
        continue;
    }
    $count = (int)($bp['count'] ?? 0);
    $bpCountMap[$svgId] = $count;
    if ($count > $maxBpCount) {
        $maxBpCount = $count;
    }
}

$coloredFrontSvg = dashboardReportColorSvg($frontSvgRaw, $bpCountMap, $maxBpCount);
$coloredBackSvg  = dashboardReportColorSvg($backSvgRaw, $bpCountMap, $maxBpCount);

$frontSvgBase64 = dashboardReportSvgDataUri($coloredFrontSvg, '0 0 261.58 620.34');
$backSvgBase64  = dashboardReportSvgDataUri($coloredBackSvg, '0 0 261.58 597.52');

// ---- Configure Dompdf ----
$options = new Options();
$options->set('chroot', $appRoot);
$options->set('isHtml5ParserEnabled', true);
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);

// ---- Render PDF template ----
ob_start();
include __DIR__ . '/../views/pdf_dashboard_report.php';
$html = ob_get_clean();

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdfData = $dompdf->output();

// ---- Audit log ----
try {
    $mysqli = sf_db();
    $userId    = $_SESSION['user_id'] ?? null;
    $userEmail = $_SESSION['sf_user']['email'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $details = json_encode([
        'start_date'          => $startDate ?: null,
        'end_date'            => $endDate   ?: null,
        'site'                => $site      ?: null,
        'include_stats'       => $includeStats,
        'include_worksites'   => $includeWorksites,
        'include_injuries'    => $includeInjuries,
        'include_recent'      => $includeRecent,
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $mysqli->prepare("
        INSERT INTO sf_audit_log
        (user_id, user_email, action, target_type, target_id, details, ip_address, user_agent, log_level, created_at)
        VALUES (?, ?, 'dashboard_report_generated', 'dashboard', 0, ?, ?, ?, 'info', NOW())
    ");
    $stmt->bind_param('issss', $userId, $userEmail, $details, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $auditErr) {
    error_log('Dashboard report audit log error: ' . $auditErr->getMessage());
}

// ---- Stream PDF ----
$filename = 'dashboard-report-' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfData));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $pdfData;
exit;