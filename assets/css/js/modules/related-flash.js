import { getters } from './state.js';
import { updatePreview, handleConditionalFields } from './preview-update.js';
import { markFieldsFromRelatedFlash } from './investigation-context.js';

const { getEl } = getters;

function getI18n(key, fallback) {
    const i18n = window.SF_I18N || {};
    return i18n[key] || fallback;
}

function getSelectedFlashLanguage() {
    return document.querySelector('input[name="lang"]:checked')?.value || 'fi';
}

function prepareRelatedFlashSourceOptions(select) {
    if (!select || select.dataset.relatedSourceReady === '1') return;

    select.dataset.relatedSourceReady = '1';

    select._sfRelatedPlaceholderOption =
        Array.from(select.options).find((option) => option.value === '')?.cloneNode(true) || new Option('', '');

    select._sfRelatedSourceOptions = Array.from(select.options)
        .filter((option) => option.value !== '')
        .map((option) => option.cloneNode(true));
}
function getRelatedFlashOptionType(option) {
    const type = option?.dataset?.type || '';

    if (type === 'red' || type === 'yellow') {
        return type;
    }

    return (option?.textContent || '').includes('🔴') ? 'red' : 'yellow';
}

function getFilteredRelatedFlashOptions(select) {
    prepareRelatedFlashSourceOptions(select);

    const selectedLang = getSelectedFlashLanguage();
    const sourceOptions = select._sfRelatedSourceOptions || [];
    const groupedOptions = new Map();

    sourceOptions.forEach((option) => {
        const rootId = option.dataset.rootId || option.value;

        if (!groupedOptions.has(rootId)) {
            groupedOptions.set(rootId, []);
        }

        groupedOptions.get(rootId).push(option);
    });

    const filteredOptions = [];

    groupedOptions.forEach((options) => {
        const languageMatch = options.find((option) => option.dataset.lang === selectedLang);
        const originalMatch = options.find((option) => option.dataset.isOriginal === '1');
        const fallbackMatch = languageMatch || originalMatch || options[0];

        if (fallbackMatch) {
            filteredOptions.push(fallbackMatch.cloneNode(true));
        }
    });

    return filteredOptions;
}

function buildRelatedFlashPicker(select) {
    if (!select || select.dataset.relatedPickerReady === '1') return;

    prepareRelatedFlashSourceOptions(select);

    select.dataset.relatedPickerReady = '1';
    select.classList.add('is-enhanced');

    const picker = document.createElement('div');
    picker.className = 'sf-related-picker';

    picker.innerHTML = `
        <button type="button" class="sf-related-picker-trigger" aria-expanded="false">
            <span class="sf-related-picker-trigger-text">${select.dataset.placeholder || getI18n('related_flash_placeholder', 'Select flash...')}</span>
            <span class="sf-related-picker-trigger-icon" aria-hidden="true">⌄</span>
        </button>
    `;

    const modal = document.createElement('div');
    modal.className = 'sf-related-picker-modal-root';
    modal.innerHTML = `
        <div class="sf-related-picker-backdrop" hidden></div>
        <div class="sf-related-picker-panel" role="dialog" aria-modal="true" hidden>
            <div class="sf-related-picker-modal-header">
                <div class="sf-related-picker-modal-title">${select.dataset.placeholder || getI18n('related_flash_placeholder', 'Select flash...')}</div>
                <button type="button" class="sf-related-picker-close" aria-label="${getI18n('close', 'Close')}">×</button>
            </div>
            <input type="search" class="sf-related-picker-search" placeholder="${select.dataset.searchPlaceholder || getI18n('related_flash_search_placeholder', 'Search flash...')}">
            <div class="sf-related-picker-list"></div>
            <div class="sf-related-picker-empty" hidden>${select.dataset.emptyText || getI18n('related_flash_empty_text', 'No flashes found')}</div>
        </div>
    `;

    select.insertAdjacentElement('afterend', picker);
    document.body.appendChild(modal);

    const trigger = picker.querySelector('.sf-related-picker-trigger');
    const triggerText = picker.querySelector('.sf-related-picker-trigger-text');
    const backdrop = modal.querySelector('.sf-related-picker-backdrop');
    const panel = modal.querySelector('.sf-related-picker-panel');
    const closeButton = modal.querySelector('.sf-related-picker-close');
    const search = modal.querySelector('.sf-related-picker-search');
    const list = modal.querySelector('.sf-related-picker-list');
    const empty = modal.querySelector('.sf-related-picker-empty');

    let ignoreNextOutsideClick = false;

    function closePicker() {
        panel.hidden = true;
        backdrop.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        picker.classList.remove('is-open', 'is-drop-up', 'is-drop-down');
        modal.classList.remove('is-open');
        document.body.classList.remove('sf-related-picker-open');
        panel.style.removeProperty('--sf-related-picker-list-max-height');
    }

    function updatePickerDirection() {
        picker.classList.remove('is-drop-up', 'is-drop-down');

        const triggerRect = trigger.getBoundingClientRect();
        const stepActions = document.querySelector('.sf-step-content[data-step="2"] .sf-step-actions-bottom');
        const actionsRect = stepActions ? stepActions.getBoundingClientRect() : null;

        const gap = 10;
        const viewportPadding = 18;
        const preferredListHeight = 260;
        const minUsableHeight = 180;

        const bottomLimit = actionsRect
            ? Math.min(window.innerHeight - viewportPadding, actionsRect.top - gap)
            : window.innerHeight - viewportPadding;

        const availableBelow = bottomLimit - triggerRect.bottom - gap;
        const availableAbove = triggerRect.top - viewportPadding - gap;

        const shouldOpenUp = availableBelow < minUsableHeight && availableAbove > availableBelow;
        const usableHeight = shouldOpenUp
            ? Math.max(minUsableHeight, Math.min(preferredListHeight, availableAbove - 70))
            : Math.max(minUsableHeight, Math.min(preferredListHeight, availableBelow - 70));

        picker.classList.add(shouldOpenUp ? 'is-drop-up' : 'is-drop-down');
        panel.style.setProperty('--sf-related-picker-list-max-height', `${usableHeight}px`);
    }

    function openPicker(options = {}) {
        const shouldFocusSearch = options.focusSearch !== false;

        if (options.ignoreNextOutsideClick === true) {
            ignoreNextOutsideClick = true;

            window.setTimeout(() => {
                ignoreNextOutsideClick = false;
            }, 250);
        }

        panel.hidden = false;
        backdrop.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        picker.classList.add('is-open');
        modal.classList.add('is-open');
        document.body.classList.add('sf-related-picker-open');

        updatePickerDirection();

        requestAnimationFrame(() => {
            updatePickerDirection();

            if (shouldFocusSearch) {
                search.focus({ preventScroll: true });
            }
        });
    }

    function syncNativeSelectOptions(filteredOptions) {
        const currentValue = select.value || '';

        select.innerHTML = '';
        select.appendChild(select._sfRelatedPlaceholderOption.cloneNode(true));

        filteredOptions.forEach((option) => {
            select.appendChild(option.cloneNode(true));
        });

        if (currentValue && Array.from(select.options).some((option) => option.value === currentValue)) {
            select.value = currentValue;
        } else {
            select.value = '';
        }
    }

    function getOptionType(option) {
        return getRelatedFlashOptionType(option);
    }

function getOptionTypeLabel(type) {
    if (type === 'red') {
        return getI18n('first_release', 'First release');
    }

    if (type === 'yellow') {
        return getI18n('dangerous_situation', 'Dangerous situation');
    }

    return '';
}

    function formatRelatedDate(dateValue) {
        if (!dateValue) return '';

        const normalizedValue = String(dateValue).replace(' ', 'T');
        const dateObj = new Date(normalizedValue);

        if (Number.isNaN(dateObj.getTime())) {
            return String(dateValue).slice(0, 16).replace('T', ' ');
        }

        return dateObj.toLocaleString(document.documentElement.lang || navigator.language || 'en-US', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function cleanOptionTitle(option) {
        const title = option?.dataset?.title || option?.dataset?.titleShort || '';
        return title || String(option?.textContent || '').replace(/^[🔴🟡]\s*/, '').trim();
    }

    function buildRelatedFlashCard(option, variant = 'list') {
        const type = getOptionType(option);
        const title = cleanOptionTitle(option);
        const site = option?.dataset?.site || '';
        const siteDetail = option?.dataset?.siteDetail || '';
        const date = formatRelatedDate(option?.dataset?.date || '');
        const metaSite = [site, siteDetail].filter(Boolean).join(' – ');
        const typeLabel = getOptionTypeLabel(type);

        const card = document.createElement('span');
        card.className = `sf-related-flash-card sf-related-flash-card--${variant} sf-related-flash-card--${type}`;

        const icon = document.createElement('img');
        icon.className = 'sf-related-flash-card-icon';
        icon.alt = typeLabel;
        icon.src = `${window.SF_BASE_URL || ''}/assets/img/icon-${type}.png`;
        icon.loading = 'lazy';
        icon.decoding = 'async';

        const body = document.createElement('span');
        body.className = 'sf-related-flash-card-body';

        const titleRow = document.createElement('span');
        titleRow.className = 'sf-related-flash-card-title-row';

        const titleEl = document.createElement('span');
        titleEl.className = 'sf-related-flash-card-title';
        titleEl.textContent = title;

        const typeBadge = document.createElement('span');
        typeBadge.className = 'sf-related-flash-card-type';
        typeBadge.textContent = typeLabel;

        titleRow.appendChild(titleEl);

        if (variant === 'trigger') {
            titleRow.appendChild(typeBadge);
        }

        const meta = document.createElement('span');
        meta.className = 'sf-related-flash-card-meta';

        if (metaSite) {
            const siteEl = document.createElement('span');
            siteEl.textContent = metaSite;
            meta.appendChild(siteEl);
        }

        if (date) {
            const dateEl = document.createElement('span');
            dateEl.textContent = date;
            meta.appendChild(dateEl);
        }

        body.appendChild(titleRow);

        if (meta.childElementCount > 0) {
            body.appendChild(meta);
        }

        card.appendChild(icon);
        card.appendChild(body);

        return card;
    }

    function updateTriggerTextFromSelect() {
        const selectedOption = select.options[select.selectedIndex];

        triggerText.innerHTML = '';

        if (selectedOption && selectedOption.value) {
            triggerText.appendChild(buildRelatedFlashCard(selectedOption, 'trigger'));
            triggerText.classList.add('has-value');
        } else {
            triggerText.textContent = select.dataset.placeholder || getI18n('related_flash_placeholder', 'Select flash...');
            triggerText.classList.remove('has-value');
        }
    }

    function renderOptions(preferredRootId = select.value || '') {
        const selectedLang = getSelectedFlashLanguage();
        const fallbackBadge = select.dataset.fallbackBadge || getI18n('related_flash_fallback_badge', 'Original');
        const filteredOptions = getFilteredRelatedFlashOptions(select);

        syncNativeSelectOptions(filteredOptions);

        list.innerHTML = '';

        filteredOptions.forEach((option) => {
            const rootId = option.dataset.rootId || option.value;
            const isFallback = option.dataset.lang !== selectedLang;
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'sf-related-picker-option';
            button.dataset.value = rootId;
            button.dataset.search = [
                option.textContent,
                option.dataset.title,
                option.dataset.titleShort,
                option.dataset.site,
                option.dataset.siteDetail,
                option.dataset.date,
            ].filter(Boolean).join(' ').toLocaleLowerCase();

            const main = document.createElement('span');
            main.className = 'sf-related-picker-option-main';

            main.appendChild(buildRelatedFlashCard(option, 'list'));

            if (isFallback) {
                const badge = document.createElement('span');
                badge.className = 'sf-related-picker-badge';
                badge.textContent = fallbackBadge;
                main.appendChild(badge);
            }

            button.appendChild(main);

            if (String(rootId) === String(preferredRootId)) {
                button.classList.add('is-selected');
                select.value = rootId;
            }

            button.addEventListener('click', () => {
                list.querySelectorAll('.sf-related-picker-option').forEach((item) => {
                    item.classList.remove('is-selected');
                });

                button.classList.add('is-selected');
                select.value = rootId;
                updateTriggerTextFromSelect();
                closePicker();
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });

            list.appendChild(button);
        });

        updateTriggerTextFromSelect();

        empty.hidden = filteredOptions.length > 0;
        filterVisibleRelatedFlashOptions();
    }

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (panel.hidden) {
            openPicker({
                focusSearch: false,
                ignoreNextOutsideClick: true
            });
        } else {
            closePicker();
        }
    });

    function normalizeRelatedFlashSearchText(value) {
        return String(value || '')
            .toLocaleLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function filterVisibleRelatedFlashOptions() {
        const term = normalizeRelatedFlashSearchText(search.value);
        let visibleCount = 0;

        list.querySelectorAll('.sf-related-picker-option').forEach((option) => {
            const searchableText = normalizeRelatedFlashSearchText(option.textContent);
            const isVisible = term === '' || searchableText.includes(term);

            option.classList.toggle('is-filtered-out', !isVisible);
            option.style.display = isVisible ? '' : 'none';

            if (isVisible) {
                visibleCount += 1;
            }
        });

        empty.hidden = visibleCount > 0;
    }

    search.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;

        event.preventDefault();
        event.stopPropagation();

        const firstVisibleOption = Array.from(list.querySelectorAll('.sf-related-picker-option'))
            .find((option) => !option.classList.contains('is-filtered-out') && option.style.display !== 'none');

        if (firstVisibleOption) {
            firstVisibleOption.click();
        }
    });

    search.addEventListener('input', filterVisibleRelatedFlashOptions);
    search.addEventListener('keyup', filterVisibleRelatedFlashOptions);
    search.addEventListener('search', filterVisibleRelatedFlashOptions);

    backdrop.addEventListener('click', () => {
        if (ignoreNextOutsideClick) {
            ignoreNextOutsideClick = false;
            return;
        }

        closePicker();
    });

    closeButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        closePicker();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            closePicker();
        }
    });

    document.addEventListener('click', (event) => {
        if (picker.contains(event.target) || panel.contains(event.target)) {
            return;
        }

        if (ignoreNextOutsideClick) {
            ignoreNextOutsideClick = false;
            return;
        }

        closePicker();
    });

    document.addEventListener('sf:flash-language-changed', () => {
        const currentRootId = select.value || '';

        search.value = '';
        renderOptions(currentRootId);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    document.querySelectorAll('input[name="lang"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const currentRootId = select.value || '';

            search.value = '';
            renderOptions(currentRootId);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    select._sfRelatedRenderOptions = renderOptions;
    select._sfRelatedUpdateTriggerText = updateTriggerTextFromSelect;
    select._sfRelatedClosePicker = closePicker;
    select._sfRelatedOpenPicker = openPicker;

    renderOptions(select.value || '');
}

export function bindRelatedFlash() {
    const relatedFlashSelect = getEl('sf-related-flash');
    if (!relatedFlashSelect) return;

    buildRelatedFlashPicker(relatedFlashSelect);

    let preservedRelatedFlashValue = '';

    function getStep2Content() {
        return document.querySelector('.sf-step-content[data-step="2"]');
    }

    function setStep2InvestigationMode(mode) {
        const step2 = getStep2Content();
        if (!step2) return;

        step2.classList.remove('sf-step2-mode-empty', 'sf-step2-mode-base', 'sf-step2-mode-standalone');
        step2.classList.add(`sf-step2-mode-${mode}`);
    }

    function setRelatedFlashSelectValue(value, shouldDispatchChange = true) {
        relatedFlashSelect.value = value || '';

        if (typeof relatedFlashSelect._sfRelatedRenderOptions === 'function') {
            relatedFlashSelect._sfRelatedRenderOptions(relatedFlashSelect.value);
        }

        if (typeof relatedFlashSelect._sfRelatedUpdateTriggerText === 'function') {
            relatedFlashSelect._sfRelatedUpdateTriggerText();
        }

        if (shouldDispatchChange) {
            relatedFlashSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function showInvestigationFields() {
        const worksiteSection = getEl('sf-step2-worksite');
        if (worksiteSection) {
            worksiteSection.classList.remove('hidden');
        }
    }

    function hideInvestigationFields() {
        const worksiteSection = getEl('sf-step2-worksite');
        if (worksiteSection) {
            worksiteSection.classList.add('hidden');
        }
    }

    function clearRelatedFlashCopiedData() {
        const fieldIdsToClear = [
            'sf-related-flash-id',
            'sf-worksite',
            'sf-site-detail',
            'sf-date',
            'sf-title',
            'sf-short-text',
            'sf-description',
            'sf-edit-annotations-data',
            'sf-image1-transform',
            'sf-image2-transform',
            'sf-image3-transform',
            'sf-grid-bitmap'
        ];

        fieldIdsToClear.forEach((fieldId) => {
            const field = getEl(fieldId) || document.getElementById(fieldId);
            if (!field) return;

            field.value = '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });

        const gridLayoutField = document.getElementById('sf-grid-layout');
        if (gridLayoutField) {
            gridLayoutField.value = 'grid-1';
            gridLayoutField.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const originalPreview = getEl('sf-original-flash-preview');
        if (originalPreview) {
            originalPreview.classList.add('hidden');
            originalPreview.classList.remove('type-red', 'type-yellow');
        }

        const worksiteTriggerText = getEl('sf-worksite-trigger-text');
        if (worksiteTriggerText) {
            const placeholder = worksiteTriggerText.dataset.placeholder || '';
            worksiteTriggerText.textContent = placeholder;
            worksiteTriggerText.classList.remove('has-value');
        }

        const chipList = getEl('sf-worksite-chip-list');
        if (chipList) {
            chipList.querySelectorAll('.sf-worksite-chip-option').forEach((chip) => {
                chip.classList.remove('is-selected');
                chip.setAttribute('aria-pressed', 'false');
            });
        }

        const card = getEl('sfPreviewCard') || getEl('sfPreviewCardGreen');
        const baseUrl = card?.dataset.baseUrl || window.SF_BASE_URL || '';
        const placeholderImage = `${baseUrl}/assets/img/camera-placeholder.png`;

        [1, 2, 3].forEach((slot) => {
            const hiddenField = document.getElementById(`sf-existing-image-${slot}`);
            if (hiddenField) {
                hiddenField.value = '';
            }

            const thumb = getEl(`sfImageThumb${slot}`);
            if (thumb) {
                thumb.src = placeholderImage;
                thumb.dataset.hasRealImage = '0';
                thumb.parentElement?.classList.remove('has-image');
            }

            const uploadPreview = getEl(`sf-upload-preview${slot}`);
            if (uploadPreview) {
                uploadPreview.src = placeholderImage;
                uploadPreview.parentElement?.classList.remove('has-image');
            }

            const cardImg = getEl(`sfPreviewImg${slot}`);
            if (cardImg) {
                cardImg.src = placeholderImage;
                cardImg.dataset.hasRealImage = '0';
            }

            const cardImgGreen = getEl(`sfPreviewImg${slot}Green`);
            if (cardImgGreen) {
                cardImgGreen.src = placeholderImage;
                cardImgGreen.dataset.hasRealImage = '0';
            }

            const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
            if (removeBtn) {
                removeBtn.classList.add('hidden');
            }

            const slotCard = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
            if (slotCard) {
                slotCard.classList.remove('has-image');
            }
        });

        updatePreview();

        if (window.Preview?.applyGridClass) {
            window.Preview.applyGridClass();
        }

        if (window.PreviewTutkinta?.applyGridClass) {
            window.PreviewTutkinta.applyGridClass();
        }

        if (window.PreviewTutkinta?.updatePreviewContent) {
            window.PreviewTutkinta.updatePreviewContent();
        }

        if (window.SFUpdateProgress) {
            window.SFUpdateProgress();
        }
    }


    // Sulje-nappi alkuperäisen tiedotteen esikatselussa
    const closeBtn = getEl('sf-original-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            const preview = getEl('sf-original-flash-preview');
            if (preview) preview.classList.add('hidden');
        });
    }

    relatedFlashSelect.addEventListener('change', function () {
        const selectedOption = this.options[this.selectedIndex];
        const hiddenRelated = getEl('sf-related-flash-id');
        const originalPreview = getEl('sf-original-flash-preview');
        const currentType = document.querySelector('input[name="type"]:checked')?.value || '';

        if (!selectedOption || !selectedOption.value) {
            if (hiddenRelated) hiddenRelated.value = '';
            if (originalPreview) originalPreview.classList.add('hidden');

            if (currentType !== 'green') {
                showInvestigationFields();
                setStep2InvestigationMode('base');
                return;
            }

            const standaloneCheckbox = getEl('sf-standalone-investigation');
            if (standaloneCheckbox && standaloneCheckbox.checked) {
                showInvestigationFields();
                setStep2InvestigationMode('standalone');
            } else {
                hideInvestigationFields();
                setStep2InvestigationMode('empty');
            }

            return;
        }

        const standaloneCheckbox = getEl('sf-standalone-investigation');
        if (standaloneCheckbox && standaloneCheckbox.checked) {
            standaloneCheckbox.checked = false;
            handleConditionalFields();
        }

        preservedRelatedFlashValue = selectedOption.value;
        showInvestigationFields();
        setStep2InvestigationMode('base');

        if (hiddenRelated) hiddenRelated.value = selectedOption.value;

        const site = selectedOption.dataset.site || '';
        const siteDetail = selectedOption.dataset.siteDetail || '';
        const date = selectedOption.dataset.date || '';
        const title = selectedOption.dataset.title || '';
        const titleShort = selectedOption.dataset.titleShort || '';
        const description = selectedOption.dataset.description || '';
        const imageMain = selectedOption.dataset.imageMain || '';
        const image2 = selectedOption.dataset.image2 || '';
        const image3 = selectedOption.dataset.image3 || '';

        const originalType = getRelatedFlashOptionType(selectedOption);

        // ============================================
        // HAE MERKINNÄT JA TRANSFORMIT ALKUPERÄISESTÄ
        // ============================================
        const annotationsData = selectedOption.dataset.annotationsData || '{}';
        const image1Transform = selectedOption.dataset.image1Transform || '';
        const image2Transform = selectedOption.dataset.image2Transform || '';
        const image3Transform = selectedOption.dataset.image3Transform || '';
        const gridLayout = selectedOption.dataset.gridLayout || 'grid-1';
        const gridBitmap = selectedOption.dataset.gridBitmap || '';

        // ============================================
        // NÄYTÄ ALKUPERÄINEN TIEDOTE (KOMPAKTI)
        // ============================================
        if (originalPreview) {
            originalPreview.classList.remove('hidden');

            // Päivitä tyyppiluokka ja ikoni
            originalPreview.classList.remove('type-red', 'type-yellow');
            originalPreview.classList.add('type-' + originalType);

            const icon = getEl('sf-original-icon');
            if (icon) {
                const card = getEl('sfPreviewCard') || getEl('sfPreviewCardGreen');
                const baseUrl = card?.dataset.baseUrl || window.SF_BASE_URL || '';
                icon.src = `${baseUrl}/assets/img/icon-${originalType}.png`;
            }

            // Päivitä otsikko
            const origTitle = getEl('sf-original-title');
            if (origTitle) origTitle.textContent = title || titleShort || '--';

            // Päivitä työmaa
            const origSite = getEl('sf-original-site');
            if (origSite) origSite.textContent = [site, siteDetail].filter(Boolean).join(' – ') || '--';

            // Päivitä päivämäärä
            const origDate = getEl('sf-original-date');
            if (origDate && date) {
                const dateObj = new Date(date);
                if (!isNaN(dateObj.getTime())) {
                    origDate.textContent = dateObj.toLocaleString(document.documentElement.lang || navigator.language || 'en-US', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    });
                } else {
                    origDate.textContent = '--';
                }
            }
        }

        // ============================================
        // KOPIOI KENTÄT SAMOIHIN KENTTIIN (EI ERILLISIIN)
        // ============================================

        // Työmaa - käytä samaa sf-worksite-kenttää
        const worksiteField = getEl('sf-worksite');
        if (worksiteField) {
            const normalizedSite = String(site || '').trim();
            let matchedValue = '';
            let found = false;

            if (normalizedSite !== '') {
                const options = Array.from(worksiteField.options);

                const exactOption = options.find((option) => {
                    return String(option.value || '').trim() === normalizedSite;
                });

                if (exactOption) {
                    matchedValue = exactOption.value;
                    found = true;
                } else {
                    const caseInsensitiveOption = options.find((option) => {
                        return String(option.value || '').trim().toLocaleLowerCase() === normalizedSite.toLocaleLowerCase();
                    });

                    if (caseInsensitiveOption) {
                        matchedValue = caseInsensitiveOption.value;
                        found = true;
                    }
                }

                if (!found) {
                    const fallbackOption = document.createElement('option');
                    fallbackOption.value = normalizedSite;
                    fallbackOption.textContent = normalizedSite;
                    fallbackOption.selected = true;
                    worksiteField.appendChild(fallbackOption);
                    matchedValue = normalizedSite;
                    found = true;
                }

                worksiteField.value = matchedValue;
            } else {
                worksiteField.value = '';
            }

            const triggerText = getEl('sf-worksite-trigger-text');
            if (triggerText) {
                const placeholder = triggerText.dataset.placeholder || '';
                if (worksiteField.value) {
                    triggerText.textContent = worksiteField.value;
                    triggerText.classList.add('has-value');
                } else {
                    triggerText.textContent = placeholder;
                    triggerText.classList.remove('has-value');
                }
            }

            const chipList = getEl('sf-worksite-chip-list');
            if (chipList) {
                const chips = chipList.querySelectorAll('.sf-worksite-chip-option');
                chips.forEach((chip) => {
                    const isSelected = String(chip.dataset.value || '').trim() === String(worksiteField.value || '').trim();
                    chip.classList.toggle('is-selected', isSelected);
                    chip.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                });
            }

            worksiteField.dispatchEvent(new Event('change', { bubbles: true }));
            worksiteField.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Site detail - käytä samaa sf-site-detail-kenttää
        const siteDetailField = getEl('sf-site-detail');
        if (siteDetailField) siteDetailField.value = siteDetail;

        // Päivämäärä - käytä samaa sf-date-kenttää
        // Backend palauttaa ajan muodossa "YYYY-MM-DD HH:mm:ss" (paikallinen aika)
        // joka on suoraan kelvollinen datetime-local-kentän arvo muunnettuna.
        // EI SAA käyttää new Date() + toISOString() koska se muuntaa UTC:ksi
        // ja aiheuttaa aikavyöhyke-offsetin verran virhettä (esim. -2h Suomessa).
        const dateField = getEl('sf-date');
        if (dateField && date) {
            // Normalisoi muoto: muuta välilyönti T-merkiksi ja ota 16 ensimmäistä merkkiä
            const normalizedDate = date.replace(' ', 'T').slice(0, 16);
            // Validoi että tulos on kelvollinen datetime-local-muoto (YYYY-MM-DDTHH:mm)
            if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalizedDate)) {
                dateField.value = normalizedDate;
            }
        }

        // Mark fields as coming from related flash for clearing logic
        markFieldsFromRelatedFlash();

        // Otsikko ja kuvaus
        const titleField = getEl('sf-title');
        const shortTextField = getEl('sf-short-text');
        const descriptionField = getEl('sf-description');

        if (titleField) titleField.value = title;
        if (shortTextField) shortTextField.value = titleShort;
        if (descriptionField) descriptionField.value = description;

        // ============================================
        // KUVIEN KÄSITTELY
        // ============================================
        const card = getEl('sfPreviewCard') || getEl('sfPreviewCardGreen');
        const baseUrl = card?.dataset.baseUrl || window.SF_BASE_URL || '';
        const placeholder = `${baseUrl}/assets/img/camera-placeholder.png`;
        const getImageUrl = (filename) => {
            if (!filename) return null;
            const dir = filename.startsWith('lib_') ? 'uploads/library' : 'uploads/images';
            return `${baseUrl}/${dir}/${filename}`;
        };

        const updateImage = (slot, filename) => {
            const imgUrl = filename ? getImageUrl(filename) : placeholder;

            // Päivitä thumbnail kuvakorteissa
            const thumb = getEl(`sfImageThumb${slot}`);
            if (thumb) {
                thumb.src = imgUrl;
                thumb.dataset.hasRealImage = filename ? '1' : '0';
                thumb.parentElement?.classList.toggle('has-image', !!filename);
            }

            // Päivitä myös vanhempi upload-preview jos olemassa
            const uploadPreview = getEl(`sf-upload-preview${slot}`);
            if (uploadPreview) {
                uploadPreview.src = imgUrl;
                uploadPreview.parentElement?.classList.toggle('has-image', !!filename);
            }

            // Päivitä esikatselukortit
            const cardImg = getEl(`sfPreviewImg${slot}`);
            if (cardImg) {
                cardImg.src = imgUrl;
                cardImg.dataset.hasRealImage = filename ? '1' : '0';
            }

            const cardImgGreen = getEl(`sfPreviewImg${slot}Green`);
            if (cardImgGreen) {
                cardImgGreen.src = imgUrl;
                cardImgGreen.dataset.hasRealImage = filename ? '1' : '0';
            }

            // Päivitä grid bitmap -kuva (tutkintatiedotteen esikatselu)
            if (slot === 1) {
                const gridBitmapImg = getEl('sfGridBitmapImgGreen');
                if (gridBitmapImg && filename) gridBitmapImg.src = imgUrl;

                const gridBitmapImgMain = getEl('sfGridBitmapImg');
                if (gridBitmapImgMain && filename) gridBitmapImgMain.src = imgUrl;
            }

            // Poista-nappi näkyviin
            const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
            if (removeBtn) {
                removeBtn.classList.toggle('hidden', !filename);
            }
        };

        updateImage(1, imageMain);
        updateImage(2, image2);
        updateImage(3, image3);

        // Tallenna kuvien tiedostonimet hidden-kenttiin
        const setExistingImage = (slot, filename) => {
            let hiddenField = document.getElementById(`sf-existing-image-${slot}`);
            if (!hiddenField) {
                hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = `existing_image_${slot}`;
                hiddenField.id = `sf-existing-image-${slot}`;
                document.getElementById('sf-form')?.appendChild(hiddenField);
            }
            hiddenField.value = filename || '';
        };

        setExistingImage(1, imageMain);
        setExistingImage(2, image2);
        setExistingImage(3, image3);

        // ============================================
        // KOPIOI MERKINNÄT JA TRANSFORMIT
        // ============================================

        // Merkinnät (annotations)
        const annotationsField = document.getElementById('sf-edit-annotations-data');
        if (annotationsField) {
            annotationsField.value = annotationsData;
        }

        // Transform-tiedot
        const transform1 = document.getElementById('sf-image1-transform');
        const transform2 = document.getElementById('sf-image2-transform');
        const transform3 = document.getElementById('sf-image3-transform');

        if (transform1) transform1.value = image1Transform;
        if (transform2) transform2.value = image2Transform;
        if (transform3) transform3.value = image3Transform;

        // Grid-asettelu
        const gridLayoutField = document.getElementById('sf-grid-layout');
        if (gridLayoutField) gridLayoutField.value = gridLayout;

        const gridBitmapField = document.getElementById('sf-grid-bitmap');
        if (gridBitmapField) gridBitmapField.value = gridBitmap;

        // ============================================
        // PÄIVITÄ KUVAKORTTIEN UI (LATAA -> MUOKKAA)
        // ============================================
        // Map slot numbers to actual image filenames
        const imageFilenames = [imageMain, image2, image3];

        setTimeout(() => {
            [1, 2, 3].forEach((slot) => {
                const filename = imageFilenames[slot - 1];
                const hasImage = Boolean(filename && filename !== '');

                // Käytä globaalia funktiota jos saatavilla
                if (typeof window.sfUpdateImageCardUI === 'function') {
                    // Varmista että badge päivittyy oikein
                    // Badge pitäisi näkyä VAIN jos:
                    // 1. Kuva on olemassa JA
                    // 2. Sillä on transformia tai annotaatioita
                    window.sfUpdateImageCardUI(slot);
                    return;
                }

                // Fallback: päivitä CTA-napin tila manuaalisesti
                const slotCard = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
                const thumb = document.getElementById(`sfImageThumb${slot}`);
                const cta = slotCard?.querySelector('.sf-image-upload-btn');
                const ctaText = cta?.querySelector('span');

                if (thumb && cta && ctaText && hasImage) {
                    cta.classList.add('sf-cta-edit');
                    cta.dataset.mode = 'edit';
                    ctaText.textContent = getI18n('edit_image', 'Edit');

                    // Lisää has-image luokka
                    slotCard?.classList.add('has-image');
                    thumb.parentElement?.classList.add('has-image');
                }
            });
        }, 50);

        // Päivitä previewit
        setTimeout(() => {
            updatePreview();
            window.Preview?.applyGridClass?.();
            window.PreviewTutkinta?.applyGridClass?.();
            window.PreviewTutkinta?.updatePreviewContent?.();

            // Update progress indicators after all updates are complete
            if (window.SFUpdateProgress) {
                window.SFUpdateProgress();
            }
        }, 100);
    });
}