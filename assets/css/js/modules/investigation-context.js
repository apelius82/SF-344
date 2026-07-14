// assets/js/modules/investigation-context.js
// Progressive field display for investigation context (Step 2)

import { getters } from './state.js';

const { getEl } = getters;

/**
 * Initialize investigation context UI
 * - Shows worksite/date fields only after user makes a choice
 * - Either select a base flash OR enable standalone toggle
 * - In EDIT mode for green type: locks context and shows fields directly
 */
export function initInvestigationContextUI() {
    const relatedFlashSelect = getEl('sf-related-flash');
    const standaloneToggle = getEl('sf-standalone-investigation');
    const worksiteSection = getEl('sf-step2-worksite');
    const incidentSection = getEl('sf-step2-incident');
    const relatedFlashField = relatedFlashSelect?.closest('.sf-field');
    const standaloneField = standaloneToggle?.closest('.sf-field');
    const relatedFlashHelp = getEl('sf-related-flash-help');

    if (!relatedFlashSelect || !standaloneToggle || !worksiteSection) {
        console.log('[Investigation Context] Required elements not found');
        return;
    }

    const form = document.getElementById('sf-form');
    const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';

    const idInput = document.querySelector('input[name="id"]');
    const checkedGreenRadio = document.querySelector('input[name="type"][value="green"]:checked');
    const hiddenGreenInput = document.querySelector('input[type="hidden"][name="type"][value="green"]');
    const isEditMode = !!(idInput && idInput.value && parseInt(idInput.value, 10) > 0);
    const isGreenType = !!(checkedGreenRadio || hiddenGreenInput);

    console.log('[Investigation Context] Initializing...', {
        isEditMode,
        isGreenType,
        isTranslationChild
    });

    function lockWorksiteForTranslation() {
        const worksiteInput = getEl('sf-worksite');
        const worksiteDropdownToggle = document.querySelector('[data-worksite-dropdown-toggle]');
        const worksiteDropdownMenu = document.querySelector('[data-worksite-dropdown-menu]');
        const worksiteSearchInput = document.querySelector('[data-worksite-search]');
        const worksiteOptionButtons = document.querySelectorAll('[data-worksite-option], .sf-worksite-option, .sf-pill-option, .sf-chip-option');
        const hiddenWorksiteInputs = document.querySelectorAll('input[name="worksite"], input[name="site"], input[name="sf-worksite"]');

        if (worksiteInput) {
            worksiteInput.setAttribute('readonly', 'readonly');
            worksiteInput.setAttribute('aria-readonly', 'true');
            worksiteInput.dataset.locked = '1';
            worksiteInput.classList.add('is-locked');
        }

        if (worksiteDropdownToggle) {
            worksiteDropdownToggle.setAttribute('disabled', 'disabled');
            worksiteDropdownToggle.setAttribute('aria-disabled', 'true');
            worksiteDropdownToggle.setAttribute('aria-expanded', 'false');
            worksiteDropdownToggle.classList.add('is-disabled');
            worksiteDropdownToggle.style.pointerEvents = 'none';
        }

        if (worksiteDropdownMenu) {
            worksiteDropdownMenu.classList.add('is-disabled');
            worksiteDropdownMenu.classList.remove('is-open', 'open', 'active', 'visible', 'show');
            worksiteDropdownMenu.setAttribute('hidden', 'hidden');
            worksiteDropdownMenu.setAttribute('aria-hidden', 'true');
            worksiteDropdownMenu.style.display = 'none';
            worksiteDropdownMenu.style.visibility = 'hidden';
            worksiteDropdownMenu.style.opacity = '0';
            worksiteDropdownMenu.style.pointerEvents = 'none';
            worksiteDropdownMenu.style.maxHeight = '0';
            worksiteDropdownMenu.style.overflow = 'hidden';
        }

        if (worksiteSearchInput) {
            worksiteSearchInput.setAttribute('readonly', 'readonly');
            worksiteSearchInput.setAttribute('aria-readonly', 'true');
            worksiteSearchInput.tabIndex = -1;
        }

        worksiteOptionButtons.forEach((button) => {
            button.setAttribute('disabled', 'disabled');
            button.setAttribute('aria-disabled', 'true');
            button.tabIndex = -1;
            button.classList.add('is-disabled');
            button.style.pointerEvents = 'none';
        });

        hiddenWorksiteInputs.forEach((input) => {
            input.dataset.locked = '1';
        });

        document.documentElement.classList.add('sf-translation-worksite-locked');
    }

    if (isTranslationChild && isGreenType) {
        console.log('[Investigation Context] Translation child + green type detected - hiding investigation source selection and locking worksite');

        if (incidentSection) {
            incidentSection.classList.add('hidden');
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (standaloneField) {
            standaloneField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        worksiteSection.classList.remove('hidden');
        worksiteSection.style.display = '';

        lockWorksiteForTranslation();

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }

        return;
    }

    if (isEditMode && isGreenType) {
        console.log('[Investigation Context] Edit mode + green type detected - locking context');

        if (incidentSection) {
            incidentSection.classList.add('hidden');
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (standaloneField) {
            standaloneField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        worksiteSection.classList.remove('hidden');
        worksiteSection.style.display = '';

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }

        return;
    }

    console.log('[Investigation Context] Create mode or red/yellow type - normal behavior');

    const originalTypeWrapper = getEl('sf-standalone-original-type-wrapper');
    const baseSourceRadio = getEl('sf-investigation-source-base');
    const baseSourceTab = getEl('sf-investigation-source-base-tab');
    const standaloneSourceTab = getEl('sf-investigation-source-standalone-tab');
    const step2Content = incidentSection?.closest('.sf-step-content[data-step="2"]');

    function setInvestigationSourceMode(isStandalone) {
        if (baseSourceRadio) {
            baseSourceRadio.checked = !isStandalone;
        }

        standaloneToggle.checked = isStandalone;

        if (baseSourceTab) {
            baseSourceTab.classList.toggle('is-selected', !isStandalone);
            baseSourceTab.setAttribute('aria-pressed', isStandalone ? 'false' : 'true');
        }

        if (standaloneSourceTab) {
            standaloneSourceTab.classList.toggle('is-selected', isStandalone);
            standaloneSourceTab.setAttribute('aria-pressed', isStandalone ? 'true' : 'false');
        }

        if (step2Content) {
            step2Content.classList.toggle('sf-step2-mode-standalone', isStandalone);
            step2Content.classList.toggle('sf-step2-mode-related', !isStandalone);
        }
    }

    function updateOriginalTypeVisibility(isStandalone) {
        if (!originalTypeWrapper) return;
        if (isStandalone) {
            originalTypeWrapper.classList.add('is-open');
            originalTypeWrapper.setAttribute('aria-hidden', 'false');
        } else {
            originalTypeWrapper.classList.remove('is-open');
            originalTypeWrapper.setAttribute('aria-hidden', 'true');
            // Reset selection
            const radios = originalTypeWrapper.querySelectorAll('input[type="radio"]');
            radios.forEach(r => {
                r.checked = false;
                r.closest('.sf-original-type-pill')?.classList.remove('is-selected');
            });
        }
    }

    function updateFieldsVisibility() {
        const hasRelatedFlash = relatedFlashSelect.value && relatedFlashSelect.value !== '';
        const isStandalone = standaloneToggle.checked;

        console.log('[Investigation Context] hasRelatedFlash:', hasRelatedFlash, 'isStandalone:', isStandalone);

        if (isStandalone) {
            if (relatedFlashField) {
                relatedFlashField.style.display = 'none';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = 'none';
            }

            clearWorksiteFields();

            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        } else if (hasRelatedFlash) {
            if (relatedFlashField) {
                relatedFlashField.style.display = '';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = '';
            }

            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        } else {
            if (relatedFlashField) {
                relatedFlashField.style.display = '';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = '';
            }

            worksiteSection.classList.add('hidden');
            worksiteSection.style.display = 'none';
        }

        setInvestigationSourceMode(isStandalone);
        updateOriginalTypeVisibility(isStandalone);

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }
    }

    // Handle pill selection visual state
    if (originalTypeWrapper) {
        originalTypeWrapper.querySelectorAll('.sf-original-type-pill input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                originalTypeWrapper.querySelectorAll('.sf-original-type-pill').forEach(function (p) {
                    p.classList.remove('is-selected');
                });
                if (this.checked) {
                    this.closest('.sf-original-type-pill')?.classList.add('is-selected');
                }
            });
        });
    }

    function clearWorksiteFields() {
        const worksiteInput = getEl('sf-worksite');
        const siteDetailInput = getEl('sf-site-detail');
        const dateInput = getEl('sf-date');

        if (worksiteInput && worksiteInput.dataset.fromRelated === '1') {
            worksiteInput.value = '';
            worksiteInput.dataset.fromRelated = '';
        }

        if (siteDetailInput && siteDetailInput.dataset.fromRelated === '1') {
            siteDetailInput.value = '';
            siteDetailInput.dataset.fromRelated = '';
        }

        if (dateInput && dateInput.dataset.fromRelated === '1') {
            dateInput.value = '';
            dateInput.dataset.fromRelated = '';
        }
    }

    function activateBaseSourceMode() {
    setInvestigationSourceMode(false);
    updateFieldsVisibility();

    if (relatedFlashSelect && typeof relatedFlashSelect._sfRelatedClosePicker === 'function') {
        relatedFlashSelect._sfRelatedClosePicker();
    }
}

    function activateStandaloneMode() {
        setInvestigationSourceMode(true);

        const hiddenRelated = getEl('sf-related-flash-id');
        if (hiddenRelated) {
            hiddenRelated.value = '';
        }

        if (relatedFlashSelect) {
            if (typeof relatedFlashSelect._sfRelatedClosePicker === 'function') {
                relatedFlashSelect._sfRelatedClosePicker();
            }

            relatedFlashSelect.value = '';
        }

        updateFieldsVisibility();

        if (relatedFlashSelect) {
            relatedFlashSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    relatedFlashSelect.addEventListener('change', function () {
        console.log('[Investigation Context] Related flash changed:', this.value);
        updateFieldsVisibility();
    });

if (baseSourceTab) {
    baseSourceTab.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        activateBaseSourceMode();
    });
}

if (standaloneSourceTab) {
    standaloneSourceTab.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        activateStandaloneMode();
    });
}

    if (baseSourceRadio) {
        baseSourceRadio.addEventListener('change', function () {
            if (this.checked) {
                activateBaseSourceMode();
            }
        });
    }

    standaloneToggle.addEventListener('change', function () {
        if (this.checked) {
            activateStandaloneMode();
        } else {
            activateBaseSourceMode();
        }
    });

    updateFieldsVisibility();
}

/**
 * Mark fields as coming from related flash (for clearing logic)
 */
export function markFieldsFromRelatedFlash() {
    const worksiteInput = getEl('sf-worksite');
    const siteDetailInput = getEl('sf-site-detail');
    const dateInput = getEl('sf-date');

    if (worksiteInput) worksiteInput.dataset.fromRelated = '1';
    if (siteDetailInput) siteDetailInput.dataset.fromRelated = '1';
    if (dateInput) dateInput.dataset.fromRelated = '1';
}