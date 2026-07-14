<?php
/**
 * PDF Report Template - Tutkintatiedote
 * Modern & Clean Design
 */
if (!function_exists('validateSafePath')) {
    function validateSafePath(string $path, string $baseDir): bool {
        $realPath = realpath($path);
        $realBase = realpath($baseDir);
        if ($realPath === false || $realBase === false) return false;
        return strpos($realPath, $realBase) === 0 && file_exists($realPath);
    }
}

$uploadsDir = dirname(__DIR__, 2) . '/uploads';
$flashId = (int)($flash['id'] ?? 0);

$extraImages = [];
if ($flashId > 0 && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT filename, original_filename, caption FROM sf_flash_images WHERE flash_id = ? ORDER BY created_at ASC");
        $stmt->execute([$flashId]);
        $extraImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$bgImagePath = dirname(__DIR__, 2) . '/assets/img/templates/SF_report_bg.jpg';
$bgBase64 = file_exists($bgImagePath) ? base64_encode(file_get_contents($bgImagePath)) : '';

// SVG icon - read and embed as base64
$iconPath = dirname(__DIR__, 2) . '/assets/img/icons/type-green.svg';
$iconDataUri = '';
if (file_exists($iconPath)) {
    $iconDataUri = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($iconPath));
}

$fontDir = dirname(__DIR__, 2) . '/assets/fonts';
$fontRegularPath = $fontDir . '/OpenSans-Regular.ttf';
$fontBoldPath = $fontDir . '/OpenSans-Bold.ttf';

$labels = [
    'fi' => ['report_title' => 'Tutkintatiedote', 'site' => 'Työmaa', 'date' => 'Tapahtumapäivä', 'short_description' => 'Tiivistelmä', 'description' => 'Tapahtumakuvaus', 'images' => 'Kuvat', 'root_causes' => 'Juurisyyanalyysi', 'actions' => 'Korjaavat toimenpiteet', 'author' => 'Laatija', 'approver' => 'Hyväksyjä', 'safetyflash_card' => 'SafetyFlash-kortti', 'original_flash' => 'Alkuperäinen SafetyFlash', 'additional_info_pdf_section' => 'Lisätiedot tapahtumasta'],
    'sv' => ['report_title' => 'Undersökningsrapport', 'site' => 'Arbetsplats', 'date' => 'Händelsedatum', 'short_description' => 'Sammanfattning', 'description' => 'Händelsebeskrivning', 'images' => 'Bilder', 'root_causes' => 'Grundorsaksanalys', 'actions' => 'Korrigerande åtgärder', 'author' => 'Författare', 'approver' => 'Godkännare', 'safetyflash_card' => 'SafetyFlash-kort', 'original_flash' => 'Ursprunglig SafetyFlash', 'additional_info_pdf_section' => 'Ytterligare information om händelsen'],
    'en' => ['report_title' => 'Investigation Report', 'site' => 'Worksite', 'date' => 'Incident Date', 'short_description' => 'Executive Summary', 'description' => 'Incident Description', 'images' => 'Images', 'root_causes' => 'Root Cause Analysis', 'actions' => 'Corrective Actions', 'author' => 'Author', 'approver' => 'Approved by', 'safetyflash_card' => 'SafetyFlash Card', 'original_flash' => 'Original SafetyFlash', 'additional_info_pdf_section' => 'Additional information about the event'],
];
$lang = $flash['lang'] ?? 'fi';
$l = $labels[$lang] ?? $labels['fi'];

$gridBitmap = trim((string)($flash['grid_bitmap'] ?? ''));
$imageMain = trim((string)($flash['image_main'] ?? ''));
$image2 = trim((string)($flash['image_2'] ?? ''));
$image3 = trim((string)($flash['image_3'] ?? ''));

// Get captions for main images if they exist
$mainImageCaptions = [];
if ($flashId > 0 && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT image1_caption, image2_caption, image3_caption FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $captions = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($captions) {
            $mainImageCaptions = [
                1 => trim((string)($captions['image1_caption'] ?? '')),
                2 => trim((string)($captions['image2_caption'] ?? '')),
                3 => trim((string)($captions['image3_caption'] ?? '')),
            ];
        }
    } catch (Throwable $e) {}
}

function getImagePath($filename, $uploadsDir) {
    if (empty($filename)) return null;
    foreach (['/images/', '/edited/', '/'] as $sub) {
        $path = $uploadsDir . $sub . basename($filename);
        if (file_exists($path)) return $path;
    }
    return null;
}

// Build unified image array with captions
$allImages = [];

// Add main images
$mainImg = getImagePath($imageMain, $uploadsDir);
if ($mainImg) $allImages[] = ['path' => $mainImg, 'caption' => $mainImageCaptions[1] ?? ''];

$img2 = getImagePath($image2, $uploadsDir);
if ($img2) $allImages[] = ['path' => $img2, 'caption' => $mainImageCaptions[2] ?? ''];

$img3 = getImagePath($image3, $uploadsDir);
if ($img3) $allImages[] = ['path' => $img3, 'caption' => $mainImageCaptions[3] ?? ''];

// Add extra images
foreach ($extraImages as $ei) {
    $p = $uploadsDir . '/extra_images/' . basename($ei['filename']);
    if (file_exists($p)) {
        $allImages[] = ['path' => $p, 'caption' => trim((string)($ei['caption'] ?? ''))];
    }
}

$hasAnyImages = !empty($allImages);

// SafetyFlash card preview images (generated 1920x1080 cards)
$previewCard1Path = null;
$previewCard2Path = null;

if (!empty($flash['preview_filename'])) {
    $candidatePath = $uploadsDir . '/previews/' . basename($flash['preview_filename']);
    if (file_exists($candidatePath)) {
        $previewCard1Path = $candidatePath;
    }
}

if (!empty($flash['preview_filename_2'])) {
    $candidatePath = $uploadsDir . '/previews/' . basename($flash['preview_filename_2']);
    if (file_exists($candidatePath)) {
        $previewCard2Path = $candidatePath;
    }
}

$hasPreviewCards = ($previewCard1Path !== null);

// Check for original flash preview (display_snapshot_preview)
$originalPreviewPath = null;
if (!empty($flash['display_snapshot_preview'])) {
    $candidatePath = $uploadsDir . '/previews/' . basename($flash['display_snapshot_preview']);
    if (file_exists($candidatePath)) {
        $originalPreviewPath = $candidatePath;
    }
}

// Fallback: if display_snapshot_preview is empty, try sf_flash_snapshots table
if ($originalPreviewPath === null && isset($pdo)) {
    $logFlashId = $flashId;
    if (!empty($flash['translation_group_id'])) {
        $logFlashId = (int)$flash['translation_group_id'];
    }

    // Look for the original flash snapshot (ensitiedote or vaaratilanne = original type before investigation)
    try {
        $stmtSnap = $pdo->prepare("
            SELECT image_path FROM sf_flash_snapshots 
            WHERE flash_id = ? AND version_type IN ('ensitiedote', 'vaaratilanne')
            ORDER BY published_at ASC 
            LIMIT 1
        ");
        $stmtSnap->execute([$logFlashId]);
        $snapRow = $stmtSnap->fetch(PDO::FETCH_ASSOC);

        if ($snapRow && !empty($snapRow['image_path'])) {
            $snapFullPath = dirname(__DIR__, 2) . $snapRow['image_path'];
            if (file_exists($snapFullPath)) {
                $originalPreviewPath = $snapFullPath;
            }
        }
    } catch (Throwable $e) {
        error_log('PDF Report - Snapshot fallback query error: ' . $e->getMessage());
    }
}

// Calculate total pages
$totalPages = 2; // Cover + Content always
if ($hasPreviewCards) $totalPages++; // SafetyFlash card + original flash page
if ($hasAnyImages) $totalPages++;    // Uploaded images page

// Footer info
$siteInfo = trim((string)($flash['site'] ?? ''));
$siteDetail = trim((string)($flash['site_detail'] ?? ''));
$footerSite = !empty($siteInfo) ? $siteInfo . (!empty($siteDetail) ? ' – ' . $siteDetail : '') : '–';
$footerDate = !empty($flash['occurred_at']) ? (new DateTime($flash['occurred_at']))->format('d.m.Y') : '–';
$footerDateTime = !empty($flash['occurred_at']) ? (new DateTime($flash['occurred_at']))->format('d.m.Y H:i') : '–';

// Hae raportin laatija ja hyväksyjä lokista
// TÄRKEÄ: Käytä alkuperäisen flashin ID:tä (translation_group_id) jos tämä on kieliversio
$authorName = '–';
$approverName = '–';

if (isset($pdo)) {
    // Määritä oikea flash ID lokihakua varten
    // Jos on kieliversio, käytä translation_group_id (alkuperäinen flash)
    $logFlashId = $flashId;
    if (!empty($flash['translation_group_id']) && (int)$flash['translation_group_id'] !== $flashId) {
        $logFlashId = (int)$flash['translation_group_id'];
    }
    
    // Raportin laatija = se joka loi tutkintatiedotteen (investigation_created tai created)
    try {
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name 
            FROM safetyflash_logs l
            JOIN sf_users u ON l.user_id = u.id
            WHERE l.flash_id = ? AND l.event_type IN ('investigation_created', 'created')
            ORDER BY l.created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$logFlashId]);
        $author = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($author) {
            $authorName = trim($author['first_name'] . ' ' . $author['last_name']);
        }
    } catch (Throwable $e) {
        error_log('PDF Report - Author query error: ' . $e->getMessage());
    }
    
    // Hyväksyjä = se joka hyväksyi tai lähetti viestintään
    try {
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name 
            FROM safetyflash_logs l
            JOIN sf_users u ON l.user_id = u.id
            WHERE l.flash_id = ? AND l.event_type IN ('supervisor_approved', 'sent_to_comms')
            ORDER BY l.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$logFlashId]);
        $approver = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($approver) {
            $approverName = trim($approver['first_name'] . ' ' . $approver['last_name']);
        }
    } catch (Throwable $e) {
        error_log('PDF Report - Approver query error: ' . $e->getMessage());
    }
}
$footerDateTime = !empty($flash['occurred_at']) ? (new DateTime($flash['occurred_at']))->format('d.m.Y H:i') : '–';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title>SafetyFlash Report</title>
    <style>
        <?php if (file_exists($fontRegularPath)): ?>
        @font-face { font-family: 'Open Sans'; src: url("file://<?= str_replace('\\', '/', $fontRegularPath) ?>") format('truetype'); font-weight: normal; }
        <?php endif; ?>
        <?php if (file_exists($fontBoldPath)): ?>
        @font-face { font-family: 'Open Sans'; src: url("file://<?= str_replace('\\', '/', $fontBoldPath) ?>") format('truetype'); font-weight: bold; }
        <?php endif; ?>
        
        @page { size: A4; margin: 25mm 18mm 18mm 18mm; }
        
        body { 
            font-family: 'Open Sans', 'DejaVu Sans', sans-serif; 
            font-size: 10pt; 
            line-height: 1.3; 
            color: #1a1a1a; 
            margin: 0; 
            padding: 0; 
        }
        
        .page-break { page-break-before: always; }
        
        .bg-img { position: fixed; top: -25mm; left: -18mm; width: 210mm; height: 297mm; z-index: -1000; }
        
        /* Header */
        .report-header { 
            margin-bottom: 20px; 
            padding: 0px 0px;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
        }
        .report-header table { width: 100%; }
        .report-header td { vertical-align: middle; }
        .header-icon { width: 70px; padding-right: 15px; }
        .header-icon svg { 
            width: 60px; 
            height: 60px; 
        }
        .header-text { }
        .report-type { 
            font-size: 22pt; 
            font-weight: bold; 
            color: #009650; 
            text-transform: uppercase; 
            letter-spacing: 2px;
            margin: 0;
        }
        .report-id {
            font-size: 9pt;
            color: #666;
            margin-top: 4px;
        }
        
        /* Title Section */
        .title-section {
            margin: 15px 0;
        }
        .title { 
            font-size: 16pt; 
            font-weight: bold; 
            margin: 0 0 12px 0; 
            color: #1a1a1a; 
            line-height: 1.2; 
        }
        
        /* Short description - bold and larger */
        .short-description {
            font-size: 14pt;
            font-weight: bold;
            color: #333;
            line-height: 1.2;
            margin-bottom: 15px;

        }
        
        /* Meta Cards */
        .meta-cards { width: 100%; margin: 15px 0; }
        .meta-cards td { width: 50%; padding: 0 5px; vertical-align: top; }
        .meta-cards td:first-child { padding-left: 0; }
        .meta-cards td:last-child { padding-right: 0; }
        .meta-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
        }
        .meta-label { 
            font-size: 8pt; 
            font-weight: bold; 
            color: #009650; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .meta-value { 
            font-size: 10pt; 
            color: #1a1a1a; 
            font-weight: 600;
        }
        
        /* Sections */
        .section { margin: 18px 0; }
        .section-header { 
            font-size: 11pt; 
            font-weight: bold; 
            margin-bottom: 10px; 
            padding: 8px 12px;
            background: #000;
            color: #ffffff;
            border-radius: 5px;
            letter-spacing: 0.5px;
        }
        .section-content { padding: 0 2px; }
        .description { 
            text-align: justify; 
            margin: 0; 
            line-height: 1.2;
            color: #333;
        }
        .content-box { 
            background: #f8f9fa;
            border-left: 4px solid #009650;
            padding: 12px 14px; 
            margin: 8px 0; 
            border-radius: 0 6px 6px 0;
            line-height: 1.2;
            color: #333;
        }
        
        /* Grid Bitmap */
        .grid-bitmap-container { margin: 15px 0; text-align: center; }
        .grid-bitmap-image { 
            max-width: 100%; 
            max-height: 130mm;
            height: auto; 
            display: block; 
            margin: 0 auto;
            border-radius: 5px;
        }
        
        /* Images - 2 column flow */
        .images-table { 
            width: 100%; 
            border-collapse: separate;
            border-spacing: 8px;
            margin: 10px 0; 
        }
        .images-table td { 
            width: 50%; 
            padding: 0;
            vertical-align: top; 
        }
        .image-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 8px;
            border: 1px solid #e0e0e0;
            margin-bottom: 5px;
        }
        .image-card img { 
            width: 100%;
            height: auto; 
            display: block; 
            border-radius: 4px;
        }
        .image-caption {
            font-size: 8pt;
            color: #666;
            text-align: center;
            padding: 6px 4px 2px 4px;
            line-height: 1.25;
            font-style: italic;
        }
        
        /* Footer */
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
        .footer table {
            width: 100%;
            height: 100%;
        }
        .footer td {
            vertical-align: middle;
            padding: 0;
        }
        .footer-left {
            text-align: left;
            font-weight: bold;
        }
        .footer-center {
            text-align: center;
            color: #888;
        }
        .footer-right {
            text-align: right;
        }
        .footer-brand {
            color: #009650;
            font-weight: bold;
        }
    </style>
</head>
<body>

<?php if (!empty($bgBase64)): ?>
<img src="data:image/jpeg;base64,<?= $bgBase64 ?>" class="bg-img" alt="">
<?php endif; ?>

<!-- Footer for Page 1 -->
<div class="footer">
    <table>
        <tr>
            <td class="footer-left">1 / <?= $totalPages ?></td>
            <td class="footer-center">ID: <?= $flashId ?> | <?= htmlspecialchars($footerSite) ?> | <?= $footerDate ?></td>
            <td class="footer-right"><span class="footer-brand"></span></td>
        </tr>
    </table>
</div>

<!-- PAGE 1: Cover -->
<div class="report-header">
    <table>
        <tr>
            <?php if (!empty($iconDataUri)): ?>
            <td class="header-icon">
                <img src="<?= $iconDataUri ?>" alt="" width="60" height="60">
            </td>
            <?php endif; ?>
            <td class="header-text">
                <div class="report-type"><?= htmlspecialchars($l['report_title']) ?></div>
                <div class="report-id">ID: <?= htmlspecialchars($flashId) ?> | <?= date('d.m.Y H:i') ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="title-section">
    <div class="title"><?= htmlspecialchars($flash['title'] ?? 'Untitled') ?></div>
    
    
    <table class="meta-cards">
        <tr>
            <td>
                <div class="meta-card">
                    <div class="meta-label"><?= htmlspecialchars($l['site']) ?></div>
                    <div class="meta-value"><?= htmlspecialchars($footerSite) ?></div>
                </div>
            </td>
            <td>
                <div class="meta-card">
                    <div class="meta-label"><?= htmlspecialchars($l['date']) ?></div>
                    <div class="meta-value"><?= $footerDateTime ?></div>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="meta-card">
                    <div class="meta-label"><?= htmlspecialchars($l['author']) ?></div>
                    <div class="meta-value"><?= htmlspecialchars($authorName) ?></div>
                </div>
            </td>
            <td>
                <div class="meta-card">
                    <div class="meta-label"><?= htmlspecialchars($l['approver']) ?></div>
                    <div class="meta-value"><?= htmlspecialchars($approverName) ?></div>
                </div>
            </td>
        </tr>
    </table>
</div>

<?php
if (!empty($gridBitmap)):
    if (strpos($gridBitmap, 'data:image/') === 0) {
        $gridPath = $gridBitmap;
    } else {
        $safeBasename = basename($gridBitmap);
        $gridPath = (preg_match('/^[a-zA-Z0-9_.-]+\.(png|jpg|jpeg|gif|webp)$/i', $safeBasename) && file_exists($uploadsDir . '/grids/' . $safeBasename))
            ? $uploadsDir . '/grids/' . $safeBasename : null;
    }
    if ($gridPath):
?>
<div class="grid-bitmap-container">
    <img src="<?= htmlspecialchars($gridPath) ?>" class="grid-bitmap-image" alt="">
</div>
<?php endif; endif; ?>

<?php if ($hasPreviewCards): ?>
<!-- PAGE: SafetyFlash Card(s) + Original Flash -->
<div class="page-break"></div>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left">2 / <?= $totalPages ?></td>
            <td class="footer-center">ID: <?= $flashId ?> | <?= htmlspecialchars($footerSite) ?> | <?= $footerDate ?></td>
            <td class="footer-right"><span class="footer-brand"></span></td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-header"><?= htmlspecialchars($l['safetyflash_card']) ?></div>
    <div class="section-content" style="text-align: center; padding: 5px 0;">
        <!-- Tutkintatiedotteen kortti (Card 1) -->
        <img src="<?= htmlspecialchars($previewCard1Path) ?>"
             style="max-width: 100%; height: auto; display: block; margin: 0 auto; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);"
             alt="SafetyFlash Card">
    </div>
</div>

<?php if ($originalPreviewPath): ?>
<div class="section" style="margin-top: 8px;">
    <div class="section-header"><?= htmlspecialchars($l['original_flash']) ?></div>
    <div class="section-content" style="text-align: center; padding: 5px 0;">
        <img src="<?= htmlspecialchars($originalPreviewPath) ?>"
             style="max-width: 100%; height: auto; display: block; margin: 0 auto; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);"
             alt="Original SafetyFlash">
        <?php
        $originalTypeLabels = [
            'red' => ['fi' => 'Ensitiedote', 'sv' => 'Första meddelande', 'en' => 'First Release'],
            'yellow' => ['fi' => 'Vaaratilanne', 'sv' => 'Farlig situation', 'en' => 'Dangerous Situation'],
        ];
        $origType = $flash['original_type'] ?? '';
        $origLabel = $originalTypeLabels[$origType][$lang] ?? '';
        if ($origLabel): ?>
        <div style="margin-top: 6px; font-size: 9pt; color: #666;">
            <?= htmlspecialchars($origLabel) ?> → <?= htmlspecialchars($l['report_title']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php $contentPageNum = 2 + ($hasPreviewCards ? 1 : 0); ?>
<!-- PAGE: Content -->
<div class="page-break"></div>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left"><?= $contentPageNum ?> / <?= $totalPages ?></td>
            <td class="footer-center">ID: <?= $flashId ?> | <?= htmlspecialchars($footerSite) ?> | <?= $footerDate ?></td>
            <td class="footer-right"><span class="footer-brand"></span></td>
        </tr>
    </table>
</div>
<?php if ($previewCard2Path): ?>
<div style="text-align: center; margin-bottom: 15px;">
    <img src="<?= htmlspecialchars($previewCard2Path) ?>"
         style="max-width: 100%; height: auto; display: block; margin: 0 auto; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);"
         alt="SafetyFlash Card 2">
</div>
<?php endif; ?>
    <?php 
    $shortDesc = trim((string)($flash['title_short'] ?? $flash['summary'] ?? ''));
    if (!empty($shortDesc) && $shortDesc !== ($flash['title'] ?? '')): 
    ?>
    <div class="short-description"><?= nl2br(htmlspecialchars($shortDesc)) ?></div>
    <?php endif; ?>
<?php $description = trim((string)($flash['description'] ?? '')); if (!empty($description)): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars($l['description']) ?></div>
    <div class="section-content">
        <div class="description"><?= nl2br(htmlspecialchars($description)) ?></div>
    </div>
</div>
<?php endif; ?>

<?php $rootCauses = trim((string)($flash['root_causes'] ?? '')); if (!empty($rootCauses)): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars($l['root_causes']) ?></div>
    <div class="section-content">
        <div class="content-box"><?= nl2br(htmlspecialchars($rootCauses)) ?></div>
    </div>
</div>
<?php endif; ?>

<?php $actions = trim((string)($flash['actions'] ?? '')); if (!empty($actions)): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars($l['actions']) ?></div>
    <div class="section-content">
        <div class="content-box"><?= nl2br(htmlspecialchars($actions)) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- PAGE: All Images -->
<?php if ($hasAnyImages): ?>
<?php $imagesPageNum = $contentPageNum + 1; ?>
<div class="page-break"></div>

<div class="footer">
    <table>
        <tr>
            <td class="footer-left"><?= $imagesPageNum ?> / <?= $totalPages ?></td>
            <td class="footer-center">ID: <?= $flashId ?> | <?= htmlspecialchars($footerSite) ?> | <?= $footerDate ?></td>
            <td class="footer-right"><span class="footer-brand"></span></td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-header"><?= htmlspecialchars($l['images']) ?></div>
    <table class="images-table">
        <?php foreach (array_chunk($allImages, 2) as $row): ?>
        <tr>
            <?php foreach ($row as $img): ?>
            <td>
                <div class="image-card">
                    <img src="<?= htmlspecialchars($img['path']) ?>" alt="">
                    <?php if (!empty($img['caption'])): ?>
                    <div class="image-caption"><?= htmlspecialchars($img['caption']) ?></div>
                    <?php endif; ?>
                </div>
            </td>
            <?php endforeach; ?>
            <?php if (count($row) === 1): ?><td></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($additionalInfoEntries)): ?>
<div class="page-break"></div>
<div class="section" style="margin-top: 0;">
    <div class="section-header"><?= htmlspecialchars($l['additional_info_pdf_section'] ?? 'Lisätiedot tapahtumasta') ?></div>
    <?php foreach ($additionalInfoEntries as $aiEntry): ?>
        <?php
        $aiRaw = trim((string)($aiEntry['content'] ?? ''));
        // Strip disallowed tags and attributes; allowed tags match sf_sanitize_ai_html() in view.php
        $aiContent = strip_tags($aiRaw, '<p><br><strong><em><u><ol><ul><li><span>');
        // Remove all attributes; preserve self-closing slash (e.g. <br />)
        $aiContent = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $aiContent);
        ?>
        <div style="margin-bottom: 14px;">
            <div style="margin: 0; line-height: 1.5; color: #333;"><?= $aiContent ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>