<?php
/**
 * app/partials/preview.php
 * RED/YELLOW tyyppien preview - tiivis layout
 */

if (!isset($base)) {
    throw new RuntimeException('preview.php requires $base to be defined');
}

// Määritä tyyppi - tarkista tyhjät merkkijonot
$previewType = 'yellow'; // oletus
if (!empty($type_val)) {
    $previewType = $type_val;
} elseif (!empty($flash['type'])) {
    $previewType = $flash['type'];
}

$previewLang = !empty($flash['lang']) ? $flash['lang'] : 'fi';

// Rakenna taustakuvan URL
$bgImageUrl = "{$base}/assets/img/templates/SF_bg_{$previewType}_{$previewLang}.jpg";

$getImageUrl = function ($filename) use ($base) {
    if (empty($filename)) {
        return "{$base}/assets/img/camera-placeholder.png";
    }
    if (strpos($filename, 'lib_') === 0) {
        if (file_exists(__DIR__ . "/../../uploads/library/{$filename}")) {
            return "{$base}/uploads/library/{$filename}";
        }
        return "{$base}/assets/img/camera-placeholder.png";
    }
    if (file_exists(__DIR__ . "/../../uploads/images/{$filename}")) {
        return "{$base}/uploads/images/{$filename}";
    }
    // Fallback
    return "{$base}/assets/img/camera-placeholder.png";
};

$imgMain = $getImageUrl($flash['image_main'] ?? null);
$img2    = $getImageUrl($flash['image_2'] ?? null);
$img3    = $getImageUrl($flash['image_3'] ?? null);

$title      = $flash['title_short'] ?? $flash['summary'] ?? '';
$desc       = $flash['description'] ?? '';
$site       = $flash['site'] ?? '';
$siteDetail = $flash['site_detail'] ?? '';
$eventDate  = $flash['occurred_at'] ?? '';

$siteText = $site . (!empty($siteDetail) ? ' – ' . $siteDetail : '');

$formattedDate = '–';
if (!empty($eventDate)) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $eventDate)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $eventDate)
        ?: (strtotime($eventDate) ? new DateTime($eventDate) : null);

    if ($dt) {
        $formattedDate = $dt->format('d.m.Y H:i');
    }
}

$labels = [
    'fi' => ['site' => 'Työmaa:',    'date' => 'Milloin?'],
    'sv' => ['site' => 'Arbetsplats:', 'date' => 'När?'],
    'en' => ['site' => 'Worksite:',  'date' => 'When?'],
];

$siteLabel = $labels[$previewLang]['site'] ?? $labels['fi']['site'];
$dateLabel = $labels[$previewLang]['date'] ?? $labels['fi']['date'];
?>

<div class="sf-preview-section">
    <!-- OTSIKKO -->
    <h2 class="sf-preview-step-title">Esikatselu</h2>

    <!-- PREVIEW-KORTTI WRAPPER -->
    <div class="sf-preview-wrapper" id="sfPreviewWrapper">


        <!-- PREVIEW-KORTTI -->
        <div
            class="sf-preview-card"
            id="sfPreviewCard"
            data-type="<?= htmlspecialchars($previewType, ENT_QUOTES, 'UTF-8') ?>"
            data-lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>"
            data-base-url="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
            data-base-width="1920"
            data-base-height="1080"
        >
            <img
                src="<?= htmlspecialchars($bgImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                alt=""
                class="sf-preview-bg"
                id="sfPreviewBg"
            >

            <div class="sf-preview-content">
                <div class="sf-preview-text-col">
                    <h3 class="sf-preview-title" id="sfPreviewTitle" lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($title ?: 'Lyhyt kuvaus tapahtumasta', ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <div class="sf-preview-desc" id="sfPreviewDesc">
                        <?= nl2br(htmlspecialchars($desc ?: 'Tarkempi kuvaus / tilannekuva', ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                    <div class="sf-preview-meta-row">
                        <div class="sf-preview-meta-box">
                            <div class="sf-preview-meta-label" id="sfPreviewSiteLabel">
                                <?= htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="sf-preview-meta-value" id="sfPreviewSite">
                                <?= htmlspecialchars($siteText ?: '–', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="sf-preview-meta-box">
                            <div class="sf-preview-meta-label" id="sfPreviewDateLabel">
                                <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="sf-preview-meta-value" id="sfPreviewDate">
                                <?= htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    </div>
                </div>

            <div class="sf-preview-image-col" id="sfImageCol">
                <div class="sf-preview-image-frame sf-grid-bitmap-frame" id="sfGridBitmapFrame">
<?php
$gridBitmap = (string)($flash['grid_bitmap'] ?? '');
$gridBitmapSrc = '';
if ($gridBitmap !== '') {
    if (strpos($gridBitmap, 'data:image/') === 0) {
        $gridBitmapSrc = $gridBitmap;
    } else {
        $gridBitmapSrc = $base . '/uploads/grids/' . $gridBitmap;
    }
}

// Priorisoi editoitu kuva (merkinnät näkyvissä)
$edited1 = (string)($flash['image1_edited_data'] ?? '');
$finalSrc = '';

if ($gridBitmapSrc) {
    // 1. Grid-bitmap (lopullinen kollaasi) on ensisijainen
    $finalSrc = $gridBitmapSrc;
} elseif ($edited1 !== '' && strpos($edited1, 'data:image/') === 0) {
    // 2. Editoitu pääkuva (sisältää merkinnät)
    $finalSrc = $edited1;
} elseif ($imgMain) {
    // 3. Alkuperäinen kuva tiedostosta
    $finalSrc = $imgMain;
} else {
    // 4. Placeholder
    $finalSrc = $base . '/assets/img/camera-placeholder.png';
}
?>
<img
    src="<?= htmlspecialchars($finalSrc, ENT_QUOTES, 'UTF-8') ?>"
    id="sfGridBitmapImg"
    class="sf-preview-img-element"
    alt="Kuvakollaasi"
    style="width:  100%; height: 100%; object-fit: contain;"
>
                </div>
            </div>            </div>
        </div>
    </div>

    <?php /* Grid-bitmap on lopullinen: preview-työkalut poistettu tästä vaiheesta */ ?>

</div>