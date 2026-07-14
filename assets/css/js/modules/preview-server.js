/**
 * Server-side preview rendering
 * Replaces client-side HTML/CSS preview with server-generated image
 */

export class ServerPreview {
    constructor(options = {}) {
        this.endpoint = options.endpoint || 'app/api/preview.php';
        this.debounceMs = options.debounce || 500;
        this.previewContainer = options.container;
        this.form = options.form;

        this.pendingRequest = null;
        this.debounceTimer = null;

        // Font size constants - MUST MATCH PreviewImageGenerator.php and preview-tutkinta.js
        this.FONT_RATIOS = {
            shortTitle: 1.6,
            description: 1.0,
            rootCauses: 0.9,
            actions: 0.9
        };

        this.FONT_PRESETS = {
            'XS': 14,
            'S': 16,
            'M': 18,
            'L': 20,
            'XL': 22
        };

        this.FONT_SIZE_AUTO = {
            max: 24,
            min: 14,
            step: 1
        };
        this.FONT_SIZE_UNIT = 'pt';

        this.CARD_LAYOUT = {
            card1DescMaxHeight: 420,
            card1DescWidth: 880,       // Width for description text (TEXT_COL_WIDTH 920 - 40 padding)
            columnMaxHeight: 400,
            columnWidth: 420,          // Width for columns ((920-20)/2 - 30 padding)
            headersSpacing: 100,       // Extra space for headers and spacing (header boxes + gaps)
            singleCardMaxHeight: 850,
            charWidthRatio: 0.48       // Approximate character width as ratio of font size
            // (calibrated for Open Sans font - actual average ~0.48)
        };
    }

    /**
     * Calculate text length (supports multi-byte characters)
     */
    _calculateTextLength(text) {
        if (!text) return 0;
        // Use Array.from to handle multi-byte characters correctly
        return Array.from(text).length;
    }

    /**
     * Calculate all font sizes from base size using ratios
     */
    _calculateFontSizes(baseSize) {
        return {
            shortTitle: Math.round(baseSize * this.FONT_RATIOS.shortTitle),
            description: Math.round(baseSize * this.FONT_RATIOS.description),
            rootCauses: Math.round(baseSize * this.FONT_RATIOS.rootCauses),
            actions: Math.round(baseSize * this.FONT_RATIOS.actions)
        };
    }

    _getCurrentLayoutMode() {
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

    /**
     * Get current font sizes based on selector
     */
    _getCurrentFontSizes() {
        const fontSizeInput = document.getElementById('sfFontSizeOverride');
        const selected = this._parseFontSizeOverride(fontSizeInput?.value || '');

        const layoutMode = this._getCurrentLayoutMode();

        if (layoutMode === 'force_double') {
            if (selected !== null) {
                return this._calculateFontSizes(selected);
            }
            return this._calculateFontSizes(this.FONT_SIZE_AUTO.max);
        }

        if (layoutMode === 'force_single') {
            const baseSize = this._calculateOptimalBaseSizeFrom(selected ?? this.FONT_SIZE_AUTO.max);
            return this._calculateFontSizes(baseSize);
        }

        if (selected !== null) {
            return this._calculateFontSizes(selected);
        }

        const maxBase = this.FONT_SIZE_AUTO.max;
        const baseSize = this._calculateOptimalBaseSizeFrom(maxBase);
        return this._calculateFontSizes(baseSize);
    }

    _parseFontSizeOverride(rawValue) {
        const value = String(rawValue || '').trim();

        if (!value || value.toLowerCase() === 'auto') {
            return null;
        }

        const presetKey = value.toUpperCase();
        if (this.FONT_PRESETS[presetKey]) {
            return this.FONT_PRESETS[presetKey];
        }

        if (/^\d+$/.test(value)) {
            return Math.max(this.FONT_SIZE_AUTO.min, Math.min(this.FONT_SIZE_AUTO.max, parseInt(value, 10)));
        }

        return null;
    }

    /**
     * Calculate optimal base size to fit content on single card
     */
    _calculateOptimalBaseSize() {
        return this._calculateOptimalBaseSizeFrom(this.FONT_SIZE_AUTO.max);
    }

    /**
     * Calculate optimal base size starting from a maximum
     * Tries progressively smaller sizes until content fits
     */
    _calculateOptimalBaseSizeFrom(maxBase) {
        const title = (document.getElementById('sf-short-text')?.value || '').trim();
        const desc = (document.getElementById('sf-description')?.value || '').trim();
        const rootCauses = (document.getElementById('sf-root-causes')?.value || '').trim();
        const actions = (document.getElementById('sf-actions')?.value || '').trim();

        for (let baseSize = maxBase; baseSize >= this.FONT_SIZE_AUTO.min; baseSize -= this.FONT_SIZE_AUTO.step) {
            const sizes = this._calculateFontSizes(baseSize);

            if (this._contentFitsOnSingleCard(title, desc, rootCauses, actions, sizes)) {
                return baseSize;
            }
        }

        return this.FONT_SIZE_AUTO.min;
    }

    /**
     * Check if content fits with given font sizes
     */
    _contentFitsOnSingleCard(title, desc, rootCauses, actions, sizes) {
        const descLines = this._estimateLinesWithFontSize(desc, this.CARD_LAYOUT.card1DescWidth, sizes.description);
        const descHeight = descLines * (sizes.description * 1.35);

        if (descHeight > this.CARD_LAYOUT.card1DescMaxHeight) {
            return false;
        }

        const rootLines = this._estimateLinesWithFontSize(rootCauses, this.CARD_LAYOUT.columnWidth, sizes.rootCauses);
        const actionsLines = this._estimateLinesWithFontSize(actions, this.CARD_LAYOUT.columnWidth, sizes.actions);

        const rootHeight = rootLines * (sizes.rootCauses * 1.35);
        const actionsHeight = actionsLines * (sizes.actions * 1.35);
        const maxColumnHeight = Math.max(rootHeight, actionsHeight);

        const totalContentHeight = descHeight + maxColumnHeight + this.CARD_LAYOUT.headersSpacing;

        return totalContentHeight <= this.CARD_LAYOUT.singleCardMaxHeight;
    }

    /**
     * Estimate lines with specific font size and width
     */
    _estimateLinesWithFontSize(text, maxWidth, fontSize) {
        if (!text) return 0;

        const charsPerLine = Math.floor(maxWidth / (fontSize * this.CARD_LAYOUT.charWidthRatio));
        let lines = 0;

        const paragraphs = text.split('\n');
        for (const p of paragraphs) {
            const trimmed = p.trim();
            if (trimmed === '') continue;

            lines += Math.max(1, Math.ceil(this._calculateTextLength(trimmed) / charsPerLine));
        }

        return lines;
    }

    init() {
        // Listen to form field changes
        const fields = [
            'sf-type-red', 'sf-type-yellow', 'sf-type-green',
            'sf-short-text', 'sf-description', 'sf-worksite',
            'sf-site-detail', 'sf-date', 'sf-root-causes', 'sf-actions',
            'sf-lang',

            // varmistus: jos joskus dispatchataan eventti hidden inputille
            'sf-grid-bitmap',
            'sf-grid-layout'
        ];

        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', () => this.scheduleUpdate());
                el.addEventListener('change', () => this.scheduleUpdate());
            }
        });

        // Handle tab switching for green type (two-slide layout)
        this.initTabs();

        // Manual refresh button
        this.bindManualRefresh();

        // Watch hidden bitmap fields (image-step writes these without firing input/change)
        this.watchHiddenBitmapFields();

        // Show and bind selectors
        this._updatePreviewControlsVisibility();
        this._bindFontSizeSelector();
        this._bindLayoutModeSelector();

        // Initial render — wait briefly for any pending grid bitmap upload from step 5
        this._waitForGridBitmapAndUpdate();
    }

    /**
     * Wait for the grid bitmap hidden input to stabilize (async uploads from grid step
     * may still be in-flight when preview step initializes), then trigger update.
     */
    async _waitForGridBitmapAndUpdate() {
        const gridInput = document.getElementById('sf-grid-bitmap');
        if (!gridInput) {
            this.update(1, { force: true });
            return;
        }

        const initialValue = gridInput.value;
        let attempts = 0;
        const maxAttempts = 10; // Max 2 seconds wait (10 * 200ms)

        // Poll until value stabilizes or max attempts reached
        while (attempts < maxAttempts) {
            await new Promise(r => setTimeout(r, 200));
            attempts++;

            const currentValue = gridInput.value;
            if (currentValue !== initialValue && currentValue !== '') {
                // Value changed — wait one more tick for it to fully settle
                await new Promise(r => setTimeout(r, 100));
                break;
            }

            // If we have a non-empty value (temp file or base64), proceed
            if (currentValue) {
                break;
            }
        }

        this.update(1, { force: true });
    }

    bindManualRefresh() {
        const btn = document.getElementById('sfRefreshPreviewBtn');
        if (!btn) return;
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', async () => {
            this.setRefreshButtonLoading(btn, true);

            const cardNumber = this.getActiveCardNumber();

            try {
                await this.update(cardNumber, { force: true });
            } finally {
                this.setRefreshButtonLoading(btn, false);
            }
        });
    }

    getActiveCardNumber() {
        const tab2 = document.getElementById('sfPreviewTab2');
        if (tab2 && tab2.classList.contains('active')) return 2;
        return 1;
    }

    setRefreshButtonLoading(btn, isLoading) {
        if (!btn) return;
        btn.disabled = !!isLoading;
        btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');

        const spinner = btn.querySelector('.sf-btn-spinner');
        const icon = btn.querySelector('.sf-btn-icon');
        const labelEl = btn.querySelector('.sf-btn-label');

        if (spinner) spinner.style.display = isLoading ? 'inline-flex' : 'none';
        if (icon) icon.style.display = isLoading ? 'none' : 'inline-flex';

        const normalLabel = btn.dataset.label || 'Refresh preview';
        const loadingLabel = btn.dataset.loadingLabel || 'Refreshing…';

        if (labelEl) labelEl.textContent = isLoading ? loadingLabel : normalLabel;

        // keep tooltip in sync
        btn.title = isLoading ? loadingLabel : normalLabel;
    }

    watchHiddenBitmapFields() {
        const ids = ['sf-grid-bitmap', 'sf-grid-layout'];

        ids.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;

            // If some code DOES dispatch events, listen to them
            el.addEventListener('input', () => this.scheduleUpdate());
            el.addEventListener('change', () => this.scheduleUpdate());

            // If value is set programmatically (most common for hidden inputs), poll changes
            let last = el.value;
            setInterval(() => {
                if (el.value !== last) {
                    last = el.value;
                    this.scheduleUpdate();
                }
            }, 400);
        });
    }

    initTabs() {
        const tab1 = document.getElementById('sfPreviewTab1');
        const tab2 = document.getElementById('sfPreviewTab2');

        if (tab1) {
            tab1.addEventListener('click', () => {
                this.switchToCard(1);
            });
        }

        if (tab2) {
            tab2.addEventListener('click', () => {
                this.switchToCard(2);
            });
        }
    }

    switchToCard(cardNumber) {
        // Update tab active states
        const tab1 = document.getElementById('sfPreviewTab1');
        const tab2 = document.getElementById('sfPreviewTab2');

        if (tab1 && tab2) {
            if (cardNumber === 1) {
                tab1.classList.add('active');
                tab2.classList.remove('active');
            } else {
                tab1.classList.remove('active');
                tab2.classList.add('active');
            }
        }

        // Show/hide card containers
        const card1Container = document.getElementById('sfPreviewCard1Container');
        const card2Container = document.getElementById('sfPreviewCard2Container');

        if (card1Container && card2Container) {
            if (cardNumber === 1) {
                card1Container.style.display = 'block';
                card2Container.style.display = 'none';
            } else {
                card1Container.style.display = 'none';
                card2Container.style.display = 'block';
            }
        }

        // Generate preview for this card
        this.update(cardNumber);
    }

    updateTabsVisibility(showTabs) {
        const tabs = document.getElementById('sfPreviewTabs');
        if (tabs) {
            tabs.style.display = showTabs ? '' : 'none';
        }
    }

    scheduleUpdate() {
        clearTimeout(this.debounceTimer);
        this._updatePreviewControlsVisibility();
        const cardNumber = this.getActiveCardNumber();
        this.debounceTimer = setTimeout(() => this.update(cardNumber), this.debounceMs);
    }

    async update(cardNumber = 1, opts = {}) {
        this._updatePreviewControlsVisibility();

        // Cancel pending request
        if (this.pendingRequest) {
            this.pendingRequest.abort();
        }

        // Show loading state on the card being updated
        this.showLoading(cardNumber);

        const controller = new AbortController();
        this.pendingRequest = controller;

        try {
            // Get selected type using name attribute (radio inputs don't have IDs)
            const typeRadio = document.querySelector('input[name="type"]:checked');
            const selectedType = typeRadio ? typeRadio.value : 'yellow';

            // Get selected language using name attribute (radio inputs don't have IDs)
            const langRadio = document.querySelector('input[name="lang"]:checked');
            const selectedLang = langRadio ? langRadio.value : 'fi';

            // Build proper form data
            const layoutMode = this._getCurrentLayoutMode();

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
            data.set('layout_mode', layoutMode);

            // Send grid layout so server knows which layout template to use
            const gridLayoutInput = document.getElementById('sf-grid-layout');
            if (gridLayoutInput && gridLayoutInput.value) {
                data.set('grid_layout', gridLayoutInput.value);
            }

            const isForceDouble = selectedType === 'green' && layoutMode === 'force_double';
            data.set('card_number', String(cardNumber));

            const fontSizeInput = document.getElementById('sfFontSizeOverride');
            const selectedFontSize = this._parseFontSizeOverride(fontSizeInput?.value || '');

            // Also send bitmap overlays if available (grid bitmap is set programmatically in step "Kuvat")
            const gridBitmapInput = document.getElementById('sf-grid-bitmap');
            if (gridBitmapInput && gridBitmapInput.value) {
                const val = gridBitmapInput.value;
                if (val.startsWith('temp_grid_') && gridBitmapInput.dataset.gridBitmapBase64) {
                    // Temp filename stored on server — send the cached base64 for live preview
                    data.set('grid_bitmap', gridBitmapInput.dataset.gridBitmapBase64);
                } else {
                    data.set('grid_bitmap', val);
                }
            }

            // Send font size override if set
            if (selectedFontSize !== null) {
                data.set('font_size_override', String(selectedFontSize));
            }

            // Force a fresh render (avoids proxy/browser caching) when requested
            if (opts && opts.force) {
                data.set('_force', String(Date.now()));
            }

            const response = await fetch(this.endpoint, {
                method: 'POST',
                body: data,
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error(`Preview server error (${response.status})`);
            }

            const result = await response.json();

            if (result.ok && result.image) {
                const layoutMode = this._getCurrentLayoutMode();
                const isForceDouble = selectedType === 'green' && layoutMode === 'force_double';

                const hasSecondCard = selectedType === 'green' && (!!result.needs_second_card || isForceDouble);
                const resolvedCard = result.card_number === '2' ? 2 : 1;
                const activeCard = hasSecondCard ? (cardNumber === 2 ? 2 : 1) : 1;

                // Toggle tabs and card2 availability in the UI using server-side decision
                this.updateTabsVisibility(hasSecondCard);

                const tab1 = document.getElementById('sfPreviewTab1');
                const tab2 = document.getElementById('sfPreviewTab2');
                if (tab2) tab2.style.display = hasSecondCard ? '' : 'none';

                const card1Container = document.getElementById('sfPreviewCard1Container');
                const card2Container = document.getElementById('sfPreviewCard2Container');

                if (tab1 && tab2) {
                    if (activeCard === 2 && hasSecondCard) {
                        tab1.classList.remove('active');
                        tab2.classList.add('active');
                    } else {
                        tab1.classList.add('active');
                        tab2.classList.remove('active');
                    }
                }

                if (card1Container && card2Container) {
                    if (activeCard === 2 && hasSecondCard) {
                        card1Container.style.display = 'none';
                        card2Container.style.display = 'block';
                    } else {
                        card1Container.style.display = 'block';
                        card2Container.style.display = 'none';
                    }
                }

                if (!hasSecondCard) {
                    const tab1 = document.getElementById('sfPreviewTab1');
                    const tab2 = document.getElementById('sfPreviewTab2');
                    const card1Container = document.getElementById('sfPreviewCard1Container');
                    const card2Container = document.getElementById('sfPreviewCard2Container');

                    if (tab1) {
                        tab1.classList.add('active');
                    }

                    if (tab2) {
                        tab2.classList.remove('active');
                        tab2.style.display = 'none';
                    }

                    if (card1Container) {
                        card1Container.style.display = 'block';
                    }

                    if (card2Container) {
                        card2Container.style.display = 'none';
                    }

                    this.showImage(result.image, 1);
                    return;
                }

                this.showImage(result.image, resolvedCard);
            } else {
                this.showError(result.error || 'Preview generation failed');
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                this.showError(err.message);
            }
        } finally {
            this.pendingRequest = null;
        }
    }

    showLoading(cardNumber = 1) {
        if (!this.previewContainer) return;

        const img = this.previewContainer.querySelector(`#sfPreviewImage${cardNumber}`);

        // Käytä VAIN kuvan wrapperia, EI koko previewContaineria
        const wrapper = img ? img.closest('.sf-preview-wrapper') : null;
        if (!wrapper) return;

        // Varmista position: relative
        const cs = window.getComputedStyle(wrapper);
        if (cs.position === 'static') wrapper.style.position = 'relative';

        // Lukitse VAIN wrapperin koko
        const h = img.offsetHeight || 270;
        const w = img.offsetWidth || 480;

        wrapper.style.minHeight = `${h}px`;
        wrapper.style.minWidth = `${w}px`;
        wrapper.style.width = `${w}px`;
        wrapper.style.overflow = 'hidden';  // TÄRKEÄ: estä overflow

        if (img) img.style.display = 'none';

        const errorEl = this.previewContainer.querySelector('.sf-preview-error');
        if (errorEl) errorEl.style.display = 'none';

        // Loader VAIN wrapperiin
        let loader = wrapper.querySelector('.sf-preview-loading');
        if (!loader) {
            loader = document.createElement('div');
            loader.className = 'sf-preview-loading';
            wrapper.appendChild(loader);
        }

        loader.innerHTML = `
          <div class="skeleton-preview-box" aria-hidden="true">
            <div class="skeleton skeleton-preview-image"></div>
          </div>
        `;

        // Rajoita loader wrapperin sisään - pienempi z-index
        loader.style.display = 'flex';
        loader.style.position = 'absolute';
        loader.style.top = '0';
        loader.style.left = '0';
        loader.style.right = '0';
        loader.style.bottom = '0';
        loader.style.alignItems = 'center';
        loader.style.justifyContent = 'center';
        loader.style.background = 'rgba(255,255,255,0.95)';
        loader.style.zIndex = '10';  // Pienempi z-index, painikkeet ovat 20
        loader.style.margin = '0';
    }

    normalizeImageSrc(src) {
        if (!src) return src;
        if (typeof src !== 'string') return src;
        if (src.startsWith('data:')) return src;

        // Cache-bust URLs (server may overwrite same filename)
        const sep = src.includes('?') ? '&' : '?';
        return `${src}${sep}v=${Date.now()}`;
    }

    showImage(imageSrc, cardNumber) {
        if (!this.previewContainer) return;

        const loaders = this.previewContainer.querySelectorAll('.sf-preview-loading');
        loaders.forEach(l => (l.style.display = 'none'));

        const errorEl = this.previewContainer.querySelector('.sf-preview-error');
        if (errorEl) errorEl.style.display = 'none';

        const img = this.previewContainer.querySelector(`#sfPreviewImage${cardNumber}`);
        const wrapper = img ? img.closest('.sf-preview-wrapper') : null;

        if (img) {
            img.src = this.normalizeImageSrc(imageSrc);
            img.style.display = 'block';
        }

        if (wrapper && wrapper.style) {
            wrapper.style.minHeight = '';
            wrapper.style.minWidth = '';
            wrapper.style.width = '';
            wrapper.style.overflow = '';
        }

        if (typeof window.sfPreviewPreloadHighResolution === 'function') {
            window.sfPreviewPreloadHighResolution(cardNumber);
        }
    }

    showError(message) {
        console.error('[ServerPreview]', message);
        if (!this.previewContainer) return;

        // Piilota kaikki loaderit
        const loaders = this.previewContainer.querySelectorAll('.sf-preview-loading');
        loaders.forEach(l => (l.style.display = 'none'));

        const errorEl = this.previewContainer.querySelector('.sf-preview-error');
        const errorMsg = this.previewContainer.querySelector('#sfPreviewErrorMessage');

        if (errorEl) errorEl.style.display = 'block';
        if (errorMsg) errorMsg.textContent = message;

        // Hide preview images on error
        for (let i = 1; i <= 2; i++) {
            const img = this.previewContainer.querySelector(`#sfPreviewImage${i}`);
            if (img) img.style.display = 'none';
        }
    }

    _showFontSizeSelector() {
        this._updatePreviewControlsVisibility();
    }

    _updatePreviewControlsVisibility() {
        const typeRadio = document.querySelector('input[name="type"]:checked');
        const selectedType = typeRadio ? typeRadio.value : 'yellow';

        const fontSelector = document.getElementById('sfFontSizeSelector');
        const layoutSelector = document.getElementById('sfLayoutModeSelector');
        const tabs = document.getElementById('sfPreviewTabs');
        const tab1 = document.getElementById('sfPreviewTab1');
        const tab2 = document.getElementById('sfPreviewTab2');
        const card1Container = document.getElementById('sfPreviewCard1Container');
        const card2Container = document.getElementById('sfPreviewCard2Container');

        if (fontSelector) {
            fontSelector.style.display = 'block';
        }

        if (layoutSelector) {
            layoutSelector.style.display = selectedType === 'green' ? 'block' : 'none';
        }

        // Jos tyyppi ei ole tutkintatiedote, palautetaan layout mode oletukseen
        // ja pakotetaan preview takaisin yhden kortin näkymään.
        if (selectedType !== 'green') {
            const hiddenInput = document.getElementById('sfLayoutMode');
            if (hiddenInput) {
                hiddenInput.value = 'auto';
            }

            const layoutOptions = document.querySelectorAll('.sf-font-size-option[data-layout-mode]');
            layoutOptions.forEach(option => {
                option.classList.remove('selected');
            });

            const autoLayoutOption = document.querySelector('.sf-font-size-option[data-layout-mode="auto"]');
            if (autoLayoutOption) {
                autoLayoutOption.classList.add('selected');
            }

            const autoLayoutRadio = document.querySelector('input[name="layout_mode_choice"][value="auto"]');
            if (autoLayoutRadio) {
                autoLayoutRadio.checked = true;
            }

            if (tabs) {
                tabs.style.display = 'none';
            }

            if (tab2) {
                tab2.style.display = 'none';
                tab2.classList.remove('active');
            }

            if (tab1) {
                tab1.classList.add('active');
            }

            if (card1Container) {
                card1Container.style.display = 'block';
            }

            if (card2Container) {
                card2Container.style.display = 'none';
            }
        }
    }

    _bindFontSizeSelector() {
        const hiddenInput = document.getElementById('sfFontSizeOverride');
        const autoButton = document.getElementById('sfFontSizeAutoBtn');
        const decreaseButton = document.getElementById('sfFontSizeDecreaseBtn');
        const increaseButton = document.getElementById('sfFontSizeIncreaseBtn');
        const valueElement = document.getElementById('sfFontSizeValue');

        if (!hiddenInput || !autoButton || !decreaseButton || !increaseButton || !valueElement) {
            return;
        }

        if (hiddenInput.dataset.serverFontSizeBound === '1') {
            return;
        }
        hiddenInput.dataset.serverFontSizeBound = '1';

        const renderState = (baseSize) => {
            if (baseSize === null) {
                hiddenInput.value = '';
                valueElement.textContent = '';
                autoButton.classList.add('selected');
            } else {
                const clamped = Math.max(this.FONT_SIZE_AUTO.min, Math.min(this.FONT_SIZE_AUTO.max, baseSize));
                hiddenInput.value = String(clamped);
                valueElement.textContent = `${clamped} ${this.FONT_SIZE_UNIT}`;
                autoButton.classList.remove('selected');
            }
        };

        const getCurrentManualBase = () => this._parseFontSizeOverride(hiddenInput.value);

        const adjust = (delta) => {
            let baseSize = getCurrentManualBase();

            if (baseSize === null) {
                baseSize = this._calculateOptimalBaseSizeFrom(this.FONT_SIZE_AUTO.max);
            }

            const next = Math.max(
                this.FONT_SIZE_AUTO.min,
                Math.min(this.FONT_SIZE_AUTO.max, baseSize + delta)
            );

            renderState(next);
            this.scheduleUpdate();
        };

        renderState(getCurrentManualBase());

        autoButton.addEventListener('click', () => {
            renderState(null);
            this.scheduleUpdate();
        });

        decreaseButton.addEventListener('click', () => adjust(-1));
        increaseButton.addEventListener('click', () => adjust(1));
    }

    _bindLayoutModeSelector() {
        const options = document.querySelectorAll('.sf-font-size-option[data-layout-mode]');

        options.forEach(option => {
            option.addEventListener('click', () => {
                const value = option.dataset.layoutMode || 'auto';

                options.forEach(o => o.classList.remove('selected'));
                option.classList.add('selected');

                const radio = option.querySelector('input[name="layout_mode_choice"]');
                if (radio) {
                    radio.checked = true;
                    radio.value = value;
                }

                const hiddenInput = document.getElementById('sfLayoutMode');
                if (hiddenInput) {
                    hiddenInput.value = value;
                }

                this._updatePreviewControlsVisibility();

                const typeRadio = document.querySelector('input[name="type"]:checked');
                const selectedType = typeRadio ? typeRadio.value : 'yellow';

                if (selectedType === 'green' && value === 'force_double') {
                    const tabs = document.getElementById('sfPreviewTabs');
                    const tab2 = document.getElementById('sfPreviewTab2');

                    if (tabs) {
                        tabs.style.display = '';
                    }

                    if (tab2) {
                        tab2.style.display = '';
                    }

                    this.switchToCard(2);
                    return;
                }

                this.switchToCard(1);
            });
        });
    }
}