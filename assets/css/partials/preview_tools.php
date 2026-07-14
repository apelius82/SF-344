<?php
/**
 * Yhteinen slider- ja merkintätyökalukomponentti
 * Käytetään sekä preview.php:stä että preview_tutkinta.php:stä
 *
 * Parametrit:
 * - $base (pakollinen): sovelluksen base URL
 * - $idSuffix (valinnainen): elementtien id-pääte, esim. 'Green' tutkintatiedotteelle
 * - $extraClass (valinnainen): lisäluokka piilotusta varten, esim. 'sf-green-card1-only'
 */

if (!isset($base)) {
    throw new RuntimeException('preview_tools.php requires $base to be defined');
}

$idSuffix   = $idSuffix ?? '';
$extraClass = $extraClass ?? '';

// Element ID:t
$sliderXId          = 'sfPreviewSliderX' . $idSuffix;
$sliderYId          = 'sfPreviewSliderY' . $idSuffix;
$sliderZoomId       = 'sfPreviewSliderZoom' . $idSuffix;
$slidersPanelId     = 'sfSlidersPanel' . $idSuffix;
$annotationsPanelId = 'sfAnnotationsPanel' . $idSuffix;
$toolsTabsId        = 'sfToolsTabs' . $idSuffix;

// Data-panel arvot välilehtien toimintaan
$slidersPanelName     = 'sliders' . $idSuffix;
$annotationsPanelName = 'annotations' . $idSuffix;
?>

<!-- TYÖKALUVÄLILEHDET -->
<div class="sf-tools-tabs <?= htmlspecialchars($extraClass) ?>" id="<?= htmlspecialchars($toolsTabsId) ?>">
    <button
        type="button"
        class="sf-tools-tab active"
        data-panel="<?= htmlspecialchars($slidersPanelName) ?>"
        data-suffix="<?= htmlspecialchars($idSuffix) ?>"
    >
        Kuvan säädöt
    </button>
    <button
        type="button"
        class="sf-tools-tab"
        data-panel="<?= htmlspecialchars($annotationsPanelName) ?>"
        data-suffix="<?= htmlspecialchars($idSuffix) ?>"
    >
        Merkinnät
    </button>
</div>

<!-- KUVAN SÄÄDÖT -->
<div
    class="sf-tools-panel active <?= htmlspecialchars($extraClass) ?>"
    id="<?= htmlspecialchars($slidersPanelId) ?>"
    data-panel="<?= htmlspecialchars($slidersPanelName) ?>"
    data-suffix="<?= htmlspecialchars($idSuffix) ?>"
>
    <div class="sf-sliders-compact">
        <div class="sf-slider-item">
            <span class="sf-slider-icon">↔</span>
            <input
                id="<?= htmlspecialchars($sliderXId) ?>"
                type="range"
                min="-100"
                max="100"
                value="0"
                step="1"
            >
        </div>
        <div class="sf-slider-item">
            <span class="sf-slider-icon">↕</span>
            <input
                id="<?= htmlspecialchars($sliderYId) ?>"
                type="range"
                min="-100"
                max="100"
                value="0"
                step="1"
            >
        </div>
        <div class="sf-slider-item">
            <span class="sf-slider-icon">🔍</span>
            <input
                id="<?= htmlspecialchars($sliderZoomId) ?>"
                type="range"
                min="50"
                max="200"
                value="100"
                step="1"
            >
        </div>
    </div>
    <p class="sf-tools-hint">
        Klikkaa kuvaa valitaksesi, säädä sitten slidereilla
    </p>
</div>

<!-- MERKINNÄT -->
<div
    class="sf-tools-panel <?= htmlspecialchars($extraClass) ?>"
    id="<?= htmlspecialchars($annotationsPanelId) ?>"
    data-panel="<?= htmlspecialchars($annotationsPanelName) ?>"
    data-suffix="<?= htmlspecialchars($idSuffix) ?>"
>
    <div class="sf-annotations-compact">
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="arrow"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Nuoli"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/arrow-red.png" alt="Nuoli">
        </button>
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="circle"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Ympyrä"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/circle-red.png" alt="Ympyrä">
        </button>
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="crash"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Törmäys"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/crash.png" alt="Törmäys">
        </button>
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="warning"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Varoitus"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/warning.png" alt="Varoitus">
        </button>
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="injury"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Vamma"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/injury.png" alt="Vamma">
        </button>
        <button
            type="button"
            class="sf-anno-btn"
            data-icon="cross"
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Rasti"
        >
            <img src="<?= htmlspecialchars($base) ?>/assets/img/annotations/cross-red.png" alt="Rasti">
        </button>
        <button
            type="button"
            class="sf-anno-clear"
            data-clear-annotations
            data-suffix="<?= htmlspecialchars($idSuffix) ?>"
            title="Tyhjennä"
        >
            ✕
        </button>
    </div>
    <p class="sf-tools-hint">
        Valitse merkintä, klikkaa kuvaa. Klikkaa merkintää kierrättääksesi/poistaaksesi.
    </p>
</div>