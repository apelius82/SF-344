<?php
/**
 * preview_modal.php - Kieliversiomodaalin preview-kortti
 * YKSINKERTAINEN versio - näyttää vain yhden kuvan (grid-bitmap)
 */

if (!isset($base)) {
    $base = rtrim($config['base_url'] ?? '', '/');
}
$baseUrl = $base;

$flashData = $flash ??  [];
$flashType = $flashData['type'] ??  'yellow';
$flashLang = $flashData['lang'] ?? 'fi';

// Taustakuva - sama polku kuin preview.php:ssä
$bgImageUrl = "{$baseUrl}/assets/img/templates/SF_bg_{$flashType}_{$flashLang}.jpg";

// Grid-bitmap tai pääkuva - EI erillisiä kuvia
$gridBitmap = (string)($flashData['grid_bitmap'] ?? '');
$finalSrc = '';

// 1. Grid-bitmap (kuvakollaasi)
if ($gridBitmap !== '') {
    if (strpos($gridBitmap, 'data:image/') === 0) {
        $finalSrc = $gridBitmap;
    } else {
        $gridPath = __DIR__ . '/../../uploads/grids/' . $gridBitmap;
        if (file_exists($gridPath)) {
            $finalSrc = $baseUrl . '/uploads/grids/' . $gridBitmap;
        }
    }
}

// 2. Fallback:  pääkuva
if (empty($finalSrc) && !empty($flashData['image_main'])) {
    $imagePath = __DIR__ . '/../../uploads/images/' . $flashData['image_main'];
    if (file_exists($imagePath)) {
        $finalSrc = $baseUrl . '/uploads/images/' . $flashData['image_main'];
    }
}

// 3. Fallback: placeholder
if (empty($finalSrc)) {
    $finalSrc = $baseUrl . '/assets/img/camera-placeholder.png';
}

// Sijainti
$site = $flashData['site'] ??  '';
$siteDetail = $flashData['site_detail'] ??  '';
$siteText = $site . (!empty($siteDetail) ? ' – ' . $siteDetail : '');

// Aika
$formattedDate = '–';
if (!empty($flashData['occurred_at'])) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $flashData['occurred_at'])
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $flashData['occurred_at'])
        ?: (strtotime($flashData['occurred_at']) ? new DateTime($flashData['occurred_at']) : null);
    if ($dt) {
        $formattedDate = $dt->format('d.m.Y') . ' klo ' . $dt->format('H:i');
    }
}

// Käännökset
$labels = [
    'fi' => ['site' => 'TYÖMAA:', 'date' => 'MILLOIN?'],
    'sv' => ['site' => 'ARBETSPLATS:', 'date' => 'NÄR?'],
    'en' => ['site' => 'WORKSITE:', 'date' => 'WHEN?'],
    'it' => ['site' => 'CANTIERE:', 'date' => 'QUANDO?'],
    'el' => ['site' => 'ΕΡΓΟΤΆΞΙΟ:', 'date' => 'ΠΌΤΕ;'],
];
$siteLabel = $labels[$flashLang]['site'] ?? $labels['fi']['site'];
$dateLabel = $labels[$flashLang]['date'] ?? $labels['fi']['date'];

// Tekstit
$titleShort = $flashData['title_short'] ?? $flashData['summary'] ?? '';
$description = $flashData['description'] ??  '';
?>

<div class="sf-modal-preview-wrapper" id="sfPreviewWrapperModal">
    <div class="sf-preview-card sf-modal-preview-card grid-main-only"
         id="sfPreviewCard"
         data-type="<?= htmlspecialchars($flashType) ?>"
         data-lang="<?= htmlspecialchars($flashLang) ?>">
        
        <!-- Taustakuva -->
        <img src="<?= htmlspecialchars($bgImageUrl) ?>"
             alt=""
             class="sf-preview-bg"
             id="sfPreviewBg">
        
        <div class="sf-preview-content">
            <div class="sf-preview-text-col">
                <h3 class="sf-preview-title" id="sfPreviewTitle" lang="<?= htmlspecialchars($flashLang) ?>">
                    <?= htmlspecialchars($titleShort ?:  'Lyhyt kuvaus tapahtumasta') ?>
                </h3>
                <div class="sf-preview-desc" id="sfPreviewDesc">
                    <?= nl2br(htmlspecialchars($description ?: 'Tarkempi kuvaus / tilannekuva')) ?>
                </div>
                
                <div class="sf-preview-meta-row">
                    <div class="sf-preview-meta-box">
                        <div class="sf-preview-meta-label"><?= htmlspecialchars($siteLabel) ?></div>
                        <div class="sf-preview-meta-value" id="sfPreviewSite">
                            <?= htmlspecialchars($siteText ?: '–') ?>
                        </div>
                    </div>
                    <div class="sf-preview-meta-box">
                        <div class="sf-preview-meta-label"><?= htmlspecialchars($dateLabel) ?></div>
                        <div class="sf-preview-meta-value" id="sfPreviewDate">
                            <?= htmlspecialchars($formattedDate) ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KUVA-ALUE:  Vain yksi kuva (grid-bitmap) -->
            <div class="sf-modal-image-col" id="sfImageCol">
                <div class="sf-modal-image-frame" id="sfGridBitmapFrame">
                    <img src="<?= htmlspecialchars($finalSrc) ?>"
                         id="sfGridBitmapImg"
                         class="sf-modal-grid-img"
                         alt="Kuva">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function scaleModalPreview() {
        var wrapper = document.getElementById('sfPreviewWrapperModal');
        if (! wrapper) return;
        
        var card = wrapper.querySelector('.sf-preview-card');
        if (!card) return;
        
        var wrapperWidth = wrapper.offsetWidth;
        if (wrapperWidth <= 0) return;
        
        // Kortti on 1920px leveä, skaalataan containerin leveyteen
        var scale = wrapperWidth / 1920;
        card.style.transform = 'scale(' + scale + ')';
    }
    
    scaleModalPreview();
    setTimeout(scaleModalPreview, 50);
    setTimeout(scaleModalPreview, 150);
    setTimeout(scaleModalPreview, 300);
    
    window.addEventListener('resize', scaleModalPreview);
    
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                setTimeout(scaleModalPreview, 50);
            }
        });
    });
    
    var modal = document.getElementById('modalTranslation');
    if (modal) {
        observer.observe(modal, { attributes: true });
    }
})();
</script>