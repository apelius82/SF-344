<?php
/**
 * Server-side rendered preview (unified rendering engine)
 * Replaces client-side HTML/CSS preview with server-generated images
 */

if (!isset($base)) {
    throw new RuntimeException('preview_server.php requires $base to be defined');
}

// Get flash type for conditional display
$type_val = $flash['type'] ?? 'yellow';
$lang_val = $flash['lang'] ?? 'fi';
?>

<div class="sf-preview-section" id="sfServerPreviewSection">
    <h2 class="sf-preview-step-title"><?= htmlspecialchars(sf_term('preview_label', $uiLang) ?: 'Preview', ENT_QUOTES, 'UTF-8') ?></h2>
<div class="sf-preview-help">
    <?= htmlspecialchars(sf_term('preview_help_text', $uiLang) ?: '', ENT_QUOTES, 'UTF-8') ?>
</div>
<div class="sf-preview-layout">
    <!-- Left column: preview image + tabs -->
    <div class="sf-preview-media-col">
        <!-- Preview tabs for green type (investigation reports) -->
<div class="sf-preview-tabs" id="sfPreviewTabs" style="display: none;">
    <button type="button" class="sf-preview-tab-btn active" data-card="1" id="sfPreviewTab1">
        <?= htmlspecialchars(sf_term('preview_card_1_tab', $uiLang) ?: '1. Summary & images', ENT_QUOTES, 'UTF-8') ?>
    </button>
    <button type="button" class="sf-preview-tab-btn" data-card="2" id="sfPreviewTab2">
        <?= htmlspecialchars(sf_term('preview_card_2_tab', $uiLang) ?: '2. Root causes & actions', ENT_QUOTES, 'UTF-8') ?>
    </button>
</div>

<!-- Preview container with loading state -->
<div class="sf-preview-wrapper" id="sfServerPreviewWrapper">
        <!-- Card 1 preview image -->
        <div class="sf-preview-card-container" id="sfPreviewCard1Container">
            <img
                id="sfPreviewImage1"
                src=""
                alt="<?= htmlspecialchars(sf_term('preview_card_alt', $uiLang) ?: 'Preview', ENT_QUOTES, 'UTF-8') ?>"
                class="sf-preview-img sf-preview-img-clickable"
                style="display: none;"
            >
        </div>

        <div class="sf-preview-card-container" id="sfPreviewCard2Container" style="display: none;">
            <img
                id="sfPreviewImage2"
                src=""
                alt="<?= htmlspecialchars(sf_term('preview_card_2_alt', $uiLang) ?: 'Preview card 2', ENT_QUOTES, 'UTF-8') ?>"
                class="sf-preview-img sf-preview-img-clickable"
                style="display: none;"
            >
        </div>

        <!-- Loading indicator -->
        <div class="sf-preview-loading" id="sfPreviewLoading" style="display: flex; align-items: center; justify-content: center; min-height: 270px;">
            <div style="text-align: center;">
                <div class="sf-spinner" style="margin: 0 auto 10px;"></div>
                <span><?= htmlspecialchars(sf_term('preview_generating', $uiLang) ?: 'Generating preview...', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

                <!-- Error message -->
                <div class="sf-preview-error" id="sfPreviewError">
                    <strong>Virhe:</strong> <span id="sfPreviewErrorMessage"></span>
                </div>
            </div>
        </div>

        <!-- Right column: controls -->
        <div class="sf-preview-controls-col">
            <!-- Font Size Selector - Available for all types -->
            <div id="sfFontSizeSelector" class="sf-font-size-selector">
                <label class="sf-label"><?= htmlspecialchars(sf_term('font_size_label', $uiLang) ?? 'Text size', ENT_QUOTES, 'UTF-8') ?></label>
                <div class="sf-font-size-stepper" id="sfFontSizeStepper">
                    <button type="button" class="sf-font-size-btn sf-font-size-auto-btn selected" id="sfFontSizeAutoBtn">
                        <?= htmlspecialchars(sf_term('font_size_auto', $uiLang) ?? 'Auto', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="sf-font-size-btn sf-font-size-step-btn" id="sfFontSizeDecreaseBtn" aria-label="<?= htmlspecialchars(sf_term('font_size_decrease', $uiLang) ?? 'Decrease text size', ENT_QUOTES, 'UTF-8') ?>">−</button>
                    <span class="sf-font-size-value" id="sfFontSizeValue" aria-live="polite"></span>
                    <button type="button" class="sf-font-size-btn sf-font-size-step-btn" id="sfFontSizeIncreaseBtn" aria-label="<?= htmlspecialchars(sf_term('font_size_increase', $uiLang) ?? 'Increase text size', ENT_QUOTES, 'UTF-8') ?>">+</button>
                </div>
                <input type="hidden" name="font_size_override" id="sfFontSizeOverride" value="">
            </div>

            <div id="sfLayoutModeSelector" class="sf-font-size-selector sf-layout-mode-selector" style="display:none;">
                <label class="sf-label"><?= htmlspecialchars(sf_term('layout_mode_label', $uiLang) ?? 'Card layout', ENT_QUOTES, 'UTF-8') ?></label>
                <div class="sf-font-size-options sf-layout-mode-options">
                    <label class="sf-font-size-option selected" data-layout-mode="auto">
                        <input type="radio" name="layout_mode_choice" value="auto" checked>
                        <span class="sf-font-size-btn"><?= htmlspecialchars(sf_term('layout_mode_auto', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="sf-font-size-option" data-layout-mode="force_single">
                        <input type="radio" name="layout_mode_choice" value="force_single">
                        <span class="sf-font-size-btn"><?= htmlspecialchars(sf_term('layout_mode_force_single', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="sf-font-size-option" data-layout-mode="force_double">
                        <input type="radio" name="layout_mode_choice" value="force_double">
                        <span class="sf-font-size-btn"><?= htmlspecialchars(sf_term('layout_mode_force_double', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                </div>
                <input type="hidden" name="layout_mode" id="sfLayoutMode" value="auto">
                <p class="sf-layout-mode-help">
                    <?= htmlspecialchars(sf_term('layout_mode_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <!-- Refresh preview button -->
            <button
                type="button"
                class="sf-refresh-btn"
                id="sfRefreshPreviewBtn"
                data-label="<?= htmlspecialchars($lblRefresh, ENT_QUOTES, 'UTF-8') ?>"
                data-loading-label="<?= htmlspecialchars($lblRefreshing, ENT_QUOTES, 'UTF-8') ?>"
                aria-busy="false"
                title="<?= htmlspecialchars($lblRefresh, ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="sf-btn-spinner" aria-hidden="true" style="display:none;">
                    <svg width="16" height="16" viewBox="0 0 50 50">
                        <circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-dasharray="90 35">
                            <animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.8s" repeatCount="indefinite"/>
                        </circle>
                    </svg>
                </span>
                <span class="sf-btn-icon" aria-hidden="true" style="display:inline-flex;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M21 3v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="sf-btn-label"><?= htmlspecialchars($lblRefresh, ENT_QUOTES, 'UTF-8') ?></span>
            </button>

            <?php if (!empty($isAdminUser) && empty($editing)): ?>
                <div class="sf-admin-import-panel" id="sfAdminImportPanel">
<label class="sf-admin-import-toggle-row">
    <span><?= htmlspecialchars(sf_term('admin_import_toggle_label', $uiLang) ?: 'Tallenna suoraan valmiina SafetyFlashina', ENT_QUOTES, 'UTF-8') ?></span>
    <input type="checkbox" id="sfAdminImportEnabled" name="admin_import_enabled" value="1">
    <span class="sf-admin-import-switch"></span>
</label>

<p class="sf-admin-import-description">
    <?= htmlspecialchars(sf_term('admin_import_toggle_description', $uiLang) ?: 'Tämä toiminto ohittaa normaalit tarkastus- ja hyväksyntävaiheet. Voit käyttää toimintoa, jos tuot sovellukseen vanhemman SafetyFlashin, joka on jo aiemmin julkaistu vanhalla toimintatavalla.', ENT_QUOTES, 'UTF-8') ?>
</p>

                    <div class="sf-admin-import-fields" id="sfAdminImportFields" hidden>
                        <label class="sf-admin-import-label">
                            <?= htmlspecialchars(sf_term('admin_import_date_source_label', $uiLang) ?: 'Luontipäivä', ENT_QUOTES, 'UTF-8') ?>
                        </label>

                        <label class="sf-admin-import-radio">
                            <input type="radio" name="admin_created_at_mode" value="now" checked>
                            <span><?= htmlspecialchars(sf_term('admin_import_date_now', $uiLang) ?: 'Nykyhetki', ENT_QUOTES, 'UTF-8') ?></span>
                        </label>

                        <label class="sf-admin-import-radio">
                            <input type="radio" name="admin_created_at_mode" value="occurred_at">
                            <span><?= htmlspecialchars(sf_term('admin_import_date_event', $uiLang) ?: 'Sama kuin tapahtumapäivä', ENT_QUOTES, 'UTF-8') ?></span>
                        </label>

                        <label class="sf-admin-import-radio">
                            <input type="radio" name="admin_created_at_mode" value="custom">
                            <span><?= htmlspecialchars(sf_term('admin_import_date_custom', $uiLang) ?: 'Oma valinta', ENT_QUOTES, 'UTF-8') ?></span>
                        </label>

                        <input
                            type="datetime-local"
                            class="sf-admin-import-datetime"
                            id="sfAdminCreatedAtCustom"
                            name="admin_created_at_custom"
                            disabled
                        >

                        <label class="sf-admin-import-label" for="sfAdminInitialState">
                            <?= htmlspecialchars(sf_term('admin_import_state_label', $uiLang) ?: 'Tallennustila', ENT_QUOTES, 'UTF-8') ?>
                        </label>

                        <select class="sf-admin-import-select" id="sfAdminInitialState" name="admin_initial_state">
                            <?php foreach (sf_status_definitions() as $statusKey => $statusDef): ?>
                                <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= $statusKey === 'published' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(sf_status_label($statusKey, $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="sf-admin-import-note" id="sfAdminImportSummary">
                            <?= htmlspecialchars(sf_term('admin_import_default_summary', $uiLang) ?: 'Oletuksena SafetyFlash tallennetaan julkaistuna nykyhetkeen.', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($sfPreviewControlsSlot)) echo $sfPreviewControlsSlot; ?>
</div>

<div class="sf-preview-fullscreen-modal hidden" id="sfPreviewFullscreenModal" aria-hidden="true">
    <div class="sf-preview-fullscreen-backdrop" id="sfPreviewFullscreenBackdrop"></div>

    <div class="sf-preview-fullscreen-dialog" role="dialog" aria-modal="true" aria-labelledby="sfPreviewFullscreenTitle">
    <div class="sf-preview-fullscreen-header">
        <h3 id="sfPreviewFullscreenTitle"><?= htmlspecialchars(sf_term('preview_label', $uiLang) ?: 'Preview', ENT_QUOTES, 'UTF-8') ?></h3>

        <div class="sf-preview-fullscreen-toolbar">
            <button type="button" class="sf-preview-fullscreen-toolbtn" id="sfPreviewZoomOut" aria-label="<?= htmlspecialchars(sf_term('edit_zoom_out', $uiLang) ?: 'Zoom out', ENT_QUOTES, 'UTF-8') ?>">
                −
            </button>
            <button type="button" class="sf-preview-fullscreen-toolbtn" id="sfPreviewZoomReset" aria-label="<?= htmlspecialchars(sf_term('preview_fit_screen', $uiLang) ?: 'Fit to screen', ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(sf_term('preview_fit_screen', $uiLang) ?: 'Fit to screen', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-preview-fullscreen-toolbtn" id="sfPreviewZoomIn" aria-label="<?= htmlspecialchars(sf_term('edit_zoom_in', $uiLang) ?: 'Zoom in', ENT_QUOTES, 'UTF-8') ?>">
                +
            </button>
            <button type="button" class="sf-preview-fullscreen-close" id="sfPreviewFullscreenClose" aria-label="<?= htmlspecialchars(sf_term('preview_close', $uiLang) ?: 'Close preview', ENT_QUOTES, 'UTF-8') ?>">
                ×
            </button>
        </div>
    </div>

<div class="sf-preview-fullscreen-body" id="sfPreviewFullscreenBody">

    <img
        id="sfPreviewFullscreenImage"
        src=""
        alt="<?= htmlspecialchars(sf_term('preview_fullscreen_alt', $uiLang) ?: 'Fullscreen preview', ENT_QUOTES, 'UTF-8') ?>"
        class="sf-preview-fullscreen-image"
    >

    <div class="sf-preview-fullscreen-loading" id="sfPreviewFullscreenLoading">
        <div class="sf-spinner"></div>
        <div class="sf-preview-loading-text">
            <?= htmlspecialchars(sf_term('preview_loading_highres', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

</div>
    </div>
</div>

<style>
.sf-spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #0066cc;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: sf-spin 1s linear infinite;
}

@keyframes sf-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.sf-preview-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    padding: 4px;
    background: #f1f5f9;
    border-radius: 12px;
    width: fit-content;
}

.sf-preview-tab-btn {
    padding: 10px 20px;
    border: none;
    background: transparent;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    transition: all 0.2s ease;
}

.sf-preview-tab-btn:hover {
    background: rgba(255, 255, 255, 0.6);
    color: #334155;
}

.sf-preview-tab-btn.active {
    background: white;
    color: #0f172a;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.sf-preview-error {
    display: none;
    padding: 16px 20px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 12px;
    color: #dc2626;
    font-size: 14px;
}

.sf-preview-error strong {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.sf-preview-error strong::before {
    content: '⚠️';
}

.sf-refresh-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 0 16px;
    height: 40px;
    border-radius: 8px;
    background: #2563eb;
    border: none;
    color: #fff;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.1s ease;
    white-space: nowrap;
    align-self: flex-end;
}

.sf-refresh-btn:hover {
    background: #1d4ed8;
}

.sf-refresh-btn:active {
    background: #1e40af;
    transform: scale(0.98);
}

.sf-refresh-btn[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
}

.sf-preview-controls-col {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.sf-preview-controls-col .sf-font-size-selector {
    margin-bottom: 0;
    flex: 1 1 auto;
}

.sf-preview-img,
#sfPreviewImage1,
#sfPreviewImage2 {
    width: 100%;
    max-width: 850px;
    height: auto;
    display: block;
    margin: 0 auto;
}

.sf-preview-img-clickable {
    cursor: zoom-in;
}

.sf-preview-card-container {
    transition: opacity 0.3s;
}

.sf-preview-card-container.hidden {
    display: none;
}

.sf-preview-layout {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.sf-preview-fullscreen-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.sf-preview-fullscreen-modal.hidden {
    display: none;
}

.sf-preview-fullscreen-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.88);
}

.sf-preview-fullscreen-dialog {
    position: relative;
    z-index: 1;
    width: min(96vw, 1800px);
    height: min(94vh, 1100px);
    background: #0f172a;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
    display: flex;
    flex-direction: column;
}

.sf-preview-fullscreen-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    background: #111827;
    color: #ffffff;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.sf-preview-fullscreen-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.sf-preview-fullscreen-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sf-preview-fullscreen-toolbtn {
    min-width: 44px;
    height: 40px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
}

.sf-preview-fullscreen-toolbtn:hover {
    background: rgba(255, 255, 255, 0.16);
    border-color: rgba(255, 255, 255, 0.28);
}

.sf-preview-fullscreen-toolbtn:active {
    transform: scale(0.98);
}

.sf-preview-fullscreen-close {
    border: none;
    background: transparent;
    color: #ffffff;
    font-size: 34px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
}

.sf-preview-fullscreen-close:hover {
    opacity: 0.8;
}

.sf-preview-fullscreen-body {
    position: relative;
    flex: 1;
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
    padding: 20px;
    background: #000000;
    overflow: auto;
    cursor: grab;
    touch-action: none;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

.sf-preview-fullscreen-body:active {
    cursor: grabbing;
}

.sf-preview-fullscreen-image {
    max-width: none;
    max-height: none;
    width: auto;
    height: auto;
    margin: auto;
    display: block;
    object-fit: contain;
    transform-origin: center center;
    transform: none;
    transition: opacity 0.35s ease;
    opacity: 1;
    user-select: none;
    -webkit-user-drag: none;
}

.sf-preview-fullscreen-image.sf-preview-highres-loading {
    opacity: 0.55;
}

.sf-preview-fullscreen-image.loaded {
    opacity: 1;
}

.sf-preview-fullscreen-loading {
    position: absolute;
    inset: 0;
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: #ffffff;
    font-size: 14px;
    pointer-events: none;
    z-index: 2;
}

.sf-preview-loading-text {
    opacity: 0.85;
    text-align: center;
    max-width: 320px;
}

@media (min-width: 992px) {
    .sf-preview-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        align-items: start;
    }

    .sf-preview-media-col {
        min-width: 0;
    }

    .sf-preview-media-col .sf-preview-wrapper {
        width: 100%;
        max-width: none;
    }

    .sf-preview-media-col .sf-preview-img,
    .sf-preview-media-col #sfPreviewImage1,
    .sf-preview-media-col #sfPreviewImage2 {
        max-width: none;
        margin: 0;
    }

    .sf-preview-controls-col {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }

    .sf-preview-controls-col .sf-font-size-selector {
        flex: none;
    }

    .sf-preview-controls-col .sf-refresh-btn {
        width: 100%;
        height: 48px;
        font-size: 1rem;
        justify-content: center;
    }
}

@media (max-width: 767px) {
    .sf-preview-fullscreen-modal {
        padding: 10px;
    }

    .sf-preview-fullscreen-dialog {
        width: 100vw;
        height: 100vh;
        max-width: none;
        max-height: none;
        border-radius: 0;
    }

    .sf-preview-fullscreen-header {
        padding: 14px 16px;
        flex-direction: column;
        align-items: stretch;
    }

    .sf-preview-fullscreen-header h3 {
        font-size: 16px;
    }

    .sf-preview-fullscreen-toolbar {
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .sf-preview-fullscreen-body {
        padding: 10px;
    }
}
</style>

<script>
(function() {
    'use strict';

    function initPreviewFullscreen() {
        const modal = document.getElementById('sfPreviewFullscreenModal');
        const backdrop = document.getElementById('sfPreviewFullscreenBackdrop');
        const closeButton = document.getElementById('sfPreviewFullscreenClose');
        const fullscreenBody = document.getElementById('sfPreviewFullscreenBody');
        const fullscreenImage = document.getElementById('sfPreviewFullscreenImage');
        const previewImage1 = document.getElementById('sfPreviewImage1');
        const previewImage2 = document.getElementById('sfPreviewImage2');
        const zoomInButton = document.getElementById('sfPreviewZoomIn');
        const zoomOutButton = document.getElementById('sfPreviewZoomOut');
        const zoomResetButton = document.getElementById('sfPreviewZoomReset');

        if (!modal || !backdrop || !closeButton || !fullscreenBody || !fullscreenImage || !zoomInButton || !zoomOutButton || !zoomResetButton) {
            return;
        }

        if (modal.dataset.initialized === '1') {
            return;
        }
        modal.dataset.initialized = '1';

        const previewEndpoint = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/preview.php';

        let currentScale = 1;
        let minScale = 0.1;
        let isDragging = false;
        let isLoadingHighRes = false;
        let dragStartX = 0;
        let dragStartY = 0;
        let startScrollLeft = 0;
        let startScrollTop = 0;
        let touchStartDistance = 0;
        let touchStartScale = 1;
        let touchStartContentX = 0;
        let touchStartContentY = 0;
        let singleTouchStartX = 0;
        let singleTouchStartY = 0;
        let singleTouchScrollLeft = 0;
        let singleTouchScrollTop = 0;
        let lastTouchX = 0;
        let lastTouchY = 0;
        let lastTouchTime = 0;
        let velocityX = 0;
        let velocityY = 0;
        let momentumFrame = null;

        const highResolutionCache = new Map();

		        function stopMomentum() {
            if (momentumFrame) {
                cancelAnimationFrame(momentumFrame);
                momentumFrame = null;
            }
        }

        function startMomentum() {
            stopMomentum();

            const friction = 0.94;
            const minVelocity = 0.15;

            function step() {
                fullscreenBody.scrollLeft -= velocityX;
                fullscreenBody.scrollTop -= velocityY;

                velocityX *= friction;
                velocityY *= friction;

                if (Math.abs(velocityX) > minVelocity || Math.abs(velocityY) > minVelocity) {
                    momentumFrame = requestAnimationFrame(step);
                } else {
                    momentumFrame = null;
                }
            }

            momentumFrame = requestAnimationFrame(step);
        }

        function applyZoom() {
            const naturalWidth = fullscreenImage.naturalWidth || 1;
            const naturalHeight = fullscreenImage.naturalHeight || 1;

            fullscreenImage.style.width = Math.round(naturalWidth * currentScale) + 'px';
            fullscreenImage.style.height = Math.round(naturalHeight * currentScale) + 'px';
            fullscreenImage.style.transform = 'none';
        }

        function fitImageToViewport() {
            const bodyRect = fullscreenBody.getBoundingClientRect();
            const naturalWidth = fullscreenImage.naturalWidth || 1;
            const naturalHeight = fullscreenImage.naturalHeight || 1;

            const fitScaleX = bodyRect.width / naturalWidth;
            const fitScaleY = bodyRect.height / naturalHeight;
            const fitScale = Math.min(fitScaleX, fitScaleY, 1.35);

            minScale = Math.max(0.08, fitScale);
            currentScale = fitScale;
            applyZoom();

            requestAnimationFrame(function() {
                fullscreenBody.scrollLeft = Math.max(0, (fullscreenBody.scrollWidth - fullscreenBody.clientWidth) / 2);
                fullscreenBody.scrollTop = Math.max(0, (fullscreenBody.scrollHeight - fullscreenBody.clientHeight) / 2);
            });
        }

        function resetZoom() {
            fitImageToViewport();
        }

        function zoomIn() {
            currentScale = Math.min(currentScale + 0.2, 4);
            applyZoom();
        }

        function zoomOut() {
            currentScale = Math.max(currentScale - 0.2, minScale);
            applyZoom();
        }

        function getTouchDistance(touches) {
            const dx = touches[0].clientX - touches[1].clientX;
            const dy = touches[0].clientY - touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        function getTouchCenter(touches) {
            return {
                x: (touches[0].clientX + touches[1].clientX) / 2,
                y: (touches[0].clientY + touches[1].clientY) / 2
            };
        }

        function waitForImageLoad(imageElement) {
            return new Promise(function(resolve, reject) {
                if (imageElement.complete && imageElement.naturalWidth > 0) {
                    resolve();
                    return;
                }

                function onLoad() {
                    cleanup();
                    resolve();
                }

                function onError() {
                    cleanup();
                    reject(new Error('Kuvan lataus epäonnistui'));
                }

                function cleanup() {
                    imageElement.removeEventListener('load', onLoad);
                    imageElement.removeEventListener('error', onError);
                }

                imageElement.addEventListener('load', onLoad);
                imageElement.addEventListener('error', onError);
            });
        }

        function getSelectedType() {
            const typeRadio = document.querySelector('input[name="type"]:checked');
            return typeRadio ? typeRadio.value : 'yellow';
        }

        function getSelectedLang() {
            const langRadio = document.querySelector('input[name="lang"]:checked');
            return langRadio ? langRadio.value : 'fi';
        }

        function getSelectedFontSize() {
            const fontSizeInput = document.getElementById('sfFontSizeOverride');
            const hiddenValue = fontSizeInput ? (fontSizeInput.value || '') : '';
            const value = hiddenValue.trim();
            const legacyPresets = { S: 16, M: 18, L: 20, XL: 22 };

            if (!value || value.toLowerCase() === 'auto') {
                return '';
            }

            const presetKey = value.toUpperCase();
            if (legacyPresets[presetKey]) {
                return String(legacyPresets[presetKey]);
            }

            if (!Number.isNaN(Number(value))) {
                const clamped = Math.max(14, Math.min(24, parseInt(value, 10)));
                return String(clamped);
            }

            return '';
        }

        function getSelectedLayoutMode() {
            const selectedOption = document.querySelector('.sf-font-size-option.selected[data-layout-mode]');
            const selectedOptionValue = selectedOption?.dataset.layoutMode || '';

            const hiddenInput = document.getElementById('sfLayoutMode');
            const hiddenValue = hiddenInput ? (hiddenInput.value || '') : '';

            const radioValue = document.querySelector('input[name="layout_mode_choice"]:checked')?.value || '';
            const value = (selectedOptionValue || hiddenValue || radioValue || 'auto').trim();

            if (value === 'force_single' || value === 'force_double') {
                return value;
            }

            return 'auto';
        }

        function getCurrentFormState() {
            return {
                type: getSelectedType(),
                lang: getSelectedLang(),
                short_text: document.getElementById('sf-short-text')?.value || '',
                description: document.getElementById('sf-description')?.value || '',
                site: document.getElementById('sf-worksite')?.value || '',
                site_detail: document.getElementById('sf-site-detail')?.value || '',
                occurred_at: document.getElementById('sf-date')?.value || '',
                root_causes: document.getElementById('sf-root-causes')?.value || '',
                actions: document.getElementById('sf-actions')?.value || '',
                grid_bitmap: document.getElementById('sf-grid-bitmap')?.value || '',
                font_size_override: getSelectedFontSize(),
                layout_mode: getSelectedLayoutMode()
            };
        }

        function buildCacheKey(cardNumber) {
            const state = getCurrentFormState();
            return JSON.stringify({
                card_number: cardNumber,
                type: state.type,
                lang: state.lang,
                short_text: state.short_text,
                description: state.description,
                site: state.site,
                site_detail: state.site_detail,
                occurred_at: state.occurred_at,
                root_causes: state.root_causes,
                actions: state.actions,
                grid_bitmap: state.grid_bitmap,
                font_size_override: state.font_size_override,
                layout_mode: state.layout_mode
            });
        }

        function clearHighResolutionCache() {
            highResolutionCache.clear();
        }

        function buildHighResolutionRequestData(cardNumber) {
            const selectedType = getSelectedType();
            const selectedLang = getSelectedLang();
            const selectedLayoutMode = getSelectedLayoutMode();

            const data = new FormData();
            data.set('type', selectedType);
            data.set('lang', selectedLang);
            data.set('short_text', document.getElementById('sf-short-text')?.value || '');
            data.set('description', document.getElementById('sf-description')?.value || '');
            data.set('site', document.getElementById('sf-worksite')?.value || '');
            data.set('site_detail', document.getElementById('sf-site-detail')?.value || '');
            data.set('occurred_at', document.getElementById('sf-date')?.value || '');
            data.set('root_causes', document.getElementById('sf-root-causes')?.value || '');
            data.set('actions', document.getElementById('sf-actions')?.value || '');

            const gridBitmapInput = document.getElementById('sf-grid-bitmap');
            if (gridBitmapInput && gridBitmapInput.value) {
                data.set('grid_bitmap', gridBitmapInput.value);
            }

            const selectedFontSize = getSelectedFontSize();
            if (selectedFontSize) {
                data.set('font_size_override', selectedFontSize);
            }

            data.set('layout_mode', selectedLayoutMode);

            const tab2 = document.getElementById('sfPreviewTab2');
            const isForceDouble = selectedLayoutMode === 'force_double';

            const hasSecondCard =
                selectedType === 'green' &&
                (
                    isForceDouble ||
                    (tab2 && tab2.style.display !== 'none')
                );

            if (tab2 && selectedType === 'green') {
                tab2.style.display = hasSecondCard ? '' : 'none';
            }

            if (selectedType === 'green') {
                if (isForceDouble) {
                    data.set('card_number', String(cardNumber));
                } else if (!hasSecondCard) {
                    data.set('card_number', 'single');
                } else {
                    data.set('card_number', String(cardNumber));
                }
            } else {
                data.set('card_number', String(cardNumber));
            }

            data.set('resolution', 'final');

            return data;
        }

        async function fetchHighResolutionImage(cardNumber) {
            const data = buildHighResolutionRequestData(cardNumber);

            const response = await fetch(previewEndpoint, {
                method: 'POST',
                body: data
            });

            if (!response.ok) {
                throw new Error('Full resolution -esikatselun haku epäonnistui');
            }

            const result = await response.json();

            if (!result.ok || !result.image) {
                throw new Error(result.error || 'Full resolution -esikatselun luonti epäonnistui');
            }

            return result.image;
        }

        function preloadHighResolution(cardNumber = 1) {
            const cacheKey = buildCacheKey(cardNumber);

            if (highResolutionCache.has(cacheKey)) {
                return;
            }

            fetchHighResolutionImage(cardNumber)
                .then(function(imageUrl) {
                    highResolutionCache.set(cacheKey, imageUrl);
                })
                .catch(function(err) {
                    console.warn('Highres preload failed', err);
                });
        }

        async function openFullscreenFromImage(imageElement) {
            if (!imageElement) {
                return;
            }

            const imageSource = imageElement.getAttribute('src');
            if (!imageSource) {
                return;
            }

            const cardNumber = imageElement.id === 'sfPreviewImage2' ? 2 : 1;
            const loadingOverlay = document.getElementById('sfPreviewFullscreenLoading');
            const cacheKey = buildCacheKey(cardNumber);
            const cachedHighResolutionImage = highResolutionCache.get(cacheKey);

            fullscreenImage.classList.remove('loaded');
            fullscreenImage.classList.add('sf-preview-highres-loading');
            fullscreenImage.setAttribute('src', imageSource);
            fullscreenImage.setAttribute('alt', imageElement.getAttribute('alt') || 'Esikatselu koko ruudulla');

            if (loadingOverlay) {
                loadingOverlay.style.display = cachedHighResolutionImage ? 'none' : 'flex';
            }

            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            try {
                await waitForImageLoad(fullscreenImage);
                fitImageToViewport();
            } catch (error) {
            }

            if (cachedHighResolutionImage) {
                try {
                    fullscreenImage.setAttribute('src', cachedHighResolutionImage);
                    await waitForImageLoad(fullscreenImage);
                    fullscreenImage.classList.remove('sf-preview-highres-loading');
                    fullscreenImage.classList.add('loaded');
                    fitImageToViewport();
                    return;
                } catch (error) {
                    highResolutionCache.delete(cacheKey);
                }
            }

            if (isLoadingHighRes) {
                return;
            }

            isLoadingHighRes = true;
            fullscreenBody.style.cursor = 'progress';

            try {
                const highResolutionImage = await fetchHighResolutionImage(cardNumber);

                highResolutionCache.set(cacheKey, highResolutionImage);

                fullscreenImage.setAttribute('src', highResolutionImage);
                await waitForImageLoad(fullscreenImage);

                fullscreenImage.classList.remove('sf-preview-highres-loading');
                fullscreenImage.classList.add('loaded');

                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }

                fitImageToViewport();
            } catch (error) {
                console.error('[Preview fullscreen]', error);

                fullscreenImage.classList.remove('sf-preview-highres-loading');
                fullscreenImage.classList.add('loaded');

                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }
            } finally {
                isLoadingHighRes = false;
                fullscreenBody.style.cursor = 'grab';
            }
        }

        function closeFullscreen() {
            const loadingOverlay = document.getElementById('sfPreviewFullscreenLoading');

            stopMomentum();

            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            fullscreenImage.setAttribute('src', '');
            fullscreenImage.style.width = '';
            fullscreenImage.style.height = '';
            fullscreenImage.style.transform = 'none';
            fullscreenImage.classList.remove('loaded');
            fullscreenImage.classList.remove('sf-preview-highres-loading');
            document.body.style.overflow = '';
            currentScale = 1;
            isDragging = false;
            isLoadingHighRes = false;
            fullscreenBody.style.cursor = 'grab';

            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
        }

        function registerCacheInvalidation() {
            const selectors = [
                '#sf-short-text',
                '#sf-description',
                '#sf-worksite',
                '#sf-site-detail',
                '#sf-date',
                '#sf-root-causes',
                '#sf-actions',
                '#sf-grid-bitmap',
                '#sfFontSizeOverride',
                '#sfLayoutMode',
                'input[name="type"]',
                'input[name="lang"]',
                'input[name="layout_mode_choice"]'
            ];

            selectors.forEach(function(selector) {
                document.querySelectorAll(selector).forEach(function(element) {
                    element.addEventListener('input', clearHighResolutionCache);
                    element.addEventListener('change', clearHighResolutionCache);
                    element.addEventListener('click', clearHighResolutionCache);
                });
            });

            document.querySelectorAll('.sf-font-size-option[data-layout-mode]').forEach(function(element) {
                element.addEventListener('click', clearHighResolutionCache);
            });

            ['#sfFontSizeAutoBtn', '#sfFontSizeDecreaseBtn', '#sfFontSizeIncreaseBtn'].forEach(function(selector) {
                const element = document.querySelector(selector);
                if (element) {
                    element.addEventListener('click', clearHighResolutionCache);
                }
            });

            const refreshButton = document.getElementById('sfRefreshPreviewBtn');
            if (refreshButton) {
                refreshButton.addEventListener('click', clearHighResolutionCache);
            }

            document.addEventListener('sf:preview:refresh', clearHighResolutionCache);
        }

        if (previewImage1) {
            previewImage1.addEventListener('click', function() {
                if (previewImage1.style.display === 'none') {
                    return;
                }
                openFullscreenFromImage(previewImage1);
            });
        }

        if (previewImage2) {
            previewImage2.addEventListener('click', function() {
                if (previewImage2.style.display === 'none') {
                    return;
                }
                openFullscreenFromImage(previewImage2);
            });
        }

        zoomInButton.addEventListener('click', zoomIn);
        zoomOutButton.addEventListener('click', zoomOut);
        zoomResetButton.addEventListener('click', function() {
            resetZoom();
        });

        fullscreenBody.addEventListener('wheel', function(event) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            event.preventDefault();

            if (event.deltaY < 0) {
                zoomIn();
            } else {
                zoomOut();
            }
        }, { passive: false });

        fullscreenBody.addEventListener('mousedown', function(event) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            isDragging = true;
            dragStartX = event.clientX;
            dragStartY = event.clientY;
            startScrollLeft = fullscreenBody.scrollLeft;
            startScrollTop = fullscreenBody.scrollTop;
        });

        window.addEventListener('mousemove', function(event) {
            if (!isDragging) {
                return;
            }

            const deltaX = event.clientX - dragStartX;
            const deltaY = event.clientY - dragStartY;

            fullscreenBody.scrollLeft = startScrollLeft - deltaX;
            fullscreenBody.scrollTop = startScrollTop - deltaY;
        });

        window.addEventListener('mouseup', function() {
            isDragging = false;
        });

        fullscreenBody.addEventListener('touchstart', function(event) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            if (event.touches.length === 2) {
                event.preventDefault();

                touchStartDistance = getTouchDistance(event.touches);
                touchStartScale = currentScale;

                const center = getTouchCenter(event.touches);
                const bodyRect = fullscreenBody.getBoundingClientRect();

                touchStartContentX = fullscreenBody.scrollLeft + center.x - bodyRect.left;
                touchStartContentY = fullscreenBody.scrollTop + center.y - bodyRect.top;
            }

            if (event.touches.length === 1) {
                stopMomentum();
                singleTouchStartX = event.touches[0].clientX;
                singleTouchStartY = event.touches[0].clientY;
                singleTouchScrollLeft = fullscreenBody.scrollLeft;
                singleTouchScrollTop = fullscreenBody.scrollTop;
                lastTouchX = event.touches[0].clientX;
                lastTouchY = event.touches[0].clientY;
                lastTouchTime = Date.now();
                velocityX = 0;
                velocityY = 0;
            }
        }, { passive: false });

        fullscreenBody.addEventListener('touchmove', function(event) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            if (event.touches.length === 2 && touchStartDistance > 0) {
                event.preventDefault();

                const newDistance = getTouchDistance(event.touches);
                const scaleFactor = newDistance / touchStartDistance;

                currentScale = Math.max(minScale, Math.min(touchStartScale * scaleFactor, 4));
                applyZoom();

                const center = getTouchCenter(event.touches);
                const bodyRect = fullscreenBody.getBoundingClientRect();
                const scaleRatio = currentScale / (touchStartScale || 1);

                fullscreenBody.scrollLeft = (touchStartContentX * scaleRatio) - (center.x - bodyRect.left);
                fullscreenBody.scrollTop = (touchStartContentY * scaleRatio) - (center.y - bodyRect.top);
            }

            if (event.touches.length === 1 && currentScale > minScale) {
                event.preventDefault();

                const currentX = event.touches[0].clientX;
                const currentY = event.touches[0].clientY;
                const now = Date.now();
                const elapsed = Math.max(16, now - lastTouchTime);

                const deltaX = currentX - singleTouchStartX;
                const deltaY = currentY - singleTouchStartY;

                fullscreenBody.scrollLeft = singleTouchScrollLeft - deltaX;
                fullscreenBody.scrollTop = singleTouchScrollTop - deltaY;

                velocityX = ((currentX - lastTouchX) / elapsed) * 16;
                velocityY = ((currentY - lastTouchY) / elapsed) * 16;

                lastTouchX = currentX;
                lastTouchY = currentY;
                lastTouchTime = now;
            }
        }, { passive: false });

        fullscreenBody.addEventListener('touchend', function() {
            if (touchStartDistance === 0 && currentScale > minScale) {
                startMomentum();
            }

            touchStartDistance = 0;
        }, { passive: false });

        closeButton.addEventListener('click', closeFullscreen);
        backdrop.addEventListener('click', closeFullscreen);

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeFullscreen();
            }

            if (modal.classList.contains('hidden')) {
                return;
            }

            if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                zoomIn();
            }

            if (event.key === '-') {
                event.preventDefault();
                zoomOut();
            }

            if (event.key === '0') {
                event.preventDefault();
                zoomResetButton.click();
            }
        });

        window.sfPreviewPreloadHighResolution = preloadHighResolution;
        window.sfPreviewPreloadHighResolution = preloadHighResolution;
registerCacheInvalidation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreviewFullscreen);
    } else {
        initPreviewFullscreen();
    }

    document.addEventListener('sf:page:loaded', initPreviewFullscreen);
})();
</script>