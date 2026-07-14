<?php
/**
 * PDF Dashboard Report Template
 * Uses SafetyFlash report base layout (A4 portrait, background, margins, footer logic)
 */

// Variables available from generate_dashboard_report.php:
// $uiLang, $originalStats, $worksiteStats, $maxWorksiteCount,
// $bodyPartCounts, $categoryTotals, $recentInjuryFlashes,
// $periodLabel, $reportUserName, $site,
// $frontSvgBase64, $backSvgBase64, $maxBpCount, $appRoot,
// $includeStats, $includeWorksites, $includeInjuries, $includeRecent

$fontDir          = $appRoot . '/assets/fonts';
$fontRegularPath  = $fontDir . '/OpenSans-Regular.ttf';
$fontBoldPath     = $fontDir . '/OpenSans-Bold.ttf';
$bgImagePath = $appRoot . '/assets/img/templates/SF_report_bg.jpg';
$bgBase64 = file_exists($bgImagePath) ? base64_encode(file_get_contents($bgImagePath)) : '';

$generatedAt = (new DateTime())->format('d.m.Y H:i');
$reportTitle = sf_term('dashboard_report_title_pdf', $uiLang);
// Dashboard PDF is intentionally fixed to a 3-page structure.
// Even when a section has no data (or is excluded), its page is still rendered with an empty-state note.
$totalPages  = 3;
$maxBodyPartsDisplay = 3;
$footerSite  = $site !== '' ? $site : sf_term('dashboard_report_all_sites', $uiLang);
$footerMeta  = $periodLabel . ' | ' . $footerSite;

// Sort categories for display
arsort($categoryTotals);
$sortedCategories = array_values($categoryTotals);
$maxCategoryCount = !empty($sortedCategories) ? max(array_column($sortedCategories, 'count')) : 0;

// Type label map using existing terms
$typeLabels = [
    'red'    => sf_term('dashboard_stat_red',    $uiLang),
    'yellow' => sf_term('dashboard_stat_yellow', $uiLang),
    'green'  => sf_term('dashboard_stat_green',  $uiLang),
];

$statsHeaderIconSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <path fill="#ffffff" d="M75.57,92.97c-8.12,4.84-17.72,7.45-27.96,6.98C21.82,98.74.96,77.64.03,51.84-.86,27.05,16.32,6.1,39.42,1.12c2.36-.51,4.58,1.32,4.58,3.73v10.7c0,1.67-1.08,3.19-2.69,3.64-13.25,3.74-23.04,15.84-23.3,30.21-.32,17.64,14.11,32.45,31.75,32.58,5.34.04,10.39-1.23,14.84-3.52,1.65-.85,3.66-.51,4.97.81l6.9,6.91c2,2,1.54,5.33-.89,6.78Z"/>
    <path fill="#ffffff" d="M98.52,62.11c-1.4,5.63-3.75,10.88-6.88,15.57-1.39,2.08-4.34,2.36-6.11.59l-7.3-7.3c-1.26-1.26-1.55-3.23-.64-4.77,1.15-1.95,2.1-4.04,2.82-6.22.54-1.62,2.01-2.75,3.71-2.75h10.53c2.56,0,4.48,2.39,3.87,4.87Z"/>
    <path fill="#ffffff" d="M96.21,45.24h-11.99c-1.46,0-2.71-1.02-3.04-2.45-2.66-11.48-11.52-20.63-22.85-23.68-1.37-.37-2.33-1.59-2.33-3.01V4.01c0-1.98,1.82-3.44,3.76-3.06,20.22,4,36.06,20.19,39.53,40.6.33,1.92-1.12,3.68-3.07,3.68Z"/>
</svg>
SVG;

$worksiteHeaderIconSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <path fill="#ffffff" d="M50,17.59c-12.62,0-22.85,10.23-22.85,22.85,0,.2,0,.39,0,.59.26,14.86,10.56,23.62,22.84,35.9,6.22-6.22,11.93-11.54,16.09-17.21,4.05-5.52,6.62-11.36,6.75-18.69,0-.2,0-.39,0-.59,0-12.62-10.23-22.85-22.85-22.85M50,51.09c-5.88,0-10.65-4.77-10.65-10.65s4.76-10.65,10.65-10.65,10.65,4.77,10.65,10.65-4.77,10.65-10.65,10.65M69.66,74.62c0,6.02-10.13,9.27-19.66,9.27s-19.66-3.25-19.66-9.27c0-3.55,3.53-6.14,8.37-7.66.9.97,1.84,1.94,2.81,2.93-4.79,1.17-7.59,3.19-7.59,4.73,0,2.31,6.26,5.68,16.07,5.68s16.07-3.37,16.07-5.68c0-1.55-2.8-3.57-7.59-4.73.96-.98,1.9-1.96,2.8-2.93,4.85,1.52,8.37,4.11,8.37,7.66M50,0C22.38,0,0,22.39,0,50s22.38,50,50,50,50-22.38,50-50S77.61,0,50,0ZM50,93.51c-24.03,0-43.51-19.48-43.51-43.51S25.97,6.49,50,6.49s43.51,19.48,43.51,43.51-19.49,43.51-43.51,43.51Z"/>
</svg>
SVG;

$injuryHeaderIconSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <path fill="#ffffff" d="M53.79,34.01l-15.84-12.94c3.59-1.94,7.69-3.04,12.05-3.04,8.09,0,15.29,3.79,19.95,9.69-2.96,1.08-7.88,2.98-16.16,6.29ZM83.06,13.69c-18.26-18.25-47.86-18.25-66.12,0C-1.31,31.94-1.31,61.54,16.94,79.8c.69.68,1.39,1.34,2.1,1.96.76.68,1.53,1.32,2.33,1.94l-1.48,8.69c-.68,3.98,2.39,7.61,6.43,7.61h16.57l-.37-2.56-2.26-15.85-1.97-13.8c.96-.51,1.95-.96,2.99-1.34,1.13-.41,2.08-1.29,2.37-2.47.4-1.63-.39-3.25-1.82-3.97-4.57-2.25-7.9-6.16-9.41-10.82-.21-.64-.39-1.3-.52-1.98-2.22.9-4.4,1.79-6.51,2.65,1.28,4.97,4.04,9.42,7.94,12.79-4.04,2.46-7.44,5.78-10.01,9.66h0s0,.02,0,.02c-.71,1.07-1.36,2.17-1.94,3.31-.04-.04-.09-.08-.14-.13-15.88-15.89-15.88-41.64,0-57.53,15.9-15.89,41.64-15.89,57.54,0,15.88,15.89,15.88,41.64,0,57.53-.05.05-.1.09-.14.13-2.67-5.32-6.8-9.85-11.96-12.99,5.51-4.76,8.75-11.68,8.75-19.19,0-2.08-.24-4.12-.74-6.06-.54-2.22-1.37-4.33-2.45-6.29-1.58.58-3.84,1.45-6.58,2.53.72,1.13,1.31,2.34,1.76,3.61.7,1.95,1.08,4.03,1.08,6.21,0,1.99-.32,3.92-.91,5.73-1.51,4.66-4.84,8.57-9.41,10.82-1.43.72-2.22,2.34-1.82,3.97.29,1.18,1.24,2.06,2.37,2.47,6.62,2.44,11.75,7.52,14.4,13.77.3.71.57,1.42.8,2.15.62,1.93,2.99,2.66,4.59,1.43.84-.65,1.65-1.33,2.45-2.04.71-.62,1.41-1.28,2.1-1.96,18.25-18.26,18.25-47.86,0-66.11ZM48.78,36.02l-14.91-12.18c-1.78,1.45-3.36,3.15-4.68,5.04l12.34,10.08c2.62-1.07,5.03-2.04,7.25-2.94ZM25.32,37.4c-.5,1.94-.75,3.98-.75,6.06,0,.8.04,1.59.12,2.37,4.32-1.77,8.25-3.38,11.85-4.84l-9.73-7.95c-.62,1.39-1.12,2.85-1.49,4.36ZM55.56,81.59h-11.42l2.26,15.85h9.16c4.38,0,7.93-3.54,7.93-7.92,0-2.19-.89-4.17-2.32-5.61-1.44-1.43-3.42-2.32-5.61-2.32Z"/>
</svg>
SVG;

$statsIconImg = 'data:image/svg+xml;base64,' . base64_encode($statsHeaderIconSvg);
$worksiteIconImg = 'data:image/svg+xml;base64,' . base64_encode($worksiteHeaderIconSvg);
$injuryIconImg = 'data:image/svg+xml;base64,' . base64_encode($injuryHeaderIconSvg);

$sfIconPath = $appRoot . '/assets/img/icons/safetyflash-icon.svg';
$sfIconImg = '';
if (file_exists($sfIconPath)) {
    $sfIconImg = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($sfIconPath));
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($uiLang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle) ?></title>
    <style>
        <?php if (file_exists($fontRegularPath)): ?>
        @font-face {
            font-family: 'Open Sans';
            src: url("file://<?= str_replace('\\', '/', $fontRegularPath) ?>") format('truetype');
            font-weight: normal;
        }
        <?php endif; ?>
        <?php if (file_exists($fontBoldPath)): ?>
        @font-face {
            font-family: 'Open Sans';
            src: url("file://<?= str_replace('\\', '/', $fontBoldPath) ?>") format('truetype');
            font-weight: bold;
        }
        <?php endif; ?>

        @page { size: A4; margin: 25mm 18mm 18mm 18mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            color: #1a1a1a;
            padding: 0;
            margin: 0;
            background-image: url("data:image/jpeg;base64,<?php echo $bgBase64; ?>");
            background-position: top left;
            background-repeat: no-repeat;
            background-size: 100% 100%;
        }
        
        .page-break { page-break-before: always; }

        .page-content {
            padding-top: 25mm;
            padding-bottom: 12mm;
            padding-left: 8mm;
            padding-right: 8mm;
        }

        .report-header {
            margin-bottom: 16px;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 6px;
            padding: 10px 12px;
        }
        .report-header table { width: 100%; border-collapse: collapse; }
        .report-header td { vertical-align: middle; }
        .header-logo-cell { width: 110px; }
        .header-logo { max-width: 98px; max-height: 34px; }
        .header-title-cell { padding-left: 0px; }
        .header-report-title {
            font-size: 17pt;
            font-weight: bold;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1;
        }
           .title-icon {
            display: inline-block;
            width: 60px;
            height: 60px;
            vertical-align: middle;
            margin-right: 10px;
            margin-bottom: -18px;
        }
        .header-meta-cell {
            text-align: right;
            font-size: 8pt;
            color: #355e3b;
        }

        .period-banner {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 9px 12px;
            margin-bottom: 16px;
            font-size: 8.5pt;
            color: #1f2937;
        }
        .period-banner strong { color: #009650; }

        .section {
            margin-bottom: 16px;
            background: rgba(255, 255, 255, 0.84);
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 12px 12px 12px;
        }
        .section-header {
            font-size: 12pt;
            font-weight: bold;
            padding: 5px 5px 10px 12px;
            background: #000;
            color: #ffffff;
            border-radius: 5px;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
            /* Flexbox tuki ei ole täydellinen dompdf:ssä, käytetään vertical-alignia */
        }
        .section-icon {
            display: inline-block;
            width: 27px;          /* Kasvatettu koko! */
            height: 27px;         /* Kasvatettu koko! */
            vertical-align: text-bottom;  /* Parempi kohdistus tekstin kanssa dompdf:ssä */
            margin-right: 10px;   /* Hieman enemmän ilmaa ikonin ja tekstin väliin */
            margin-bottom: 2px;  /* Laskee ikonia hieman alaspäin, jotta asettuu samalle linjalle tekstin kanssa */
        }

        .stats-table { width: 100%; border-collapse: collapse; }
        .stats-table td { width: 33.33%; padding: 0 6px; vertical-align: top; }
        .stats-table td:first-child { padding-left: 0; }
        .stats-table td:last-child { padding-right: 0; }

        .stat-box {
            border-radius: 8px;
            padding: 10px 10px;
            text-align: center;
            border-width: 2px;
            border-style: solid;
        }
        .stat-box--red    { background: #fff1f2; border-color: #dc2626; }
        .stat-box--yellow { background: #fffbeb; border-color: #f59e0b; }
        .stat-box--total  { background: #eff6ff; border-color: #2563eb; }

        .stat-label {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 5px;
        }
        .stat-box--red .stat-label { color: #b91c1c; }
        .stat-box--yellow .stat-label { color: #92400e; }
        .stat-box--total .stat-label { color: #1e40af; }

        .stat-count {
            font-size: 23pt;
            font-weight: bold;
            line-height: 1;
        }
        .stat-box--red .stat-count { color: #dc2626; }
        .stat-box--yellow .stat-count { color: #d97706; }
        .stat-box--total .stat-count { color: #2563eb; }

        .worksite-table { width: 100%; border-collapse: collapse; }
        .worksite-table td { padding: 4px 0; vertical-align: middle; }
        .worksite-name {
            font-size: 8.5pt;
            color: #374151;
            font-weight: 600;
            width: 34%;
            padding-right: 8px;
        }
        .bar-track {
            width: 100%;
            border-collapse: collapse;
            background: #e5e7eb;
            border-radius: 4px;
        }
        .bar-fill {
            border-radius: 4px;
            padding: 2px 8px; /* Palautetaan ylä- ja alapadding normaaliksi */
            text-indent: 8px; /* Tämä siirtää lukua oikealle! Kokeile esim. 8px tai 12px */
            color: #fff;
            font-weight: bold;
            font-size: 7.5pt;
        }

        .injury-layout-table { width: 100%; border-collapse: collapse; }
        .injury-svg-cell { width: 48%; vertical-align: top; padding-right: 10px; }
        .injury-chart-cell { width: 52%; vertical-align: top; }

        .body-map-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .body-map-table td { width: 50%; vertical-align: top; text-align: center; padding: 0 4px; }
        .body-map-svg img { width: 118px; height: auto; display: block; margin: 0 auto; }
        .body-map-label { font-size: 7.5pt; color: #6b7280; text-align: center; margin-top: 4px; }

        .heatmap-legend { margin-top: 10px; font-size: 7.5pt; color: #6b7280; }
        .legend-bar { height: 8px; border-radius: 4px; margin-bottom: 3px; background: #f59e0b; }
        .legend-table { width: 100%; border-collapse: collapse; }
        .legend-table td:last-child { text-align: right; }

        .cat-table { width: 100%; border-collapse: collapse; }
        .cat-table td { padding: 3px 0; vertical-align: middle; }
        .cat-name { font-size: 8.5pt; color: #374151; font-weight: 600; width: 46%; padding-right: 6px; }

        .injuries-table { width: 100%; border-collapse: collapse; }
        .injuries-table th {
            background: #f3f4f6;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            padding: 6px 10px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        .injuries-table td {
            font-size: 8.5pt;
            color: #374151;
            padding: 6px 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .injuries-table tr:nth-child(even) td { background: rgba(255, 255, 255, 0.65); }

        .type-pill {
            display: inline-table;
            border-radius: 3px;
            padding: 2px 6px;
            font-size: 7pt;
            font-weight: bold;
            color: #fff;
        }
        .type-pill--red { background: #dc2626; }
        .type-pill--yellow { background: #d97706; }
        .type-pill--green { background: #059669; }

        .empty-note {
            font-size: 8.5pt;
            color: #6b7280;
            font-style: italic;
            padding: 8px 0;
        }

        .footer {
            position: fixed;
            bottom: -18mm;
            left: -18mm;
            right: -18mm;
            height: 12mm;
            padding: 0 18mm;
            font-size: 8pt;
            color: #666;
        }
        .footer table { width: 100%; height: 100%; border-collapse: collapse; }
        .footer td { padding: 0; vertical-align: middle; }
        .footer-left { text-align: left; font-weight: bold; }
        .footer-center { text-align: center; color: #888; }
        .footer-right { text-align: right; }
    </style>
</head>
<body>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left">1 / <?= $totalPages ?></td>
            <td class="footer-center"><?= htmlspecialchars($footerMeta) ?></td>
            <td class="footer-right"><?= htmlspecialchars(sf_term('dashboard_report_generated_by', $uiLang)) ?>: <?= htmlspecialchars($reportUserName) ?></td>
        </tr>
    </table>
</div>

<div class="page-content">
    <div class="report-header">
        <table>
            <tr>

                <td class="header-title-cell">
                    <div class="header-report-title">
                        <?php if ($sfIconImg !== ''): ?>
                            <img src="<?= $sfIconImg ?>" class="title-icon" alt="">
                        <?php endif; ?>
                        <?= htmlspecialchars($reportTitle) ?>
                    </div>
                    <!-- Omitted subtitle: previous text was a hardcoded Finnish duplicate of the localized report title. -->
                </td>
                <td class="header-meta-cell">
                    <div><?= htmlspecialchars(sf_term('dashboard_report_generated_at', $uiLang)) ?></div>
                    <div><?= htmlspecialchars($generatedAt) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="period-banner">
        <strong><?= htmlspecialchars(sf_term('dashboard_report_period_label', $uiLang)) ?>:</strong>
        <?= htmlspecialchars($periodLabel) ?>
        <?php if ($site !== ''): ?>
            &nbsp;·&nbsp;
            <strong><?= htmlspecialchars(sf_term('dashboard_report_site_filter', $uiLang)) ?>:</strong>
            <?= htmlspecialchars($site) ?>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-header">
            <img src="<?= $statsIconImg ?>" class="section-icon" alt="">
            <?= htmlspecialchars(sf_term('dashboard_report_include_stats', $uiLang)) ?>
        </div>
        <?php if (!$includeStats): ?>
            <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang)) ?></p>
        <?php else: ?>
            <table class="stats-table">
                <tr>
                    <td>
                        <div class="stat-box stat-box--red">
                            <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_red', $uiLang)) ?></div>
                            <div class="stat-count"><?= (int)($originalStats['red'] ?? 0) ?></div>
                        </div>
                    </td>
                    <td>
                        <div class="stat-box stat-box--yellow">
                            <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_yellow', $uiLang)) ?></div>
                            <div class="stat-count"><?= (int)($originalStats['yellow'] ?? 0) ?></div>
                        </div>
                    </td>
                    <td>
                        <div class="stat-box stat-box--total">
                            <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_total', $uiLang)) ?></div>
                            <div class="stat-count"><?= (int)($originalStats['total'] ?? 0) ?></div>
                        </div>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-header">
            <img src="<?= $worksiteIconImg ?>" class="section-icon" alt="">
            <?= htmlspecialchars(sf_term('dashboard_report_include_worksites', $uiLang)) ?>
        </div>
        <?php if (!$includeWorksites || empty($worksiteStats)): ?>
            <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang)) ?></p>
        <?php else: ?>
            <table class="worksite-table">
                <?php foreach ($worksiteStats as $idx => $ws):
                    $wsName  = htmlspecialchars($ws['site'] ?? '');
                    $wsCount = (int)($ws['count'] ?? 0);
                    $barPct  = $maxWorksiteCount > 0 ? round(($wsCount / $maxWorksiteCount) * 100) : 0;
                    $restPct = max(0, 100 - $barPct);
                    $barColor = '#6b7280';
                    if ($idx < 3) {
                        $barColor = '#2563eb';
                    } elseif ($idx < 6) {
                        $barColor = '#7c3aed';
                    }
                ?>
                <tr>
                    <td class="worksite-name"><?= $wsName ?></td>
                    <td>
                        <table class="bar-track">
                            <tr>
                                <td class="bar-fill" style="width:<?= $barPct ?>%; background:<?= $barColor ?>;"><?= $wsCount ?></td>
                                <td style="width:<?= $restPct ?>%;">&nbsp;</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="page-break"></div>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left">2 / <?= $totalPages ?></td>
            <td class="footer-center"><?= htmlspecialchars($footerMeta) ?></td>
            <td class="footer-right"><?= htmlspecialchars(sf_term('dashboard_report_generated_by', $uiLang)) ?>: <?= htmlspecialchars($reportUserName) ?></td>
        </tr>
    </table>
</div>

<div class="page-content">
    <div class="section">
        <div class="section-header">
            <img src="<?= $injuryIconImg ?>" class="section-icon" alt="">
            <?= htmlspecialchars(sf_term('dashboard_report_include_injuries', $uiLang)) ?>
        </div>
        <?php if (!$includeInjuries): ?>
            <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_injury_empty', $uiLang)) ?></p>
        <?php else: ?>
            <?php $hasInjuryData = !empty($bodyPartCounts) && array_sum(array_column($bodyPartCounts, 'count')) > 0; ?>
            <?php if (!$hasInjuryData): ?>
                <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_injury_empty', $uiLang)) ?></p>
            <?php else: ?>
                <table class="injury-layout-table">
                    <tr>
                        <td class="injury-svg-cell">
                            <table class="body-map-table">
                                <tr>
                                    <td>
                                        <?php if ($frontSvgBase64 !== ''): ?>
                                            <div class="body-map-svg"><img src="<?= htmlspecialchars($frontSvgBase64) ?>" alt="<?= htmlspecialchars(sf_term('body_map_front_label', $uiLang)) ?>"></div>
                                            <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_front_label', $uiLang)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($backSvgBase64 !== ''): ?>
                                            <div class="body-map-svg"><img src="<?= htmlspecialchars($backSvgBase64) ?>" alt="<?= htmlspecialchars(sf_term('body_map_back_label', $uiLang)) ?>"></div>
                                            <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_back_label', $uiLang)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                            <div class="heatmap-legend">
                                <div class="legend-bar"></div>
                                <table class="legend-table"><tr><td>0</td><td><?= $maxBpCount ?></td></tr></table>
                            </div>
                        </td>
                        <td class="injury-chart-cell">
                            <p style="font-size:8.5pt; font-weight:bold; color:#374151; margin-bottom:10px;">
                                <?= htmlspecialchars(sf_term('dashboard_injury_chart_title', $uiLang)) ?>
                            </p>
                            <?php if (empty($sortedCategories)): ?>
                                <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang)) ?></p>
                            <?php else: ?>
                                <table class="cat-table">
                                    <?php foreach ($sortedCategories as $cat):
                                        $catPct = $maxCategoryCount > 0 ? round(($cat['count'] / $maxCategoryCount) * 100) : 0;
                                        $catRestPct = max(0, 100 - $catPct);
                                    ?>
                                    <tr>
                                        <td class="cat-name"><?= htmlspecialchars($cat['name']) ?></td>
                                        <td>
                                            <table class="bar-track">
                                                <tr>
                                                    <td class="bar-fill" style="width:<?= $catPct ?>%; background:#059669;"><?= (int)$cat['count'] ?></td>
                                                    <td style="width:<?= $catRestPct ?>%;">&nbsp;</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="page-break"></div>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left">3 / <?= $totalPages ?></td>
            <td class="footer-center"><?= htmlspecialchars($footerMeta) ?></td>
            <td class="footer-right"><?= htmlspecialchars(sf_term('dashboard_report_generated_by', $uiLang)) ?>: <?= htmlspecialchars($reportUserName) ?></td>
        </tr>
    </table>
</div>

<div class="page-content">
    <div class="section">
        <div class="section-header">
            <img src="<?= $injuryIconImg ?>" class="section-icon" alt="">
            <?= htmlspecialchars(sf_term('dashboard_report_include_recent', $uiLang)) ?>
        </div>
        <?php if (!$includeRecent || empty($recentInjuryFlashes)): ?>
            <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_injury_empty', $uiLang)) ?></p>
        <?php else: ?>
            <table class="injuries-table">
                <thead>
                <tr>
                    <th style="width:8%;"><?= htmlspecialchars(sf_term('dashboard_stat_red', $uiLang)) ?>/<?= htmlspecialchars(sf_term('dashboard_stat_yellow', $uiLang)) ?></th>
                    <th style="width:42%;"><?= htmlspecialchars(sf_term('dashboard_by_worksite', $uiLang)) ?> / <?= htmlspecialchars(sf_term('dashboard_recent', $uiLang)) ?></th>
                    <th style="width:25%;"><?= htmlspecialchars(sf_term('dashboard_report_site_filter', $uiLang)) ?></th>
                    <th style="width:25%;"><?= htmlspecialchars(sf_term('dashboard_injury_chart_title', $uiLang)) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentInjuryFlashes as $flash):
                    $flashType  = $flash['type'] ?? '';
                    $flashTitle = $flash['title'] ?? '';
                    $flashSite  = $flash['site'] ?? '';
                    $flashDate  = '';
                    if (!empty($flash['updated_at'])) {
                        try {
                            $flashDate = (new DateTime($flash['updated_at']))->format('d.m.Y');
                        } catch (Throwable $e) {
                            error_log('Dashboard PDF date parse failed: ' . $e->getMessage());
                        }
                    }
                    $bpList = implode(', ', array_slice($flash['body_parts'] ?? [], 0, $maxBodyPartsDisplay));
                    if (count($flash['body_parts'] ?? []) > $maxBodyPartsDisplay) {
                        $bpList .= '…';
                    }
                    $typeLbl = $typeLabels[$flashType] ?? $flashType;
                    ?>
                    <tr>
                        <td>
                            <span class="type-pill type-pill--<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($typeLbl) ?></span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($flashTitle) ?></strong>
                            <?php if ($flashDate): ?>
                                <br><span style="font-size:7.5pt; color:#9ca3af;"><?= htmlspecialchars($flashDate) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($flashSite) ?></td>
                        <td style="font-size:7.5pt; color:#6b7280;"><?= htmlspecialchars($bpList) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</body>
</html>