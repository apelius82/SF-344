import { getters, state } from './state.js';
const { qs, getEl } = getters

function getSelectedWorksiteValue() {
    const select = getEl('sf-worksite');

    if (select && select.value && select.value.trim() !== '') {
        return select.value.trim();
    }

    const selectedChip = document.querySelector('#sf-worksite-chip-list .sf-worksite-chip-option.is-selected');
    const chipValue = selectedChip?.dataset?.value?.trim();

    if (chipValue) {
        if (select) {
            select.value = chipValue;
        }

        return chipValue;
    }

    const triggerText = getEl('sf-worksite-trigger-text');
    const triggerValue = triggerText?.textContent?.trim();
    const triggerPlaceholder = triggerText?.dataset?.placeholder?.trim();

    if (triggerValue && triggerValue !== triggerPlaceholder) {
        const matchingOption = select
            ? Array.from(select.options).find((option) => option.value.trim() === triggerValue)
            : null;

        if (matchingOption && select) {
            select.value = matchingOption.value;
            return matchingOption.value.trim();
        }
    }

    return '';
}

export function validateStep(stepNumber) {
    const errors = [];
    const currentType = qs('input[name="type"]:checked')?.value;
    const i18n = window.SF_I18N || {};

    // Check if we're editing a translation child
    const form = document.getElementById('sf-form');
    const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';

    if (stepNumber === 1) {
        // In translation child mode, type and language are locked, skip validation
        if (isTranslationChild) {
            return errors; // Empty array - no errors
        }
        
        if (!qs('input[name="lang"]:checked')) errors.push(i18n.validation_select_language || 'Select language');
        if (!qs('input[name="type"]:checked')) errors.push(i18n.validation_select_type || 'Select flash type');
    }    if (stepNumber === 2) {
        if (currentType === 'green') {
            // SKIP validation in edit mode - investigation context is already set
            const flashIdInput = qs('input[name="id"]');
            const isEditMode = flashIdInput && flashIdInput.value && parseInt(flashIdInput.value) > 0;

            if (isEditMode) {
                console.log('[Validation] Edit mode detected for green type - skipping Step 2 validation');
                return errors; // Empty array - no errors
            }

            const standaloneCheckbox = getEl('sf-standalone-investigation');
            const isStandalone = standaloneCheckbox?.checked;

if (isStandalone) {
    // Standalone investigation - require worksite, date and original type
    const worksite = getSelectedWorksiteValue();
    const eventDate = getEl('sf-date')?.value;
    const selectedOriginalType = document.querySelector(
        'input[name="standalone_original_type"]:checked'
    )?.value;

    console.log(
        '[Validation] Standalone mode - worksite:',
        worksite,
        'date:',
        eventDate,
        'originalType:',
        selectedOriginalType
    );

    if (!selectedOriginalType) {
        errors.push(
            i18n.validation_select_original_type ||
            'Valitse alkuperäinen tyyppi'
        );
    }

    if (!worksite) {
        errors.push(i18n.validation_select_worksite || 'Select worksite');
    }
                if (!eventDate) {
                    errors.push(i18n.validation_enter_event_time || 'Enter event time');
                } else {
                    const selectedDate = new Date(eventDate);
                    const now = new Date();
                    if (selectedDate > now) {
                        errors.push(i18n.validation_time_not_future || 'Event time cannot be in the future');
                    }
                }
            } else {
                // Based on existing flash - require related flash selection
                const relatedFlash = getEl('sf-related-flash')?.value;

                console.log('[Validation] Base flash mode - relatedFlash:', relatedFlash);

                if (!relatedFlash) {
                    errors.push(i18n.validation_select_base_or_standalone || 'Select base flash or enable standalone investigation');
                }
            }
        } else {
            const worksite = getSelectedWorksiteValue();
            const eventDate = getEl('sf-date')?.value;
            if (!worksite) errors.push(i18n.validation_select_worksite || 'Select worksite');
            if (!eventDate) {
                errors.push(i18n.validation_enter_event_time || 'Enter event time');
            } else {
                const selectedDate = new Date(eventDate);
                const now = new Date();
                if (selectedDate > now) {
                    errors.push(i18n.validation_time_not_future || 'Event time cannot be in the future');
                }
            }
        }
    }
    if (stepNumber === 3) {
        const title = getEl('sf-title')?.value?.trim();
        const shortText = getEl('sf-short-text')?.value?.trim();
        const description = getEl('sf-description')?.value?.trim();
        if (!title) errors.push(i18n.validation_enter_title || 'Enter internal title');
        if (!shortText) errors.push(i18n.validation_enter_short_desc || 'Enter short description');
        else if (shortText.length > 85) errors.push(i18n.validation_short_desc_too_long || 'Short description is too long (max 125 characters)');
        if (!description) errors.push(i18n.validation_enter_description || 'Enter event description');
        else if (description.length > 950) errors.push(i18n.validation_desc_too_long || 'Description is too long (max 650 characters)');
        if (currentType === 'green') {
            const rootCauses = getEl('sf-root-causes')?.value?.trim();
            const actions = getEl('sf-actions')?.value?.trim();
            if (rootCauses && rootCauses.length > 1500) errors.push(i18n.validation_root_causes_too_long || 'Root causes text is too long (max 1500 characters)');
            if (actions && actions.length > 1500) errors.push(i18n.validation_actions_too_long || 'Actions text is too long (max 1500 characters)');
        }
    }
    if (stepNumber === 4) {
        const hasRealImageInSlot = (slot) => {
            const fileInput = getEl(`sf-image${slot}`);
            const cameraInput = document.querySelector(`input[name="image${slot}_camera"]`);
            const libraryInput = getEl(`sfLibraryImage${slot}`);
            const tempInput = getEl(`sf-temp-image${slot}`);
            const existingInput = getEl(`sf-existing-image-${slot}`);
            const editedInput = getEl(`sf-image${slot}-edited-data`);
            const thumb = getEl(`sfImageThumb${slot}`) || getEl(`sf-upload-preview${slot}`);

            if (fileInput?.files?.length > 0) return true;
            if (cameraInput?.files?.length > 0) return true;
            if (libraryInput?.value?.trim()) return true;
            if (tempInput?.value?.trim()) return true;
            if (existingInput?.value?.trim()) return true;
            if (editedInput?.value?.trim()) return true;

            if (thumb?.dataset?.hasRealImage === '1') return true;

            const src = thumb?.getAttribute('src') || '';
            const placeholder = thumb?.dataset?.placeholder || '';

            if (!src) return false;
            if (placeholder && src === placeholder) return false;
            if (src.includes('camera-placeholder')) return false;
            if (src.includes('placeholder')) return false;
            if (src === 'about:blank') return false;
            if (src.endsWith('/')) return false;

            return true;
        };

        const hasAnyImage =
            hasRealImageInSlot(1) ||
            hasRealImageInSlot(2) ||
            hasRealImageInSlot(3);

        if (!hasAnyImage) {
            errors.push(i18n.validation_image_required || 'Lisää vähintään yksi kuva');
        }
    }

    // Step 6 validation for supervisor selection
    if (stepNumber === 6) {
        // Skip supervisor validation for translation children
        const form = document.getElementById('sf-form');
        const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';
        
        if (isTranslationChild) {
            return errors;
        }

        // Check if at least one supervisor is selected
        // Support both old checkbox-based UI and new chip-based UI
        const checkedSupervisors = document.querySelectorAll('input[name="approver_ids[]"]:checked');
        const hiddenInput = getEl('approverIds') || getEl('selectedApprovers');
        let selectedIds = [];

        // Try to get IDs from hidden inputs (new chip-based UI)
        if (hiddenInput && hiddenInput.value) {
            try {
                selectedIds = JSON.parse(hiddenInput.value);
            } catch (e) {
                selectedIds = [];
            }
        }

        // Check if we have any selected supervisors from either system
        if (checkedSupervisors.length === 0 && selectedIds.length === 0) {
            errors.push(i18n.validation_select_at_least_one_site_manager || 'Valitse vähintään yksi työmaavastaava');
        }
    }

    return errors;
}

export function showValidationErrors(errors) {
    if (errors.length === 0) return true;

    const i18n = window.SF_I18N || {};
    const activeStep = qs('.sf-step-content.active');

    if (!activeStep) {
        return false;
    }

    let errorBox = getEl('sf-validation-errors');

    if (!errorBox) {
        errorBox = document.createElement('div');
        errorBox.id = 'sf-validation-errors';
        errorBox.className = 'sf-validation-errors';
        errorBox.setAttribute('role', 'alert');
        errorBox.setAttribute('aria-live', 'assertive');
        errorBox.setAttribute('tabindex', '-1');
    }

    if (errorBox.parentElement !== activeStep) {
        activeStep.insertBefore(errorBox, activeStep.firstChild);
    }

    errorBox.classList.remove('hidden');
    errorBox.style.display = '';

    const escapeValidationText = (value) => {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    };

    errorBox.innerHTML = `
        <div class="sf-validation-icon">⚠️</div>
        <div class="sf-validation-content">
            <strong>${escapeValidationText(i18n.validation_fill_missing || 'Täytä puuttuvat tiedot:')}</strong>
            <ul>${errors.map(error => `<li>${escapeValidationText(error)}</li>`).join('')}</ul>
        </div>
        <button type="button" class="sf-validation-close" aria-label="${escapeValidationText(i18n.close || 'Sulje')}" onclick="this.parentElement.remove()">×</button>
    `;

    requestAnimationFrame(() => {
        const progressBar = document.querySelector('.sf-form-progress');
        const progressHeight = progressBar ? progressBar.getBoundingClientRect().height : 0;
        const extraGap = 32;

        const scrollParent = (() => {
            let parent = errorBox.parentElement;

            while (parent && parent !== document.body) {
                const style = window.getComputedStyle(parent);
                const overflowY = style.overflowY;

                if ((overflowY === 'auto' || overflowY === 'scroll') && parent.scrollHeight > parent.clientHeight) {
                    return parent;
                }

                parent = parent.parentElement;
            }

            return null;
        })();

        if (scrollParent) {
            const parentRect = scrollParent.getBoundingClientRect();
            const errorRect = errorBox.getBoundingClientRect();
            const targetTop = scrollParent.scrollTop + errorRect.top - parentRect.top - progressHeight - extraGap;

            scrollParent.scrollTo({
                top: Math.max(targetTop, 0),
                behavior: 'smooth'
            });
        } else {
            const targetTop = errorBox.getBoundingClientRect().top + window.pageYOffset - progressHeight - extraGap;

            window.scrollTo({
                top: Math.max(targetTop, 0),
                behavior: 'smooth'
            });
        }

        setTimeout(() => {
            errorBox.focus({ preventScroll: true });
        }, 250);
    });

    return false;
}