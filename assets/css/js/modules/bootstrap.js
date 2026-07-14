// assets/js/modules/bootstrap.js

import { state, getters, setSelectedLang, setSelectedType } from './state.js';
import { updatePreview, updatePreviewLabels, handleConditionalFields } from './preview-update.js';
import { validateStep, showValidationErrors } from './validation.js';
import { showStep, bindStepButtons } from './navigation.js';
import { bindUploads } from './uploads.js';
import { bindMainImageSwitcher } from './main-image-switcher.js';
import { bindRelatedFlash } from './related-flash.js';
import { bindSubmit } from './submit.js';
import { autoSave } from './autosave.js';
import { initSupervisorApproval } from './supervisor-approval.js';
import { initInvestigationContextUI } from './investigation-context.js';
import { initMarkdownToolbar } from './markdown-toolbar.js';

// Importataan preview-moduulit
import { Preview } from './preview-core.js';
import { PreviewTutkinta } from './preview-tutkinta.js';

// NEW: Import server-side preview (optional, can be enabled via config)
import { ServerPreview } from './preview-server.js';

const { getEl, qsa, qs } = getters;

// =====================================================
// CONSTANTS
// =====================================================

// Maximum number of images per flash (main image + 2 additional images)
const IMAGE_SLOTS = [1, 2, 3];

// Mobile breakpoint for responsive behavior (matches CSS @media max-width)
const MOBILE_BREAKPOINT = 768;

// =====================================================
// 1) APUTOIMINNOT
// =====================================================

function updateStep1NextButton() {
    const nextBtn = getEl('sfNext');
    if (!nextBtn) return;

    // Check ACTUAL DOM state, not internal state
    const langRadio = document.querySelector('input[name="lang"]:checked');
    const typeRadio = document.querySelector('input[name="type"]:checked');

    // Must have BOTH type AND language explicitly selected (radio checked)
    const hasLang = !!langRadio;
    const hasType = !!typeRadio;

    if (hasLang && hasType) {
        nextBtn.disabled = false;
        nextBtn.classList.remove('disabled');
        nextBtn.removeAttribute('aria-disabled');
    } else {
        nextBtn.disabled = true;
        nextBtn.classList.add('disabled');
        nextBtn.setAttribute('aria-disabled', 'true');
    }
}
function initWorksiteChips() {
    const select = getEl('sf-worksite');
    const chipList = getEl('sf-worksite-chip-list');
    const picker = getEl('sf-worksite-picker');
    const panel = getEl('sf-worksite-chip-panel');
    const trigger = getEl('sf-worksite-trigger');
    const triggerText = getEl('sf-worksite-trigger-text');
    const searchInput = getEl('sf-worksite-search');
    const clearButton = getEl('sfClearWorksiteSearch');
    const emptyState = getEl('sf-worksite-chip-empty');

    if (!select) {
        return;
    }

    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    if (isMobile) {
        if (!select.dataset.chipSyncBound) {
            select.addEventListener('change', () => {
                if (!triggerText) {
                    return;
                }

                const selectedValue = select.value || '';
                const placeholder = triggerText.dataset.placeholder || '';

                if (selectedValue !== '') {
                    triggerText.textContent = selectedValue;
                    triggerText.classList.add('has-value');
                } else {
                    triggerText.textContent = placeholder;
                    triggerText.classList.remove('has-value');
                }
            });

            select.dataset.chipSyncBound = '1';
        }

        return;
    }

    if (!chipList || !picker || !panel) {
        return;
    }

    const chips = Array.from(chipList.querySelectorAll('.sf-worksite-chip-option'));
    const isLocked = picker.dataset.disabled === '1' || select.disabled;

    function updateTriggerText() {
        if (!triggerText) {
            return;
        }

        const selectedValue = select.value || '';
        const placeholder = triggerText.dataset.placeholder || '';

        if (selectedValue !== '') {
            triggerText.textContent = selectedValue;
            triggerText.classList.add('has-value');
        } else {
            triggerText.textContent = placeholder;
            triggerText.classList.remove('has-value');
        }
    }

    function setPanelOpen(isOpen) {
        const nextOpen = isLocked ? false : isOpen;

        picker.classList.toggle('is-open', nextOpen);
        panel.classList.toggle('is-open', nextOpen);

        if (panel) {
            panel.hidden = !nextOpen;
            panel.setAttribute('aria-hidden', nextOpen ? 'false' : 'true');
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
        }
    }

    function syncSelectedState() {
        const selectedValue = select.value || '';

        chips.forEach((chip) => {
            const selected = (chip.dataset.value || '') === selectedValue;
            chip.classList.toggle('is-selected', selected);
            chip.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        updateTriggerText();
    }

    function updateEmptyState() {
        if (!emptyState) {
            return;
        }

        const hasVisible = chips.some((chip) => !chip.classList.contains('sf-hidden'));
        emptyState.hidden = hasVisible;
    }

    function filterChips() {
        const term = (searchInput?.value || '').trim().toLocaleLowerCase();

        chips.forEach((chip) => {
            const haystack = ((chip.dataset.search || chip.textContent || '') + '').toLocaleLowerCase();
            const match = term === '' || haystack.includes(term);
            chip.classList.toggle('sf-hidden', !match);
        });

        if (clearButton) {
            clearButton.hidden = term === '';
        }

        if (term !== '' && !isLocked) {
            setPanelOpen(true);
        }

        updateEmptyState();
    }

    function handleTriggerClick(event) {
        event.preventDefault();
        event.stopPropagation();

        if (isLocked) {
            return;
        }

        const isOpen = panel.classList.contains('is-open');
        setPanelOpen(!isOpen);

        if (!isOpen && searchInput) {
            setTimeout(() => {
                searchInput.focus();
            }, 0);
        }
    }

    function handleChipClick(event) {
        event.preventDefault();
        event.stopPropagation();

        if (isLocked) {
            return;
        }

        const chip = event.currentTarget;
        const nextValue = chip?.dataset?.value || '';

        if (!nextValue) {
            return;
        }

        select.value = nextValue;
        syncSelectedState();

        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));

        setPanelOpen(false);
    }

    function handleClearClick(event) {
        event.preventDefault();
        event.stopPropagation();

        if (!searchInput) {
            return;
        }

        searchInput.value = '';
        filterChips();
        searchInput.focus();
    }

    function handleOutsideClick(event) {
        if (isLocked) {
            return;
        }

        if (!picker.contains(event.target)) {
            setPanelOpen(false);
        }
    }

    if (isLocked) {
        chips.forEach((chip) => {
            chip.classList.add('is-disabled');
            chip.setAttribute('aria-disabled', 'true');
            chip.setAttribute('tabindex', '-1');
        });

        if (searchInput) {
            searchInput.value = '';
            searchInput.setAttribute('readonly', 'readonly');
            searchInput.setAttribute('aria-readonly', 'true');
            searchInput.tabIndex = -1;
        }

        if (clearButton) {
            clearButton.hidden = true;
        }

        if (emptyState) {
            emptyState.hidden = true;
        }

        panel.hidden = true;
        panel.setAttribute('aria-hidden', 'true');
        panel.classList.remove('is-open');
        picker.classList.remove('is-open');

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            trigger.setAttribute('aria-disabled', 'true');
        }

        setPanelOpen(false);
    }

    if (trigger && !trigger.dataset.bound) {
        trigger.addEventListener('click', handleTriggerClick);
        trigger.dataset.bound = '1';
    }

    chips.forEach((chip) => {
        if (!chip.dataset.bound) {
            chip.addEventListener('click', handleChipClick);
            chip.dataset.bound = '1';
        }
    });

    if (searchInput && !searchInput.dataset.bound) {
        searchInput.addEventListener('input', filterChips);
        searchInput.dataset.bound = '1';
    }

    if (clearButton && !clearButton.dataset.bound) {
        clearButton.addEventListener('click', handleClearClick);
        clearButton.dataset.bound = '1';
    }

    if (!select.dataset.chipSyncBound) {
        select.addEventListener('change', syncSelectedState);
        select.dataset.chipSyncBound = '1';
    }

    if (!select.dataset.chipCollapseBound) {
        select.addEventListener('change', () => {
            if (!isLocked) {
                setPanelOpen(false);
            }
        });
        select.dataset.chipCollapseBound = '1';
    }

    if (!picker.dataset.outsideClickBound) {
        document.addEventListener('click', handleOutsideClick);
        picker.dataset.outsideClickBound = '1';
    }

    syncSelectedState();
    filterChips();

    if (!isLocked) {
        setPanelOpen(false);
    }
}
/**
 * Huom: Smooth-navigaatiossa DOM vaihtuu, joten
 * (a) elementtikohtaisia eventtejä ei kannata sitoa "kerran ja valmis" -mallilla
 * (b) document-tason delegointi toimii aina
 */

// =====================================================
// 2) EVENT-DELEGOINNIT (SITOUDUTAAN VAIN KERRAN)
// =====================================================

let documentDelegationBound = false;

function bindDocumentDelegationOnce() {
    if (documentDelegationBound) return;
    documentDelegationBound = true;

    // Document-level event delegation for form interactions
    document.addEventListener('click', (e) => {
        // ÄLÄ anna clickin käsitellä worksite dropdownia
        if (e.target.closest('#sf-worksite-picker')) {
            return;
        }
        // Kieli-valinta (delegointi)
        const langBox = e.target.closest('.sf-lang-box');
        if (langBox) {
            e.preventDefault();
            e.stopPropagation();

            const radio = langBox.querySelector('input[type="radio"]');
            if (!radio) return;

            qsa('input[name="lang"]').forEach((r) => (r.checked = false));
            radio.checked = true;

setSelectedLang(radio.value);

qsa('.sf-lang-box').forEach((b) => b.classList.remove('selected'));
langBox.classList.add('selected');

document.dispatchEvent(new CustomEvent('sf:flash-language-changed', {
    detail: {
        lang: radio.value
    }
}));

updatePreviewLabels();
updatePreview();
updateStep1NextButton();
return;
        }

        // Tyyppi-valinta (delegointi)
        const typeBox = e.target.closest('.sf-type-box');
        if (typeBox) {
            e.preventDefault();
            e.stopPropagation();

            const radio = typeBox.querySelector('input[type="radio"]');
            if (!radio) return;

            qsa('input[name="type"]').forEach((r) => (r.checked = false));
            radio.checked = true;

            setSelectedType(radio.value);

            qsa('.sf-type-box').forEach((b) => b.classList.remove('selected'));
            typeBox.classList.add('selected');

            const formEl = getEl('sf-form');
            if (formEl) {
                formEl.classList.remove('type-red', 'type-yellow', 'type-green');
                formEl.classList.add('type-' + state.selectedType);
            }

            const progressEl = qs('.sf-form-progress');
            if (progressEl) {
                progressEl.classList.remove('type-red', 'type-yellow', 'type-green');
                progressEl.classList.add('type-' + state.selectedType);
            }

            handleConditionalFields();
            updatePreview();
            updateStep1NextButton();

            // Issue 3: Auto-scroll to language selection after type is selected (mobile UX improvement)
            // Increased timeout to ensure animations complete before scrolling
            setTimeout(() => {
                const langSection = document.getElementById('sf-lang-selection');
                if (langSection && window.innerWidth <= MOBILE_BREAKPOINT) {
                    // Use 'nearest' for better mobile behavior and add offset for fixed headers
                    langSection.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
                }
            }, 500);

            // Initialize investigation context UI when type changes to green
            if (radio.value === 'green') {
                setTimeout(() => {
                    initInvestigationContextUI();
                }, 100);
            }
            return;
        }

        // Grid-napit (delegointi)
        const gridBtn = e.target.closest('.sf-grid-btn');
        if (gridBtn) {
            const forCount = gridBtn.getAttribute('data-for');
            const container = gridBtn.closest('.sf-grid-buttons');

            if (container) {
                container.querySelectorAll('.sf-grid-btn').forEach((b) => {
                    if (b.getAttribute('data-for') === forCount) b.classList.remove('active');
                });
            }
            gridBtn.classList.add('active');

            const isGreen = gridBtn.closest('#sfGridSelectorGreen') !== null;
            const gridType = gridBtn.dataset.grid;

            if (isGreen && window.PreviewTutkinta) {
                window.PreviewTutkinta.applyGridClass(gridType);
            } else if (!isGreen && window.Preview) {
                window.Preview.applyGridClass(gridType);
            }
            return;
        }

        // Tools-tabit (delegointi)
        const toolsTab = e.target.closest('.sf-tools-tab');
        if (toolsTab) {
            const targetPanel = toolsTab.getAttribute('data-panel');
            const parentTabs = toolsTab.closest('.sf-tools-tabs');

            if (parentTabs) {
                parentTabs.querySelectorAll('.sf-tools-tab').forEach((t) => t.classList.remove('active'));
            }
            toolsTab.classList.add('active');

            const panels = document.querySelectorAll('.sf-tools-panel');
            const isGreen = targetPanel && targetPanel.includes('Green');

            panels.forEach((panel) => {
                const panelId = panel.getAttribute('data-panel');
                const panelIsGreen = panelId && panelId.includes('Green');

                if (isGreen === panelIsGreen) {
                    if (panelId === targetPanel) panel.classList.add('active');
                    else panel.classList.remove('active');
                }
            });

            return;
        }

    });

    // Preview-kenttien input (delegointi)
    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLElement)) return;

        if (
            el.matches(
                '#sf-short-text, #sf-description, #sf-date, #sf-worksite, #sf-site-detail, #sf-root-causes, #sf-actions'
            )
        ) {
            updatePreview();
        }
    });
}

// =====================================================
// 3) CHAR COUNTERIT (AJA PER PAGE-RENDER, EI DUPLICOIDA)
// =====================================================

function initCharCounters() {
    const fieldsWithLimits = [
        { id: 'sf-short-text', max: 85, lineBreakCost: 0 },
        { id: 'sf-description', max: 950, lineBreakCost: 50 },
        { id: 'sf-root-causes', max: 800, lineBreakCost: 30 },
        { id: 'sf-actions', max: 800, lineBreakCost: 30 }
    ];

    fieldsWithLimits.forEach(({ id, max, lineBreakCost }) => {
        const field = getEl(id);
        if (!field) return;

        // Jos counter jo olemassa tässä DOMissa, älä lisää uutta
        const existing = getEl(id + '-counter');
        let counter = existing;

        if (!counter) {
            counter = document.createElement('div');
            counter.className = 'sf-char-counter';
            counter.id = id + '-counter';
            field.parentElement?.appendChild(counter);
        }

        function calculateUsed() {
            const text = field.value || '';
            const charCount = text.length;
            const lineBreaks = (text.match(/\n/g) || []).length;
            return charCount + lineBreaks * lineBreakCost;
        }

        function enforceLimit() {
            let text = field.value || '';
            let used = calculateUsed();

            while (used > max && text.length > 0) {
                text = text.slice(0, -1);
                const lineBreaks = (text.match(/\n/g) || []).length;
                used = text.length + lineBreaks * lineBreakCost;
            }

            if (text !== field.value) {
                const cursorPos = field.selectionStart ?? text.length;
                field.value = text;
                field.setSelectionRange(
                    Math.min(cursorPos, text.length),
                    Math.min(cursorPos, text.length)
                );
            }
        }

        function updateCounter() {
            const used = calculateUsed();
            const remaining = max - used;

            counter.textContent = `${used} / ${max}`;
            counter.classList.remove('sf-counter-warning', 'sf-counter-error');

            if (remaining <= 0) counter.classList.add('sf-counter-error');
            else if (remaining < max * 0.1) counter.classList.add('sf-counter-warning');
        }

        // HUOM: nämä listenerit kiinnittyvät fieldiin joka kuuluu nyky-DOMiin
        field.addEventListener('input', () => {
            enforceLimit();
            updateCounter();
        });

        field.addEventListener('paste', () => {
            setTimeout(() => {
                enforceLimit();
                updateCounter();
            }, 0);
        });

        updateCounter();
    });
}

// =====================================================
// 4) FORM-SIVUN INIT (AJA DOMContentLoaded + sf:pagechange)
// =====================================================



function setPreviewBaseUrl() {
    try {
        const base = typeof SF_BASE_URL !== 'undefined' ? SF_BASE_URL.replace(/\/$/, '') : '';

        const card = getEl('sfPreviewCard');
        const cardGreen = getEl('sfPreviewCardGreen');

        if (card && base) card.dataset.baseUrl = base;
        if (cardGreen && base) cardGreen.dataset.baseUrl = base;
    } catch (e) {
        console.warn('SF_BASE_URL ei määritelty:', e);
    }
}

function initSelectionsFromDOM() {
    const checkedLangOnLoad = qs('input[name="lang"]:checked');
    if (checkedLangOnLoad) {
        setSelectedLang(checkedLangOnLoad.value);
        qsa('.sf-lang-box').forEach((box) => box.classList.remove('selected'));
        checkedLangOnLoad.closest('.sf-lang-box')?.classList.add('selected');
    }

    const checkedTypeOnLoad = qs('input[name="type"]:checked');
    if (checkedTypeOnLoad) {
        setSelectedType(checkedTypeOnLoad.value);
        qsa('.sf-type-box').forEach((box) => box.classList.remove('selected'));
        checkedTypeOnLoad.closest('.sf-type-box')?.classList.add('selected');

        const formEl = getEl('sf-form');
        if (formEl) {
            formEl.classList.remove('type-red', 'type-yellow', 'type-green');
            formEl.classList.add('type-' + state.selectedType);
        }

        const progressEl = qs('.sf-form-progress');
        if (progressEl) {
            progressEl.classList.remove('type-red', 'type-yellow', 'type-green');
            progressEl.classList.add('type-' + state.selectedType);
        }
    }

    // KORJAUS: Kutsu handleConditionalFields() AINA, ei vain kun tyyppi on valittuna
    // Tämä varmistaa, että sf-step2-incident on piilotettu kun tyyppi ei ole green
    handleConditionalFields();

    // Initialize investigation context UI for green type
    if (state.selectedType === 'green') {
        setTimeout(() => {
            initInvestigationContextUI();
        }, 100);
    }
}

function initSteps() {
    const initialStepInput = getEl('initialStep');
    const startStep = initialStepInput ? parseInt(initialStepInput.value, 10) : 1;
    showStep(isNaN(startStep) || startStep < 1 ? 1 : startStep, true);
}

function isFormPageNow() {
    // index.php asettaa <body data-page="form">
    return document.body && document.body.dataset && document.body.dataset.page === 'form';
}

/**
 * Load existing flash data when editing
 * Reads PHP-rendered values and populates the form + preview
 * BUG FIX 2: Improved edit mode detection - works regardless of URL mode parameter
 */
function loadExistingFlashData() {
    console.log('[Bootstrap] Loading existing flash data...');

    const formEl = getEl('sf-form');
    if (!formEl) return;

    // BUG FIX 2: Better edit mode detection
    // Check for ID field in form (more reliable than URL params)
    const idField = formEl.querySelector('input[name="id"]');
    const editId = idField?.value ? parseInt(idField.value, 10) : 0;

    if (!editId || editId <= 0) {
        console.log('[Bootstrap] Not in edit mode (no valid ID), skipping data load');
        return;
    }

    console.log('[Bootstrap] Edit mode detected, ID:', editId);

    // Additional check: verify we have data to load
    // Check if any form fields have pre-populated values
    const hasExistingData =
        (getEl('sf-title')?.value || '').length > 0 ||
        (getEl('sf-worksite')?.value || '').length > 0 ||
        (getEl('sf-existing-image-1')?.value || '').length > 0;

    if (!hasExistingData) {
        console.warn('[Bootstrap] Edit mode detected but no data found - may need to reload');
        // Still proceed to try loading what we can
    }

    // Use global SF_BASE_URL instead of reading from DOM elements
    // (old preview elements sfPreviewCard/sfPreviewCardGreen no longer exist after server-side preview migration)
    const baseUrl = (typeof SF_BASE_URL !== 'undefined' ? SF_BASE_URL : '').replace(/\/$/, '');
    const placeholder = `${baseUrl}/assets/img/camera-placeholder.png`;
    const getImageUrl = (filename) => {
        if (!filename) return null;
        const dir = filename.startsWith('lib_') ? 'uploads/library' : 'uploads/images';
        return `${baseUrl}/${dir}/${filename}`;
    };

    // Load worksite (already selected by PHP)
    const worksiteField = getEl('sf-worksite');
    if (worksiteField && worksiteField.value) {
        console.log('[Bootstrap] Worksite populated:', worksiteField.value);
    }

    // Load site detail (already has value from PHP)
    const siteDetailField = getEl('sf-site-detail');
    if (siteDetailField && siteDetailField.value) {
        console.log('[Bootstrap] Site detail populated:', siteDetailField.value);
    }

    // Load date (already has value from PHP)
    const dateField = getEl('sf-date');
    if (dateField && dateField.value) {
        console.log('[Bootstrap] Date populated:', dateField.value);
    }

    // Load images from hidden fields
    let imagesLoaded = 0;
    IMAGE_SLOTS.forEach((slot) => {
        const hiddenField = getEl(`sf-existing-image-${slot}`);
        const filename = hiddenField?.value || '';

        if (filename) {
            imagesLoaded++;
            const imgUrl = getImageUrl(filename);

            console.log(`[Bootstrap] Loaded image ${slot}: ${filename}`);

            // Update thumbnail in upload card
            const thumb = getEl(`sfImageThumb${slot}`);
            if (thumb) {
                thumb.src = imgUrl;
                thumb.dataset.hasRealImage = '1';
                thumb.parentElement?.classList.add('has-image');
            }

            // Update preview card images
            const cardImg = getEl(`sfPreviewImg${slot}`);
            if (cardImg) {
                cardImg.src = imgUrl;
                cardImg.dataset.hasRealImage = '1';
            }

            const cardImgGreen = getEl(`sfPreviewImg${slot}Green`);
            if (cardImgGreen) {
                cardImgGreen.src = imgUrl;
                cardImgGreen.dataset.hasRealImage = '1';
            }

            // Update grid bitmap image (for investigation reports)
            if (slot === 1) {
                const gridBitmapImg = getEl('sfGridBitmapImgGreen');
                if (gridBitmapImg) gridBitmapImg.src = imgUrl;

                const gridBitmapImgMain = getEl('sfGridBitmapImg');
                if (gridBitmapImgMain) gridBitmapImgMain.src = imgUrl;
            }

            // Show remove button
            const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
            if (removeBtn) {
                removeBtn.classList.remove('hidden');
            }

            // Show edit button
            const editBtn = document.querySelector(`.sf-image-edit-inline-btn[data-slot="${slot}"]`);
            if (editBtn) {
                editBtn.classList.remove('hidden');
                editBtn.disabled = false;
            }
        }
    });

    console.log('[Bootstrap] Images loaded:', imagesLoaded);

    // Trigger preview update to sync all fields
    try {
        if (typeof updatePreview === 'function') {
            updatePreview();
        }
    } catch (e) {
        console.warn('[Bootstrap] Failed to update preview:', e);
    }

    // BUG FIX 1: Setup PreviewTutkinta in edit mode for green type
    // When editing an existing tutkintatiedote (green type), PreviewTutkinta needs to be
    // set up early so that the two-slides notification and character counters work correctly
    const typeRadio = document.querySelector('input[name="type"]:checked');
    const currentType = typeRadio?.value || state.selectedType;

    if (currentType === 'green') {
        console.log('[Bootstrap] Setting up PreviewTutkinta for edit mode (green type)');

        // Bind form event listeners immediately (doesn't require card in DOM)
        if (window.PreviewTutkinta && typeof window.PreviewTutkinta._bindFormEvents === 'function') {
            // Ensure _tutkintaEventsBound doesn't prevent binding
            window.PreviewTutkinta._tutkintaEventsBound = false;
            window.PreviewTutkinta._bindFormEvents();
            console.log('[Bootstrap] PreviewTutkinta form events bound');
        }

        // Update two-slides notice if it exists in DOM
        // (notice element exists in step 3, even though preview card doesn't)
        const notice = document.getElementById('sfTwoSlidesNotice');
        if (notice && window.PreviewTutkinta) {
            // Update two-slides state based on existing field values
            const title = document.getElementById('sf-short-text')?.value || '';
            const desc = document.getElementById('sf-description')?.value || '';
            const rootCauses = document.getElementById('sf-root-causes')?.value || '';
            const actions = document.getElementById('sf-actions')?.value || '';

            const useTwoSlides = window.PreviewTutkinta._shouldUseTwoSlides(title, desc, rootCauses, actions);
            notice.style.display = useTwoSlides ? 'flex' : 'none';

            console.log('[Bootstrap] Two slides notice updated, useTwoSlides:', useTwoSlides);
        }
    }

    console.log('[Bootstrap] Existing flash data loaded successfully');
}

/**
 * Initialize form in translation mode.
 * In translation mode, type and language are pre-selected from source flash.
 * This function ensures the visual state is correct and step 1 validation passes.
 * 
 * @returns {void}
 */
function initTranslationMode() {
    if (!window.SF_TRANSLATION_MODE) return;

    console.log('[Bootstrap] Initializing translation mode');

    // Ensure type and language selections are visually active
    // The radio buttons are already checked via PHP, we just need to apply visual states

    // Update type selection visual state
    const checkedType = qs('input[name="type"]:checked');
    if (checkedType) {
        const typeBox = checkedType.closest('.sf-type-box');
        if (typeBox) {
            qsa('.sf-type-box').forEach((b) => b.classList.remove('selected'));
            typeBox.classList.add('selected');
            console.log('[Bootstrap] Translation mode: type selected =', checkedType.value);
        }
    }

    // Update language selection visual state
    const checkedLang = qs('input[name="lang"]:checked');
    if (checkedLang) {
        const langBox = checkedLang.closest('.sf-lang-box');
        if (langBox) {
            qsa('.sf-lang-box').forEach((b) => b.classList.remove('selected'));
            langBox.classList.add('selected');
            console.log('[Bootstrap] Translation mode: language selected =', checkedLang.value);
        }
    }

    // Update form type class
    if (checkedType) {
        const formEl = getEl('sf-form');
        if (formEl) {
            formEl.classList.remove('type-red', 'type-yellow', 'type-green');
            formEl.classList.add('type-' + checkedType.value);
        }

        const progressEl = qs('.sf-form-progress');
        if (progressEl) {
            progressEl.classList.remove('type-red', 'type-yellow', 'type-green');
            progressEl.classList.add('type-' + checkedType.value);
        }
    }

    // Ensure conditional fields are shown/hidden correctly
    // In translation mode, treat it like edit mode - show all populated fields
    handleConditionalFields();

    // Enable Next button on step 1 since both type and language are pre-selected
    updateStep1NextButton();

    console.log('[Bootstrap] Translation mode initialized successfully');
}

function initSiteDetailSuggestions() {
    const worksiteField = getEl('sf-worksite');
    const siteDetailField = getEl('sf-site-detail');
    const suggestionsPanel = getEl('sf-site-detail-suggestions');
    const emptyState = getEl('sf-site-detail-suggestions-empty');
    const toggleButton = getEl('sf-site-detail-suggestions-toggle');

    if (!worksiteField || !siteDetailField || !suggestionsPanel) {
        return;
    }

    if (siteDetailField.dataset.suggestionsBound === '1') {
        return;
    }

    siteDetailField.dataset.suggestionsBound = '1';

    let debounceTimer = null;
    let abortController = null;
    let latestSuggestions = [];

    function getBaseUrl() {
        return (typeof SF_BASE_URL !== 'undefined' ? SF_BASE_URL : '').replace(/\/$/, '');
    }

    function getSelectedWorksite() {
        return String(worksiteField.value || '').trim();
    }

    function updateToggleState() {
        if (!toggleButton) {
            return;
        }

        const hasWorksite = getSelectedWorksite() !== '';

        toggleButton.disabled = !hasWorksite;

        if (hasWorksite) {
            toggleButton.removeAttribute('title');
        } else {
            toggleButton.setAttribute('title', 'Valitse ensin työmaa');
        }
    }

    function closeSuggestions() {
        suggestionsPanel.hidden = true;
        suggestionsPanel.innerHTML = '';
        siteDetailField.setAttribute('aria-expanded', 'false');
    }

    function renderSuggestions(items) {
        latestSuggestions = Array.isArray(items) ? items : [];
        suggestionsPanel.innerHTML = '';

        if (emptyState) {
            emptyState.hidden = true;
        }

        if (latestSuggestions.length === 0) {
            closeSuggestions();

            if (emptyState && getSelectedWorksite() !== '' && siteDetailField.value.trim() === '') {
                emptyState.hidden = false;
            }

            return;
        }

        const titleText = suggestionsPanel.dataset.title || 'Aiemmin käytetyt';

        const title = document.createElement('div');
        title.className = 'sf-site-detail-suggestions-title';
        title.textContent = titleText;

        suggestionsPanel.appendChild(title);

        latestSuggestions.forEach((item) => {
            const label = String(item.label || '').trim();

            if (label === '') {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sf-site-detail-suggestion-option';
            button.dataset.value = label;

            const labelSpan = document.createElement('span');
            labelSpan.className = 'sf-site-detail-suggestion-label';
            labelSpan.textContent = label;

            const countSpan = document.createElement('span');
            countSpan.className = 'sf-site-detail-suggestion-count';
            countSpan.textContent = String(item.usage_count || 0);

            button.appendChild(labelSpan);
            button.appendChild(countSpan);

            button.addEventListener('click', () => {
                siteDetailField.value = label;
                siteDetailField.dispatchEvent(new Event('input', { bubbles: true }));
                siteDetailField.dispatchEvent(new Event('change', { bubbles: true }));
                closeSuggestions();
                siteDetailField.focus();
            });

            suggestionsPanel.appendChild(button);
        });

        suggestionsPanel.hidden = false;
        siteDetailField.setAttribute('aria-expanded', 'true');
    }

    function fetchSuggestions() {
        const site = getSelectedWorksite();
        const query = siteDetailField.value.trim();

        if (emptyState) {
            emptyState.hidden = true;
        }

        if (site === '') {
            closeSuggestions();
            updateToggleState();
            return;
        }

        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();

        const params = new URLSearchParams();
        const selectedLang = document.querySelector('input[name="lang"]:checked')?.value || '';

        params.set('site', site);

        if (selectedLang !== '') {
            params.set('lang', selectedLang);
        }

        if (query !== '') {
            params.set('q', query);
        }

        fetch(`${getBaseUrl()}/app/api/site-detail-suggestions.php?${params.toString()}`, {
            signal: abortController.signal
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data || data.ok !== true) {
                    closeSuggestions();
                    return;
                }

                renderSuggestions(data.suggestions || []);
            })
            .catch((error) => {
                if (error && error.name === 'AbortError') {
                    return;
                }

                closeSuggestions();
            });
    }

    function scheduleFetchSuggestions() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchSuggestions, 180);
    }

    siteDetailField.addEventListener('input', () => {
        const value = siteDetailField.value.trim();

        if (value.length < 2) {
            closeSuggestions();
            return;
        }

        scheduleFetchSuggestions();
    });

    if (toggleButton && !toggleButton.dataset.bound) {
        toggleButton.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const site = getSelectedWorksite();

            if (site === '') {
                siteDetailField.focus();
                updateToggleState();
                return;
            }

            if (!suggestionsPanel.hidden) {
                closeSuggestions();
                return;
            }

            fetchSuggestions();
            siteDetailField.focus();
        });

        toggleButton.dataset.bound = '1';
    }

    siteDetailField.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSuggestions();
        }
    });

    worksiteField.addEventListener('change', () => {
        closeSuggestions();
        updateToggleState();
    });

    document.addEventListener('click', (event) => {
        if (
            event.target === siteDetailField ||
            event.target === toggleButton ||
            suggestionsPanel.contains(event.target)
        ) {
            return;
        }

        closeSuggestions();
    });

    updateToggleState();
}

export function initFormPage() {
    if (!isFormPageNow()) return;

    // Delegointi kerran koko applikaation elinkaaren aikana
    bindDocumentDelegationOnce();

    // “Per render” initit
    setPreviewBaseUrl();
    initSelectionsFromDOM();

    // Load existing data in edit mode BEFORE other initializations
    loadExistingFlashData();

    // Initialize modern worksite chip selector
    initWorksiteChips();

    // Initialize frequently used detailed-location suggestions
    initSiteDetailSuggestions();

    updateStep1NextButton();
    initSteps();

    // Nämä sitovat eventtejä suoraan DOM-elementteihin (ok koska DOM vaihtuu),
    // ja/tai tekevät muuta sivukohtaista init-logiikkaa
    bindUploads();
    bindMainImageSwitcher();
    bindRelatedFlash();
    bindSubmit();
    initCharCounters();
    initMarkdownToolbar();
    bindStepButtons();

    // Initialize supervisor approval
    try {
        initSupervisorApproval();
    } catch (_) { }

    // Initialize autosave
    try {
        autoSave.init();
    } catch (_) { }

    // Jos preview tarvitsee kerran "pakota päivitys" initissä:
    try {
        updatePreviewLabels();
        updatePreview();
    } catch (_) { }

    // NEW: Initialize server-side preview if enabled
    try {
        const serverPreviewSection = document.getElementById('sfServerPreviewSection');
        if (serverPreviewSection) {
            if (!(window.sfServerPreview && window.sfServerPreview.__sf_inited)) {
                const form = document.getElementById('sf-form')
                    || document.getElementById('sfForm')
                    || document.querySelector('form.sf-form');
                const previewContainer = document.getElementById('sfServerPreviewWrapper');

                if (form && previewContainer) {
                    const serverPreview = new ServerPreview({
                        endpoint: '/app/api/preview.php',
                        debounce: 500,
                        container: previewContainer,
                        form: form
                    });
                    serverPreview.init();
                    serverPreview.__sf_inited = true;
                    window.sfServerPreview = serverPreview;
                    console.log('[Bootstrap] Server-side preview initialized');
                }
            }
        }
    } catch (e) {
        console.warn('[Bootstrap] Server-side preview initialization failed:', e);
    }

    // UUSI: Lataa merkinnät esikatseluun muokkaustilassa
    try {
        loadAnnotationsToPreview();
    } catch (_) { }
}

/**
 * Lataa tallennetut merkinnät esikatseluun muokkaustilassa. 
 * Tämä varmistaa, että merkinnät näkyvät heti kun sivu avataan,
 * eikä käyttäjän tarvitse avata kuvaeditooria.
 */
function loadAnnotationsToPreview() {
    const storeEl = getEl('sf-edit-annotations-data');
    if (!storeEl || !storeEl.value) return;

    let stored;
    try {
        stored = JSON.parse(storeEl.value);
    } catch (e) {
        return;
    }

    if (!stored || typeof stored !== 'object') return;

    // Muunna sf-edit-annotations-data → sf-annotations-data muotoon
    // sf-edit-annotations-data:  {"image1": [...], "image2": [...], "image3": [...]}
    // sf-annotations-data: [{... ann, frameId: "sfPreviewImageFrame1"}, ...]
    const allAnnotations = [];

    [1, 2, 3].forEach(slot => {
        const key = `image${slot}`;
        const slotAnnotations = stored[key];

        if (Array.isArray(slotAnnotations) && slotAnnotations.length > 0) {
            slotAnnotations.forEach(ann => {
                // Varmista frameId
                const frameId = ann.frameId || `sfPreviewImageFrame${slot}`;
                allAnnotations.push({
                    ...ann,
                    frameId: frameId,
                    slot: slot
                });
            });
        }
    });

    if (allAnnotations.length === 0) return;

    // Aseta sf-annotations-data -kenttään
    const targetEl = getEl('sf-annotations-data');
    if (targetEl) {
        targetEl.value = JSON.stringify(allAnnotations);
    }

    // Alusta Annotations-moduuli, joka renderöi merkinnät
    if (window.Annotations && typeof window.Annotations.init === 'function') {
        // Pieni viive jotta DOM ehtii renderöityä
        setTimeout(() => {
            window.Annotations.init();
        }, 100);
    }
}

// =====================================================
// 5) KÄYNNISTYS: ENSILATAUS + SMOOTH-NAV PALUU
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
    initFormPage();
});

// Kun smooth-navigaatio vaihtaa sisällön, kutsu sama init uudestaan
window.addEventListener('sf:pagechange', () => {
    initFormPage();
});