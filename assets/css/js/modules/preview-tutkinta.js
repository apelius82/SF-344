// assets/js/modules/preview_tutkinta.js
// Tutkintatiedotteen laajennus PreviewCore:sta

import { PreviewCore } from './preview-core.js';

class PreviewTutkintaClass extends PreviewCore {
    constructor() {
        super({
            idSuffix: 'Green',
            cardId: 'sfPreviewCard',
            gridSelectorId: 'sfGridSelector',
            sliderXId: 'sfPreviewSliderX',
            sliderYId: 'sfPreviewSliderY',
            sliderZoomId: 'sfPreviewSliderZoom',
            slidersPanelId: 'sfSlidersPanel',
            annotationsPanelId: 'sfAnnotationsPanel'
        });

        this.tutkintaIds = {
            card1: 'sfPreviewCardGreen',
            card2: 'sfPreviewCard2Green',
            tabs: 'sfPreviewTabsTutkinta',
            tab2: 'sfPreviewTab2Green',
            bg1: 'sfPreviewBgGreen',
            bg2: 'sfPreviewBg2Green',
            title1: 'sfPreviewTitleGreen',
            title2: 'sfPreviewTitle2Green',
            desc: 'sfPreviewDescGreen',
            desc2: 'sfPreviewDesc2Green',
            desc2Block: 'sfPreviewDescBlock2Green',
            site1: 'sfPreviewSiteGreen',
            site2: 'sfPreviewSite2Green',
            date1: 'sfPreviewDateGreen',
            date2: 'sfPreviewDate2Green',
            rootCauses: 'sfPreviewRootCausesGreen',
            rootCausesCard1: 'sfPreviewRootCausesCard1Green',
            actions: 'sfPreviewActionsGreen',
            actionsCard1: 'sfPreviewActionsCard1Green'
        };

        this.activeCard = 1;
        this._tutkintaEventsBound = false;

        this.LIMITS = {
            shortText: 85,
            descSingleSlide: 400,
            descTwoSlides: 650,
            rootCausesSingleSlide: 500,
            actionsSingleSlide: 500,
            rootCausesTwoSlides: 800,
            actionsTwoSlides: 800,
            rootCausesActionsCombined: 800,
            lineBreakCost: 30,
            maxColumnLines: 14,      // Max lines that fit in a column on single-slide layout
            charsPerLine: 45         // Average characters per line
        };

        // Font size ratios (proportional to base size)
        this.FONT_RATIOS = {
            shortTitle: 1.6,
            description: 1.0,
            rootCauses: 0.9,
            actions: 0.9
        };

        // Preset sizes (base size for description)
        this.FONT_PRESETS = {
            'XS': 14,
            'S': 16,
            'M': 18,
            'L': 20,
            'XL': 22
        };

        // Font size calculation constants
        this.FONT_SIZE_AUTO = {
            max: 24,      // Maximum base size for auto mode
            min: 14,      // Minimum base size for auto mode
            step: 1       // Step size when searching for optimal size
        };
        this.FONT_SIZE_UNIT = 'pt';

        // Layout constraint constants for card fitting calculations
        this.CARD_LAYOUT = {
            card1DescMaxHeight: 420,   // Max height for description on card 1
            card1DescWidth: 880,       // Width for description text (TEXT_COL_WIDTH 920 - 40 padding)
            columnMaxHeight: 400,      // Max height for root causes/actions columns
            columnWidth: 420,          // Width for columns ((920-20)/2 - 30 padding)
            headersSpacing: 100,       // Extra space for headers and spacing (header boxes + gaps)
            singleCardMaxHeight: 850,  // Total max height for single card
            charWidthRatio: 0.48       // Approximate character width as ratio of font size
            // (calibrated for Open Sans font - actual average ~0.48)
        };

        this.SINGLE_SLIDE_TOTAL_LIMIT = 900;
        this._resizeListenerBound = false;
    }

    /**
     * Update card scale to fit container
     * Called on init, resize, and tab switch
     */
    updateCardScale() {
        const wrapper = document.getElementById('sfPreviewWrapperGreen');
        const card1 = document.getElementById(this.tutkintaIds.card1);
        const card2 = document.getElementById(this.tutkintaIds.card2);

        if (!wrapper || !card1) return;

        // Get available width (container width minus padding from .sf-preview-section: 0 12px)
        const containerWidth = wrapper.parentElement?.offsetWidth || wrapper.offsetWidth;
        const availableWidth = containerWidth - 24; // 12px padding on each side

        // Card base dimensions (960x540)
        const cardWidth = 960;

        // Calculate scale to fit
        const scale = Math.min(1, availableWidth / cardWidth);

        // Apply scale transform
        card1.style.transform = `scale(${scale})`;

        if (card2) {
            card2.style.transform = `scale(${scale})`;
        }

        // Adjust wrapper height to match scaled card
        const scaledHeight = 540 * scale;
        wrapper.style.height = `${scaledHeight}px`;
    }

    init() {
        if (this.initialized) {
            console.log('PreviewTutkinta already initialized');
            return this;
        }

        const card = document.getElementById(this.tutkintaIds.card1);
        if (!card) {
            console.warn('PreviewTutkinta init: Card not found');
            return this;
        }

        super.init();

        if (!this._tutkintaEventsBound) {
            this._initTabs();
            this._bindFormEvents();
            this._bindFontSizeSelector();
            this._bindLayoutModeSelector();
            this._tutkintaEventsBound = true;
        }

        this._showFontSizeSelector();
        this.updatePreviewContent();
        this.updateCardScale();

        if (!this._resizeListenerBound) {
            let resizeTimer;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => this.updateCardScale(), 100);
            });
            this._resizeListenerBound = true;
        }

        console.log('PreviewTutkinta initialized');
        return this;
    }

    _initTabs() {
        const tabsWrapper = document.getElementById(this.tutkintaIds.tabs);
        if (!tabsWrapper) return;

        const buttons = tabsWrapper.querySelectorAll('.sf-preview-tab-btn');
        const self = this;

        buttons.forEach(btn => {
            if (btn.dataset.tutkintaTabBound) return;
            btn.dataset.tutkintaTabBound = '1';

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                self._switchCard(this.dataset.target, buttons);
            });
        });

        this._switchCard(this.tutkintaIds.card1, buttons);
    }

    _switchCard(targetId, buttons) {
        const card1 = document.getElementById(this.tutkintaIds.card1);
        const card2 = document.getElementById(this.tutkintaIds.card2);
        const showCard1 = !targetId || targetId === this.tutkintaIds.card1;

        if (card1) {
            card1.style.display = showCard1 ? 'block' : 'none';
        }
        if (card2) {
            card2.style.display = showCard1 ? 'none' : 'block';
        }

        this.activeCard = showCard1 ? 1 : 2;

        if (buttons) {
            buttons.forEach(btn => {
                const isActive =
                    btn.dataset.target === (showCard1 ? this.tutkintaIds.card1 : this.tutkintaIds.card2);
                btn.classList.toggle('sf-preview-tab-active', isActive);
            });
        }

        this._toggleTools(showCard1);

        if (showCard1) {
            this.applyGridClass();
            this._syncSlidersToState();
        }

        // Update scale after switching
        requestAnimationFrame(() => this.updateCardScale());
    }

    _toggleTools(show) {
        const gridSelector = document.getElementById(this.ids.gridSelector);
        const toolsTabs = document.querySelector('.sf-tools-tabs.sf-green-card1-only');
        const toolsPanels = document.querySelectorAll('.sf-tools-panel.sf-green-card1-only');
        const slidersPanel = document.getElementById(this.ids.slidersPanel);
        const annotationsPanel = document.getElementById(this.ids.annotationsPanel);

        if (gridSelector) gridSelector.style.display = show ? '' : 'none';
        if (toolsTabs) toolsTabs.style.display = show ? '' : 'none';

        toolsPanels.forEach(p => {
            if (!show) {
                p.style.display = 'none';
            } else if (p.classList.contains('active')) {
                p.style.display = 'block';
            }
        });

        if (slidersPanel) slidersPanel.style.display = show ? '' : 'none';
        if (annotationsPanel) annotationsPanel.style.display = show ? '' : 'none';
    }

    _bindFormEvents() {
        const self = this;
        const fields = [
            'sf-short-text', 'sf-description', 'sf-worksite',
            'sf-site-detail', 'sf-date', 'sf-root-causes', 'sf-actions'
        ];

        // Debounce timer to prevent flickering notification while typing
        let debounceTimer = null;

        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.dataset.tutkintaInputBound) {
                el.dataset.tutkintaInputBound = '1';
                el.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        self.updatePreviewContent();
                    }, 300);
                });
            }
        });
    }

    _calculateTextLength(text) {
        if (!text) return 0;
        // Use Array.from to count actual characters, not UTF-16 code units
        // This matches mb_strlen behavior on server side for multi-byte characters
        return Array.from(String(text)).length;
    }

    /**
     * Estimate the number of lines needed to display text
     * Takes into account line breaks (bullets) and text wrapping
     * @param {string} text Text to estimate
     * @param {number} charsPerLine Average characters per line
     * @return {number} Estimated number of lines
     */
    _estimateLines(text, charsPerLine = null) {
        if (!text) return 0;

        charsPerLine = charsPerLine || this.LIMITS.charsPerLine;
        let lines = 0;
        const paragraphs = text.split('\n');

        for (const p of paragraphs) {
            const trimmed = p.trim();
            if (trimmed === '') continue;

            // Each paragraph/bullet point is at least 1 line
            // Additional lines based on character count
            lines += Math.max(1, Math.ceil(this._calculateTextLength(trimmed) / charsPerLine));
        }

        return lines;
    }

    /**
     * Determine if content requires two slides
     * @param {string} title - Title text
     * @param {string} desc - Description text
     * @param {string} rootCauses - Root causes text
     * @param {string} actions - Actions text
     * @param {Object|null} sizes - Optional pre-calculated font sizes (null to calculate automatically)
     * @return {boolean} True if two slides are needed
     */
    _shouldUseTwoSlides(title, desc, rootCauses, actions, sizes = null) {
        const hasRootCauses = (rootCauses || '').trim().length > 0;
        const hasActions = (actions || '').trim().length > 0;

        if (!hasRootCauses && !hasActions) {
            return false;
        }

        const decision = this._resolveRenderDecision(title, desc, rootCauses, actions);
        return decision.useTwoSlides;
    }

    _getCurrentLayoutMode() {
        const selectedOption = document.querySelector('.sf-font-size-option.selected[data-layout-mode]');
        const selectedOptionValue = selectedOption?.dataset.layoutMode || '';

        const hiddenInput = document.getElementById('sfLayoutMode');
        const hiddenValue = hiddenInput?.value || '';

        const radioValue = document.querySelector('input[name="layout_mode_choice"]:checked')?.value || '';

        const value = (selectedOptionValue || hiddenValue || radioValue || 'auto').trim();

        if (value === 'force_single' || value === 'force_double') {
            return value;
        }

        return 'auto';
    }

    _getSelectedFontSizeValue() {
        const hiddenInput = document.getElementById('sfFontSizeOverride');
        const overrideValue = hiddenInput ? hiddenInput.value : '';
        return this._parseFontSizeOverride(overrideValue);
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

    _resolveRenderDecision(
        title = null,
        desc = null,
        rootCauses = null,
        actions = null
    ) {
        const resolvedTitle = title ?? (document.getElementById('sf-short-text')?.value || '');
        const resolvedDesc = desc ?? (document.getElementById('sf-description')?.value || '');
        const resolvedRootCauses = rootCauses ?? (document.getElementById('sf-root-causes')?.value || '');
        const resolvedActions = actions ?? (document.getElementById('sf-actions')?.value || '');

        const layoutMode = this._getCurrentLayoutMode();
        const selectedFont = this._getSelectedFontSizeValue();

        if (layoutMode === 'force_double') {
            return {
                layoutMode,
                selectedFont,
                sizes: this._calculateFontSizes(selectedFont ?? this.FONT_SIZE_AUTO.max),
                useTwoSlides: true,
                showNotice: true,
                reason: 'force_double'
            };
        }

        if (layoutMode === 'force_single') {
            const optimizedBase = this._calculateOptimalBaseSizeFrom(selectedFont ?? this.FONT_SIZE_AUTO.max);
            const sizes = this._calculateFontSizes(optimizedBase);
            const fits = this._contentFitsOnSingleCard(
                resolvedTitle,
                resolvedDesc,
                resolvedRootCauses,
                resolvedActions,
                sizes
            );

            return {
                layoutMode,
                selectedFont,
                sizes,
                useTwoSlides: !fits,
                showNotice: true,
                reason: fits ? 'single_success' : 'force_single_fallback'
            };
        }

        if (selectedFont === null) {
            const optimizedBase = this._calculateOptimalBaseSizeFrom(this.FONT_SIZE_AUTO.max);
            const sizes = this._calculateFontSizes(optimizedBase);
            const fits = this._contentFitsOnSingleCard(
                resolvedTitle,
                resolvedDesc,
                resolvedRootCauses,
                resolvedActions,
                sizes
            );

            return {
                layoutMode,
                selectedFont,
                sizes,
                useTwoSlides: !fits,
                showNotice: !fits,
                reason: 'auto'
            };
        }

        const manualSizes = this._calculateFontSizes(selectedFont);
        const manualFits = this._contentFitsOnSingleCard(
            resolvedTitle,
            resolvedDesc,
            resolvedRootCauses,
            resolvedActions,
            manualSizes
        );

        return {
            layoutMode,
            selectedFont,
            sizes: manualSizes,
            useTwoSlides: !manualFits,
            showNotice: !manualFits,
            reason: 'auto'
        };
    }

    /**
     * Get dynamic size class based on total content length
     * Matches the logic in preview_tutkinta.php and PreviewImageGenerator.php
     */
    _getDynamicSizeClass(title, desc, rootCauses, actions) {
        // Use _calculateTextLength to match PHP mb_strlen() behavior for multi-byte characters
        const totalLength = this._calculateTextLength(title) + this._calculateTextLength(desc) +
            this._calculateTextLength(rootCauses) + this._calculateTextLength(actions);

        if (totalLength < 500) return 'sf-content-size-lg';
        if (totalLength < 700) return 'sf-content-size-md';
        if (totalLength < 900) return 'sf-content-size-sm';
        return 'sf-content-size-xs';
    }

    _updateTwoSlidesNotice(decision) {
        const notice = document.getElementById('sfTwoSlidesNotice');
        const titleEl = document.getElementById('sfTwoSlidesNoticeTitle');
        const textEl = document.getElementById('sfTwoSlidesNoticeText');

        if (!notice || !titleEl || !textEl) {
            return;
        }

        if (!decision || !decision.showNotice) {
            notice.style.display = 'none';
            notice.classList.remove('is-warning');
            return;
        }

        let title = notice.dataset.titleAttention || 'Huomio';
        let text = notice.dataset.textAuto || '';
        let warning = false;

        if (decision.reason === 'force_double') {
            title = notice.dataset.titleAttention || 'Huomio';
            text = notice.dataset.textForceDouble || '';
        } else if (decision.reason === 'force_single_fallback') {
            title = notice.dataset.titleWarning || 'Varoitus';
            text = notice.dataset.textForceSingleFallback || '';
            warning = true;
        } else if (decision.reason === 'single_success') {
            title = notice.dataset.titleAttention || 'Huomio';
            text = notice.dataset.textSingleSuccess || '';
        } else {
            title = notice.dataset.titleAttention || 'Huomio';
            text = notice.dataset.textAuto || '';
        }

        titleEl.textContent = title;
        textEl.textContent = text;
        notice.classList.toggle('is-warning', warning);
        notice.style.display = 'flex';
    }

    updatePreviewContent() {
        const title = document.getElementById('sf-short-text')?.value || '';
        const desc = document.getElementById('sf-description')?.value || '';
        const site = document.getElementById('sf-worksite')?.value || '';
        const siteDetail = document.getElementById('sf-site-detail')?.value || '';
        const siteText = [site, siteDetail].filter(Boolean).join(' – ');
        const rootCauses = document.getElementById('sf-root-causes')?.value || '';
        const actions = document.getElementById('sf-actions')?.value || '';

        const formattedDate = this._formatDate();
        const decision = this._resolveRenderDecision(title, desc, rootCauses, actions);
        const sizes = decision.sizes;

        this._setMultiline(this.tutkintaIds.title1, title, 'Lyhyt kuvaus tapahtumasta');
        this._setMultiline(this.tutkintaIds.desc, desc, 'Tarkempi kuvaus');
        this._setMultiline(this.tutkintaIds.site1, siteText, '–');
        this._setMultiline(this.tutkintaIds.date1, formattedDate, '–');

        this._setMultiline(this.tutkintaIds.title2, title, 'Kuvaus');
        this._setMultiline(this.tutkintaIds.site2, siteText, '–');
        this._setMultiline(this.tutkintaIds.date2, formattedDate, '–');

        this._applyFontSizesToPreview(sizes);

        const useTwoSlides = decision.useTwoSlides;
        const layout = this._buildTwoCardPreviewLayout(
            title,
            desc,
            rootCauses,
            actions,
            sizes,
            decision.reason === 'force_double'
        );

        const tab2 = document.getElementById(this.tutkintaIds.tab2);
        if (tab2) {
            tab2.style.display = useTwoSlides ? '' : 'none';
        }

        if (decision.reason === 'force_double' && useTwoSlides) {
            this._activatePreviewCard(2);
        } else if (!useTwoSlides && this.activeCard === 2) {
            this._activatePreviewCard(1);
        }

        const rootActionsRow = document.getElementById('sfRootActionsCard1Green');
        const rootCausesCard1 = document.getElementById(this.tutkintaIds.rootCausesCard1);
        const actionsCard1 = document.getElementById(this.tutkintaIds.actionsCard1);

        if (rootActionsRow) {
            rootActionsRow.style.display = (!useTwoSlides && layout.hasRootOrActions) ? 'grid' : 'none';
        }

        if (rootCausesCard1) {
            rootCausesCard1.innerHTML = this._formatBulletList(layout.card1RootCauses);
        }

        if (actionsCard1) {
            actionsCard1.innerHTML = this._formatBulletList(layout.card1Actions);
        }

        const desc2Block = document.getElementById(this.tutkintaIds.desc2Block);
        const desc2 = document.getElementById(this.tutkintaIds.desc2);
        const rootEl = document.getElementById(this.tutkintaIds.rootCauses);
        const actionsEl = document.getElementById(this.tutkintaIds.actions);

        if (useTwoSlides) {
            if (desc2Block && desc2) {
                if (layout.card2Description.trim()) {
                    desc2Block.style.display = 'flex';
                    desc2.innerHTML = this._escapeHtml(layout.card2Description).replace(/\n/g, '<br>');
                } else {
                    desc2Block.style.display = 'none';
                    desc2.innerHTML = '–';
                }
            }

            if (rootEl) {
                rootEl.innerHTML = this._formatBulletList(layout.card2RootCauses);
            }

            if (actionsEl) {
                actionsEl.innerHTML = this._formatBulletList(layout.card2Actions);
            }

            const totalCard2Chars =
                layout.card2Description.length +
                layout.card2RootCauses.length +
                layout.card2Actions.length;

            const card2 = document.getElementById(this.tutkintaIds.card2);

            if (card2) {
                card2.classList.remove('content-medium', 'content-large', 'content-xlarge');

                if (totalCard2Chars > 1400) {
                    card2.classList.add('content-xlarge');
                } else if (totalCard2Chars > 1000) {
                    card2.classList.add('content-large');
                } else if (totalCard2Chars > 700) {
                    card2.classList.add('content-medium');
                }
            }
        } else {
            if (desc2Block) {
                desc2Block.style.display = 'none';
            }

            if (rootEl) {
                rootEl.innerHTML = '–';
            }

            if (actionsEl) {
                actionsEl.innerHTML = '–';
            }

            const card2 = document.getElementById(this.tutkintaIds.card2);
            if (card2) {
                card2.classList.remove('content-medium', 'content-large', 'content-xlarge');
            }
        }

        this._updateTwoSlidesNotice(decision);
        this._updateBackgroundImages(useTwoSlides);
        this.applyGridClass();
        this.updateCardScale();
    }

    _formatDate() {
        const dateEl = document.getElementById('sf-date');
        if (!dateEl?.value) return '–';

        const d = new Date(dateEl.value);
        if (isNaN(d.getTime())) return '–';

        const pad = n => (n < 10 ? '0' + n : '' + n);
        return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    _escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    _setMultiline(id, text, fallback) {
        const el = document.getElementById(id);
        if (el) {
            const value = (text?.trim()) ? text : (fallback || '–');
            el.innerHTML = this._escapeHtml(value).replace(/\n/g, '<br>');
        }
    }

    _formatBulletList(text) {
        if (!text || !text.trim()) return '–';

        const lines = text.split('\n');
        const result = [];

        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;

            // Tarkista alkaako rivi bullet-merkillä
            const bulletMatch = trimmed.match(/^[-•·*]\s*(.+)$/);
            if (bulletMatch) {
                result.push(
                    '<div class="sf-bullet-line">' +
                    '<span class="sf-bullet">•</span>' +
                    '<span class="sf-bullet-text">' + this._escapeHtml(bulletMatch[1]) + '</span>' +
                    '</div>'
                );
            } else {
                result.push('<div>' + this._escapeHtml(trimmed) + '</div>');
            }
        }

        return result.join('');
    }

    _updateBackgroundImages(hasTwoCards) {
        const card1 = document.getElementById(this.tutkintaIds.card1);
        if (!card1) return;

        const lang = card1.dataset.lang || 'fi';
        const base = card1.dataset.baseUrl || '';

        const bg1 = document.getElementById(this.tutkintaIds.bg1);
        const bg2 = document.getElementById(this.tutkintaIds.bg2);

        if (hasTwoCards) {
            if (bg1) bg1.src = `${base}/assets/img/templates/SF_bg_green_1_${lang}.jpg`;
            if (bg2) bg2.src = `${base}/assets/img/templates/SF_bg_green_2_${lang}.jpg`;
        } else {
            if (bg1) bg1.src = `${base}/assets/img/templates/SF_bg_green_${lang}.jpg`;
        }

        card1.dataset.hasCard2 = hasTwoCards ? '1' : '0';
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

    /**
     * Get current font sizes based on selector
     */
    _getCurrentFontSizes() {
        const decision = this._resolveRenderDecision();
        return decision.sizes;
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
        const title = document.getElementById('sf-short-text')?.value || '';
        const desc = document.getElementById('sf-description')?.value || '';
        const rootCauses = document.getElementById('sf-root-causes')?.value || '';
        const actions = document.getElementById('sf-actions')?.value || '';

        // Try from largest to smallest until content fits.
        // Auto mode is not allowed to shrink below FONT_SIZE_AUTO.min,
        // so if content does not fit at that size, layout should switch to two cards.
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
     * Enhanced version that takes font sizes into account
     */
    _contentFitsOnSingleCard(title, desc, rootCauses, actions, sizes) {
        const layout = this._buildTwoCardPreviewLayout(title, desc, rootCauses, actions, sizes, false);
        return !layout.useTwoSlides;
    }

    _buildTwoCardPreviewLayout(title, desc, rootCauses, actions, sizes, forceTwoSlides = false) {
        const normalizedDesc = this._normalizeText(desc);
        const normalizedRoot = this._normalizeText(rootCauses);
        const normalizedActions = this._normalizeText(actions);

        const card1DescEl = document.getElementById(this.tutkintaIds.desc);
        const card1RootEl = document.getElementById(this.tutkintaIds.rootCausesCard1);
        const card1ActionsEl = document.getElementById(this.tutkintaIds.actionsCard1);

        const card2DescBlockEl = document.getElementById(this.tutkintaIds.desc2Block);
        const card2DescEl = document.getElementById(this.tutkintaIds.desc2);
        const card2RootEl = document.getElementById(this.tutkintaIds.rootCauses);
        const card2ActionsEl = document.getElementById(this.tutkintaIds.actions);

        const result = {
            useTwoSlides: false,
            hasRootOrActions: normalizedRoot.trim().length > 0 || normalizedActions.trim().length > 0,
            card1Description: normalizedDesc,
            card1RootCauses: normalizedRoot,
            card1Actions: normalizedActions,
            card2Description: '',
            card2RootCauses: '',
            card2Actions: ''
        };

        if (!card1DescEl || !card1RootEl || !card1ActionsEl || !card2DescBlockEl || !card2DescEl || !card2RootEl || !card2ActionsEl) {
            return result;
        }

        const originalCard1Desc = card1DescEl.innerHTML;
        const originalCard1Root = card1RootEl.innerHTML;
        const originalCard1Actions = card1ActionsEl.innerHTML;
        const originalCard2DescDisplay = card2DescBlockEl.style.display;
        const originalCard2Desc = card2DescEl.innerHTML;
        const originalCard2Root = card2RootEl.innerHTML;
        const originalCard2Actions = card2ActionsEl.innerHTML;

        card1DescEl.innerHTML = this._escapeHtml(normalizedDesc).replace(/\n/g, '<br>');
        card1RootEl.innerHTML = this._formatBulletList(normalizedRoot);
        card1ActionsEl.innerHTML = this._formatBulletList(normalizedActions);

        const card1DescFits = card1DescEl.scrollHeight <= card1DescEl.clientHeight + 1;
        const card1RootFits = card1RootEl.scrollHeight <= card1RootEl.clientHeight + 1;
        const card1ActionsFits = card1ActionsEl.scrollHeight <= card1ActionsEl.clientHeight + 1;

        if (card1DescFits && card1RootFits && card1ActionsFits && !forceTwoSlides) {
            card1DescEl.innerHTML = originalCard1Desc;
            card1RootEl.innerHTML = originalCard1Root;
            card1ActionsEl.innerHTML = originalCard1Actions;
            return result;
        }

        result.useTwoSlides = true;

        if (forceTwoSlides) {
            const descSplit = this._splitPlainTextToFit(card1DescEl, normalizedDesc);

            result.card1Description = descSplit.first || normalizedDesc;
            result.card1RootCauses = '';
            result.card1Actions = '';
            result.card2Description = descSplit.rest || '';
            result.card2RootCauses = normalizedRoot;
            result.card2Actions = normalizedActions;

            // Pakotetussa kahden kortin tilassa kortti 2 ei saa jäädä tyhjäksi.
            // Jos kuvaus mahtuu kokonaan kortille 1 eikä juurisyitä/toimenpiteitä ole,
            // näytetään koko kuvaus myös kortilla 2.
            if (
                !result.card2Description.trim() &&
                !result.card2RootCauses.trim() &&
                !result.card2Actions.trim()
            ) {
                result.card2Description = normalizedDesc;
            }

            card1DescEl.innerHTML = originalCard1Desc;
            card1RootEl.innerHTML = originalCard1Root;
            card1ActionsEl.innerHTML = originalCard1Actions;
            card2DescBlockEl.style.display = originalCard2DescDisplay;
            card2DescEl.innerHTML = originalCard2Desc;
            card2RootEl.innerHTML = originalCard2Root;
            card2ActionsEl.innerHTML = originalCard2Actions;

            return result;
        }

        const descSplit = this._splitPlainTextToFit(card1DescEl, normalizedDesc);
        result.card1Description = descSplit.first;
        result.card2Description = descSplit.rest;

        card1DescEl.innerHTML = this._escapeHtml(result.card1Description).replace(/\n/g, '<br>');
        card1RootEl.innerHTML = '';
        card1ActionsEl.innerHTML = '';

        card2DescBlockEl.style.display = result.card2Description.trim() ? 'flex' : 'none';
        card2DescEl.innerHTML = this._escapeHtml(result.card2Description).replace(/\n/g, '<br>');

        const rootSplit = this._splitBulletTextToFit(card2RootEl, normalizedRoot);
        const actionsSplit = this._splitBulletTextToFit(card2ActionsEl, normalizedActions);

        result.card2RootCauses = rootSplit.first;
        result.card2Actions = actionsSplit.first;

        card1DescEl.innerHTML = originalCard1Desc;
        card1RootEl.innerHTML = originalCard1Root;
        card1ActionsEl.innerHTML = originalCard1Actions;
        card2DescBlockEl.style.display = originalCard2DescDisplay;
        card2DescEl.innerHTML = originalCard2Desc;
        card2RootEl.innerHTML = originalCard2Root;
        card2ActionsEl.innerHTML = originalCard2Actions;

        return result;
    }

    _splitPlainTextToFit(element, text) {
        const normalized = this._normalizeText(text);

        if (!normalized.trim()) {
            return { first: '', rest: '' };
        }

        const paragraphs = normalized.split('\n');
        const first = [];
        let rest = [];
        let current = '';

        for (let i = 0; i < paragraphs.length; i += 1) {
            const candidate = current ? `${current}\n${paragraphs[i]}` : paragraphs[i];
            element.innerHTML = this._escapeHtml(candidate).replace(/\n/g, '<br>');

            if (element.scrollHeight <= element.clientHeight + 1) {
                current = candidate;
                first.push(paragraphs[i]);
                continue;
            }

            const lines = candidate.split('\n');
            let fitting = '';
            let overflowStartIndex = 0;

            for (let j = 0; j < lines.length; j += 1) {
                const nextCandidate = fitting ? `${fitting}\n${lines[j]}` : lines[j];
                element.innerHTML = this._escapeHtml(nextCandidate).replace(/\n/g, '<br>');

                if (element.scrollHeight <= element.clientHeight + 1) {
                    fitting = nextCandidate;
                    overflowStartIndex = j + 1;
                } else {
                    break;
                }
            }

            if (fitting.trim()) {
                const firstLines = fitting.split('\n');
                first.length = 0;
                first.push(...firstLines);
                rest = lines.slice(overflowStartIndex).concat(paragraphs.slice(i + 1));
            } else {
                rest = paragraphs.slice(i);
            }

            return {
                first: this._normalizeText(first.join('\n')),
                rest: this._normalizeText(rest.join('\n'))
            };
        }

        return {
            first: this._normalizeText(first.join('\n')),
            rest: ''
        };
    }

    _splitBulletTextToFit(element, text) {
        const normalized = this._normalizeText(text);

        if (!normalized.trim()) {
            return { first: '', rest: '' };
        }

        const items = normalized
            .split('\n')
            .map(item => item.trim())
            .filter(item => item.length > 0);

        const first = [];
        let rest = [];

        for (let i = 0; i < items.length; i += 1) {
            const candidate = [...first, items[i]].join('\n');
            element.innerHTML = this._formatBulletList(candidate);

            if (element.scrollHeight <= element.clientHeight + 1) {
                first.push(items[i]);
                continue;
            }

            rest = items.slice(i);
            break;
        }

        return {
            first: first.join('\n'),
            rest: rest.join('\n')
        };
    }

    _normalizeText(text) {
        return String(text || '')
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    /**
     * Estimate lines with specific font size and width
     */
    _estimateLinesWithFontSize(text, maxWidth, fontSize) {
        if (!text) return 0;

        const charsPerLine = Math.floor(maxWidth / (fontSize * this.CARD_LAYOUT.charWidthRatio));
        let lines = 0;

        const paragraphs = this._normalizeText(text).split('\n');
        for (const p of paragraphs) {
            const trimmed = p.trim();
            if (trimmed === '') continue;

            lines += Math.max(1, Math.ceil(this._calculateTextLength(trimmed) / charsPerLine));
        }

        return lines;
    }

    /**
     * Apply calculated font sizes to preview DOM elements
     * Preview card is 960x540 (half of 1920x1080), so scale fonts by 0.5
     * In capture mode (1920x1080), use scale 1.0
     * @param {Object} sizes - The font size object containing properties like shortTitle, description, rootCauses, and actions
     */
    _applyFontSizesToPreview(sizes) {
        const card = document.getElementById(this.tutkintaIds.card1);
        const isCaptureMode = card?.classList.contains('sf-capture-mode') ?? false;
        const scale = isCaptureMode ? 1.0 : 0.5;

        // Card 1 elements
        const title1 = document.getElementById(this.tutkintaIds.title1);
        const desc = document.getElementById(this.tutkintaIds.desc);
        const rootCausesCard1 = document.getElementById('sfPreviewRootCausesCard1Green');
        const actionsCard1 = document.getElementById('sfPreviewActionsCard1Green');

        if (title1) {
            title1.style.fontSize = `${sizes.shortTitle * scale}px`;
            title1.style.lineHeight = '1.2';
        }
        if (desc) {
            desc.style.fontSize = `${sizes.description * scale}px`;
            desc.style.lineHeight = '1.35';
        }
        if (rootCausesCard1) {
            rootCausesCard1.style.fontSize = `${sizes.rootCauses * scale}px`;
            rootCausesCard1.style.lineHeight = '1.3';
        }
        if (actionsCard1) {
            actionsCard1.style.fontSize = `${sizes.actions * scale}px`;
            actionsCard1.style.lineHeight = '1.3';
        }

        // Card 2 elements (also need font sizes applied)
        const title2 = document.getElementById(this.tutkintaIds.title2);
        const desc2 = document.getElementById(this.tutkintaIds.desc2);
        const rootCauses2 = document.getElementById(this.tutkintaIds.rootCauses);
        const actions2 = document.getElementById(this.tutkintaIds.actions);

        if (title2) {
            title2.style.fontSize = `${sizes.shortTitle * scale}px`;
            title2.style.lineHeight = '1.2';
        }
        if (desc2) {
            desc2.style.fontSize = `${sizes.description * scale}px`;
            desc2.style.lineHeight = '1.35';
        }
        if (rootCauses2) {
            rootCauses2.style.fontSize = `${sizes.rootCauses * scale}px`;
            rootCauses2.style.lineHeight = '1.3';
        }
        if (actions2) {
            actions2.style.fontSize = `${sizes.actions * scale}px`;
            actions2.style.lineHeight = '1.3';
        }
    }

    /**
     * Bind font size selector events
     */
    _bindFontSizeSelector() {
        if (document.getElementById('sfServerPreviewSection')) {
            return;
        }

        const hiddenInput = document.getElementById('sfFontSizeOverride');
        const autoButton = document.getElementById('sfFontSizeAutoBtn');
        const decreaseButton = document.getElementById('sfFontSizeDecreaseBtn');
        const increaseButton = document.getElementById('sfFontSizeIncreaseBtn');
        const valueElement = document.getElementById('sfFontSizeValue');

        if (!hiddenInput || !autoButton || !decreaseButton || !increaseButton || !valueElement) {
            return;
        }

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
            this.updatePreviewContent();
            this._updateTabsVisibility();
        };

        renderState(getCurrentManualBase());

        autoButton.addEventListener('click', () => {
            renderState(null);
            this.updatePreviewContent();
            this._updateTabsVisibility();
        });

        decreaseButton.addEventListener('click', () => adjust(-1));
        increaseButton.addEventListener('click', () => adjust(1));
    }

    _bindLayoutModeSelector() {
        if (document.getElementById('sfServerPreviewSection')) {
            return;
        }

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

                this.updatePreviewContent();
                this._updateTabsVisibility();
            });
        });
    }

    /**
     * Show font size selector for green type
     */
    _showFontSizeSelector() {
        const selector = document.getElementById('sfFontSizeSelector');
        const layoutSelector = document.getElementById('sfLayoutModeSelector');

        if (selector) {
            selector.style.display = 'block';
        }

        if (layoutSelector) {
            layoutSelector.style.display = 'block';
        }
    }

    _activatePreviewCard(cardNumber) {
        const tabsWrapper = document.getElementById(this.tutkintaIds.tabs);
        const buttons = tabsWrapper ? tabsWrapper.querySelectorAll('.sf-preview-tab-btn') : null;

        if (cardNumber === 2) {
            this._switchCard(this.tutkintaIds.card2, buttons);
            return;
        }

        this._switchCard(this.tutkintaIds.card1, buttons);
    }

    /**
     * Update tabs based on content fit
     */
    _updateTabsVisibility() {
        const decision = this._resolveRenderDecision();
        const tab2 = document.getElementById(this.tutkintaIds.tab2);

        if (!decision.useTwoSlides) {
            if (tab2) {
                tab2.style.display = 'none';
            }

            if (this.activeCard === 2) {
                this._activatePreviewCard(1);
            }
        } else {
            if (tab2) {
                tab2.style.display = '';
            }

            if (decision.reason === 'force_double' && this.activeCard !== 2) {
                this._activatePreviewCard(2);
            }
        }

        this._updateTwoSlidesNotice(decision);
    }
}

export const PreviewTutkinta = new PreviewTutkintaClass();

if (typeof window !== 'undefined') {
    window.PreviewTutkinta = PreviewTutkinta;
}

export default PreviewTutkinta;