<?php
/**
 * Esikatselu – Tutkintatiedote (vihreä) - Kompakti layout
 */

if (!isset($base)) {
    throw new RuntimeException('preview_tutkinta.php requires $base to be defined');
}

if (!isset($flash) || !is_array($flash)) {
    $flash = [];
}
// Käytä globaalia käännösfunktiota (ladattu index.php:ssä)
$t = function ($key, $lang) {
    return sf_term($key, $lang);
};

$previewType = 'green';
$previewLang = $flash['lang'] ?? 'fi';

// Define ALL content variables BEFORE using them in calculations
$shortTitle = trim((string)($flash['title_short'] ?? $flash['summary'] ?? ''));
$longDesc   = trim((string)($flash['description'] ?? ''));
$rootCauses = trim((string)($flash['root_causes'] ?? ''));
$actions    = trim((string)($flash['actions'] ?? ''));

$charLimitForSingleSlide = 900;
$rootCausesSingleLimit = 500;
$actionsSingleLimit = 500;
$descSingleLimit = 400;
$rootCausesActionsCombinedLimit = 800;

// Now calculate using the defined variables
$totalChars = mb_strlen($shortTitle) + mb_strlen($longDesc) + mb_strlen($rootCauses) + mb_strlen($actions);
$rootActionsChars = mb_strlen($rootCauses) + mb_strlen($actions);

// Select CSS class for dynamic font sizing based on total content length
if ($totalChars < 500) {
    $contentSizeClass = 'sf-content-size-lg';
} elseif ($totalChars < 700) {
    $contentSizeClass = 'sf-content-size-md';
} elseif ($totalChars < 900) {
    $contentSizeClass = 'sf-content-size-sm';
} else {
    $contentSizeClass = 'sf-content-size-xs';
}

$hasSecondCard = (
    $totalChars > $charLimitForSingleSlide ||
    mb_strlen($longDesc) > $descSingleLimit ||
    $rootActionsChars > $rootCausesActionsCombinedLimit ||
    mb_strlen($rootCauses) > $rootCausesSingleLimit ||
    mb_strlen($actions) > $actionsSingleLimit
);

if ($hasSecondCard) {
    $bgImageUrl1 = "{$base}/assets/img/templates/SF_bg_{$previewType}_1_{$previewLang}.jpg";
    $bgImageUrl2 = "{$base}/assets/img/templates/SF_bg_{$previewType}_2_{$previewLang}.jpg";
} else {
    $bgImageUrl1 = "{$base}/assets/img/templates/SF_bg_{$previewType}_{$previewLang}.jpg";
    $bgImageUrl2 = $bgImageUrl1;
}

$getImageUrl = function ($filename) use ($base) {
    if (empty($filename)) {
        return "{$base}/assets/img/camera-placeholder.png";
    }
    if (file_exists(__DIR__ . "/../../uploads/images/{$filename}")) {
        return "{$base}/uploads/images/{$filename}";
    }
    return "{$base}/assets/img/camera-placeholder.png";
};

$imgMain = $getImageUrl($flash['image_main'] ?? null);
$img2    = $getImageUrl($flash['image_2'] ?? null);
$img3    = $getImageUrl($flash['image_3'] ?? null);

// Note: $shortTitle and $longDesc are already defined earlier
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

// Hae käännökset
$siteLabel              = $t('site_label', $previewLang) . ':';
$dateLabel              = $t('when_label', $previewLang);
$rootLabel              = $t('root_causes_label', $previewLang);
$actionsLabel           = $t('actions_label', $previewLang);
$shortTitlePlaceholder  = $t('short_title_label', $previewLang);
$descPlaceholder        = $t('description_label', $previewLang);

// Apufunktio bullet-listojen muotoiluun (hanging indent)
$formatBulletList = function ($text) {
    if (empty($text)) return '–';
    
    $lines = explode("\n", $text);
    $result = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) {
            continue; // Ohita tyhjät rivit
        }
        
        // Tarkista alkaako rivi bullet-merkillä
        if (preg_match('/^[-•·*]\s*(.+)$/', $trimmed, $matches)) {
            $result[] = '<div class="sf-bullet-line">' .
                '<span class="sf-bullet">•</span>' .
                '<span class="sf-bullet-text">' .  htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</span>' .
                '</div>';
        } else {
            $result[] = '<div>' . htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
    
    return implode('', $result);
};
?>

<div class="sf-preview-section">

    <h2 class="sf-preview-step-title">
        <?= htmlspecialchars($t('preview_label', $previewLang), ENT_QUOTES, 'UTF-8') ?>
    </h2>

    <div class="sf-preview-tabs" id="sfPreviewTabsTutkinta">
        <button
            type="button"
            class="sf-preview-tab-btn sf-preview-tab-active"
            data-target="sfPreviewCardGreen"
        >
            <?= htmlspecialchars($t('card_1_summary', $previewLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button
            type="button"
            class="sf-preview-tab-btn"
            data-target="sfPreviewCard2Green"
            id="sfPreviewTab2Green"
            style="<?= $hasSecondCard ? '' : 'display:none;' ?>"
        >
            <?= htmlspecialchars($t('card_2_investigation', $previewLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <div class="sf-preview-wrapper" id="sfPreviewWrapperGreen">
        


        <!-- KORTTI 1 (Yhteenveto) -->
        <div
            class="sf-preview-card <?= htmlspecialchars($contentSizeClass, ENT_QUOTES, 'UTF-8') ?>"
            id="sfPreviewCardGreen"
            data-type="green"
            data-lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>"
            data-base-url="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
            data-has-card2="<?= $hasSecondCard ? '1' : '0' ?>"
            data-base-width="1920"
            data-base-height="1080"
        >
            <img
                src="<?= htmlspecialchars($bgImageUrl1, ENT_QUOTES, 'UTF-8') ?>"
                alt=""
                class="sf-preview-bg"
                id="sfPreviewBgGreen"
            >

            <div class="sf-preview-content">
                <div class="sf-preview-text-col">
                    <h3 class="sf-preview-title" id="sfPreviewTitleGreen" lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($shortTitle ?: $shortTitlePlaceholder, ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <div class="sf-preview-desc" id="sfPreviewDescGreen">
                        <?= nl2br(htmlspecialchars($longDesc ?: $descPlaceholder, ENT_QUOTES, 'UTF-8')) ?>
                    </div>

                    <!-- Juurisyyt ja Toimenpiteet (yhden dian malli) -->
                    <div class="sf-preview-root-actions-row" id="sfRootActionsCard1Green" style="display: none;">
                        <div class="sf-preview-ra-col">
                            <div class="sf-preview-ra-header"><?= htmlspecialchars($rootLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="sf-preview-ra-body" id="sfPreviewRootCausesCard1Green">
                                <?= $formatBulletList($rootCauses) ?>
                            </div>
                        </div>
                        <div class="sf-preview-ra-col">
                            <div class="sf-preview-ra-header"><?= htmlspecialchars($actionsLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="sf-preview-ra-body" id="sfPreviewActionsCard1Green">
                                <?= $formatBulletList($actions) ?>
                            </div>
                        </div>
                    </div>


                    <div class="sf-preview-meta-row">
                        <div class="sf-preview-meta-box">
                            <div class="sf-preview-meta-label">
                                <?= htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="sf-preview-meta-value" id="sfPreviewSiteGreen">
                                <?= htmlspecialchars($siteText ?: '–', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="sf-preview-meta-box">
                            <div class="sf-preview-meta-label">
                                <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="sf-preview-meta-value" id="sfPreviewDateGreen">
                                <?= htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sf-preview-image-col" id="sfImageColGreen">
                    <div class="sf-preview-image-frame sf-grid-bitmap-frame" id="sfGridBitmapFrameGreen">
<?php
$gridBitmap = (string)($flash['grid_bitmap'] ??  '');
$gridBitmapSrc = '';
if ($gridBitmap !== '') {
    if (strpos($gridBitmap, 'data:image/') === 0) {
        $gridBitmapSrc = $gridBitmap;
    } else {
        $gridBitmapSrc = $base .  '/uploads/grids/' . $gridBitmap;
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
} elseif (! empty($imgMain) && $imgMain !== "{$base}/assets/img/camera-placeholder.png") {
    // 3. Alkuperäinen kuva tiedostosta
    $finalSrc = $imgMain;
} else {
    // 4. Placeholder - JS päivittää tämän kun related flash valitaan
    $finalSrc = $base . '/assets/img/camera-placeholder.png';
}
?>
<img
  src="<?= htmlspecialchars($finalSrc, ENT_QUOTES, 'UTF-8') ?>"
  id="sfGridBitmapImgGreen"
  class="sf-preview-img-element"
  alt="Kuvakollaasi"
  style="width: 100%; height: 100%; object-fit: contain; object-position: center;"
>                    </div>
                </div>
            </div>
        </div>

        <!-- KORTTI 2 (Juurisyyt & Toimenpiteet) -->
        <div
            class="sf-preview-card sf-preview-card-secondary sf-preview-card-2"
            id="sfPreviewCard2Green"
            data-type="green"
            data-lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>"
            data-base-url="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
        >
            <img
                src="<?= htmlspecialchars($bgImageUrl2, ENT_QUOTES, 'UTF-8') ?>"
                alt=""
                class="sf-preview-bg"
                id="sfPreviewBg2Green"
            >

            <div class="sf-preview-content-card2">
                <div class="sf-preview-card2-title-row">
                    <h3 class="sf-preview-title-full" id="sfPreviewTitle2Green" lang="<?= htmlspecialchars($previewLang, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($shortTitle ?: $shortTitlePlaceholder, ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                </div>

                <div class="sf-preview-card2-desc-block" id="sfPreviewDescBlock2Green" style="display:none;">
                    <div class="sf-preview-card2-header">
                        <?= htmlspecialchars($descPlaceholder, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="sf-preview-card2-desc-body" id="sfPreviewDesc2Green">–</div>
                </div>

                <div class="sf-preview-card2-columns">
                    <div class="sf-preview-card2-col">
                        <div class="sf-preview-card2-header">
                            <?= htmlspecialchars($rootLabel, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="sf-preview-card2-body" id="sfPreviewRootCausesGreen">
                            <?= $formatBulletList($rootCauses) ?>
                        </div>
                    </div>
                    <div class="sf-preview-card2-col">
                        <div class="sf-preview-card2-header">
                            <?= htmlspecialchars($actionsLabel, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="sf-preview-card2-body" id="sfPreviewActionsGreen">
                            <?= $formatBulletList($actions) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php /* Grid-bitmap on lopullinen: preview-työkalut poistettu tästä vaiheesta */ ?>


</div>