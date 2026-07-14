import { getters } from './state.js';
import { validateStep, showValidationErrors } from './validation.js';

const { getEl } = getters;

/**
 * Parse approver IDs from a hidden form input. Returns an empty array if
 * the input is missing or its value is not valid JSON.
 * @param {HTMLInputElement|null} input
 * @returns {string[]}
 */
function parseApproverIds(input) {
    try { return JSON.parse(input?.value || '[]'); } catch (_) { return []; }
}

function isAdminImportEnabled(form) {
    return form.querySelector('#sfAdminImportEnabled')?.checked === true;
}

function getAdminImportState(form) {
    return form.querySelector('[name="admin_initial_state"]')?.value || '';
}

function shouldSkipSupervisorValidation(form) {
    if (!isAdminImportEnabled(form)) {
        return false;
    }

    const selectedState = getAdminImportState(form);

    return selectedState !== '' && selectedState !== 'pending_supervisor';
}

function updateAdminImportSubmitButton(form) {
    const submitButton = getEl('sfSubmitReview');

    if (!submitButton) {
        return;
    }

    const isImportMode = shouldSkipSupervisorValidation(form);

    if (!submitButton.dataset.defaultText) {
        submitButton.dataset.defaultText = submitButton.textContent.trim();
    }

    submitButton.textContent = isImportMode ? 'Tallenna' : submitButton.dataset.defaultText;
}

function createLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'sf-loading-overlay';
    const i18n = window.SF_I18N || {};
    const savingText = i18n.saving_flash || 'Saving... ';
    const previewText = i18n.generating_preview || 'Generating preview';
    overlay.innerHTML = `
        <div class="sf-loading-content">
            <div class="sf-loading-spinner"></div>
            <div class="sf-loading-text">${savingText}</div>
            <div class="sf-loading-subtext">${previewText}</div>
        </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
}

function showLoading(message, subtext) {
    let overlay = getEl('sf-loading-overlay');
    if (!overlay) overlay = createLoadingOverlay();
    const i18n = window.SF_I18N || {};
    const textEl = overlay.querySelector('.sf-loading-text');
    const subtextEl = overlay.querySelector('.sf-loading-subtext');
    if (textEl) textEl.textContent = message || i18n.saving_flash || 'Saving...';
    if (subtextEl) subtextEl.textContent = subtext || i18n.generating_preview || 'Generating preview';
    overlay.classList.add('visible');
}

function hideLoading() {
    const overlay = getEl('sf-loading-overlay');
    if (overlay) overlay.classList.remove('visible');
}

function showToast(message, type = 'info') {
    // käytä mieluummin globaalista headerista löytyvää toastia jos on
    if (typeof window.sfToast === 'function') {
        window.sfToast(type, message);
        return;
    }

    const toast = document.createElement('div');
    toast.className = `sf-toast sf-toast-${type} visible`;
    toast.innerHTML = `<div class="sf-toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.classList.remove('visible');
        setTimeout(() => toast.remove(), 500);
    }, 5000);
}

function showOfflineNotification(isDraft) {
    const i18n = window.SF_I18N || {};

    // Save draft to localStorage as backup when offline
    if (window.autoSave) {
        window.autoSave.saveNow();
    }

    // Show offline notification
    const offlineMessage = isDraft
        ? (i18n.offline_draft_message || 'Ei verkkoyhteyttä. Lomake tallennettu laitteelle automaattisesti. Yritä lähettää kun verkko palaa.')
        : (i18n.offline_submit_message || 'Ei verkkoyhteyttä. Lomake tallennettu laitteelle luonnokseksi. Lähetä kun verkko palaa.');

    // Create persistent notification banner
    let banner = document.getElementById('sfOfflineBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'sfOfflineBanner';
        banner.className = 'sf-offline-banner';
        banner.innerHTML = `
            <div class="sf-offline-banner-content">
                <div class="sf-offline-banner-icon">📡</div>
                <div class="sf-offline-banner-text">
                    <strong>${i18n.offline_title || 'Ei verkkoyhteyttä'}</strong>
                    <p>${offlineMessage}</p>
                </div>
                <button type="button" class="sf-offline-banner-close" aria-label="${i18n.btn_close || 'Sulje'}">×</button>
            </div>
        `;
        document.body.appendChild(banner);

        // Add close button handler
        banner.querySelector('.sf-offline-banner-close').addEventListener('click', () => {
            banner.classList.remove('visible');
            setTimeout(() => banner.remove(), 300);
        });
    }

    // Show banner with animation
    requestAnimationFrame(() => {
        banner.classList.add('visible');
    });

    // Also show toast for immediate feedback
    showToast(offlineMessage, 'warning');
}

function showProgressToast(flashId) {
    let toast = document.getElementById('sfProgressToast');
    if (toast) toast.remove();

    toast = document.createElement('div');
    toast.className = 'sf-toast sf-toast-info visible';
    toast.id = 'sfProgressToast';
    toast.innerHTML = `
        <div class="sf-toast-content">
                        ${window.SF_I18N?.processing_flash || 'Safetyflashia prosessoidaan taustalla...'}
            <div class="sf-progress-bar">
                <span id="sfProgressValue" style="width: 0%;"></span>
            </div>
            <span id="sfProgressText">0%</span>
        </div>
    `;
    document.body.appendChild(toast);
    trackProcessStatus(flashId);
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function trackProcessStatus(flashId) {
    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
    const intervalId = setInterval(async () => {
        try {
            const url = `${baseUrl}/app/api/check-status.php?flash_id=${encodeURIComponent(flashId)}`;
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(`Server responded with status: ${response.status}`);
            }

            const data = await response.json();
            const { status, progress } = data;

            const progressBar = document.getElementById('sfProgressValue');
            const progressText = document.getElementById('sfProgressText');

            if (progressBar && progressText) {
                progressBar.style.width = `${progress}%`;
                progressText.textContent = `${progress}%`;
            }

            if (status === 'completed' || progress >= 100) {
                clearInterval(intervalId);
                const toast = document.getElementById('sfProgressToast');
                if (toast) {
                    const i18n = window.SF_I18N || {};
                    toast.querySelector('.sf-toast-content').innerHTML = i18n.processing_complete || 'Processing complete!';
                    toast.classList.remove('sf-toast-info');
                    toast.classList.add('sf-toast-success');
                    setTimeout(() => {
                        toast.classList.remove('visible');
                        setTimeout(() => toast.remove(), 2000);
                    }, 2000);
                }
            } else if (status === 'error') {
                throw new Error('Processing failed on the server.');
            }
        } catch (err) {
            console.error('Error tracking process status:', err);
            clearInterval(intervalId);
            const toast = document.getElementById('sfProgressToast');
            if (toast) {
                toast.className = 'sf-toast sf-toast-danger visible';
                const i18n = window.SF_I18N || {};
                toast.querySelector('.sf-toast-content').innerHTML = i18n.processing_failed || 'Processing failed.';
            }
        }
    }, 3000);
}

async function doSubmit(form, isDraft, isInlineSave = false) {
    const i18n = window.SF_I18N || {};

    // Check if we're saving a translation child
    const isTranslationChild = form.querySelector('input[name="is_translation_child"]')?.value === '1';

    // Check if online before attempting submission
    if (!navigator.onLine) {
        showOfflineNotification(isDraft);
        return;
    }

    // Validate step 6 before submitting
    // Skip validation if:
    // - isDraft = true (saving draft, supervisor not required yet)
    // - isInlineSave = true (editing approved flash, supervisor already selected)
    // - isTranslationChild = true (translation children don't have reviewers)
    if (!isDraft && !isInlineSave && !isTranslationChild && !shouldSkipSupervisorValidation(form)) {
        const errors = validateStep(6);
        if (errors.length > 0) {
            showValidationErrors(errors);
            return;
        }
    }

    // Check if this is a bundle review for translation child
    const hasApprovers = parseApproverIds(form.querySelector('input[name="approver_ids"]')).length > 0;
    const isBundleReviewMsg = isTranslationChild && !isDraft && hasApprovers;
    const isAdminImportSave = shouldSkipSupervisorValidation(form);

    showLoading(
        isDraft
            ? (i18n.saving_draft || 'Tallennetaan luonnosta...')
            : isAdminImportSave
                ? (i18n.saving_flash || 'Tallennetaan SafetyFlashia...')
                : isInlineSave
                    ? (i18n.saving_changes || 'Tallennetaan muutoksia...')
                    : isBundleReviewMsg
                        ? (i18n.sending_for_review || 'Lähetetään tarkistettavaksi...')
                        : isTranslationChild
                            ? (i18n.saving_translation || 'Tallennetaan kieliversiota...')
                            : (i18n.sending_for_review || 'Lähetetään tarkistettavaksi...'),
        isAdminImportSave
            ? (i18n.processing_continues || 'Prosessointi jatkuu taustalla.')
            : (i18n.please_wait || 'Odota hetki...')
    );

    const draftBtn = getEl('sfSaveDraft');
    const reviewBtn = getEl('sfSubmitReview');
    const translationBtn = getEl('sf-save-translation-btn');
    if (draftBtn) draftBtn.disabled = true;
    if (reviewBtn) reviewBtn.disabled = true;
    if (translationBtn) translationBtn.disabled = true;

    const confirmModal = getEl('sfConfirmModal');
    if (confirmModal) confirmModal.classList.add('hidden');

    try {
        // Tyhjennä preview-kentät - palvelin generoi kuvan
        const p1Input = form.querySelector('input[name="preview_image_data"]');
        const p2Input = form.querySelector('input[name="preview_image_data_2"]');
        if (p1Input) p1Input.value = '';
        if (p2Input) p2Input.value = '';

        console.log('[submit.js] Preview will be generated server-side by worker');

        const gridBitmapInput = form.querySelector('input[name="grid_bitmap"]');
        if (gridBitmapInput) {
            const currentGridValue = (gridBitmapInput.value || '').trim();

            if (currentGridValue.startsWith('data:image/') && typeof window.SF_GRID_UPLOAD_TEMP === 'function') {
                // Try up to 3 times with increasing delays
                let uploaded = false;
                for (let attempt = 1; attempt <= 3 && !uploaded; attempt++) {
                    const retryDelay = 1000 * attempt;
                    try {
                        const tempFilename = await window.SF_GRID_UPLOAD_TEMP(currentGridValue);
                        if (tempFilename) {
                            gridBitmapInput.value = tempFilename;
                            gridBitmapInput.dataset.gridBitmapBase64 = currentGridValue;
                            console.log(`[submit.js] Grid bitmap uploaded on attempt ${attempt}:`, tempFilename);
                            uploaded = true;
                        } else {
                            console.warn(`[submit.js] Grid temp upload attempt ${attempt} returned null`);
                            if (attempt < 3) await new Promise(r => setTimeout(r, retryDelay));
                        }
                    } catch (gridUploadError) {
                        console.warn(`[submit.js] Grid temp upload attempt ${attempt} failed:`, gridUploadError);
                        if (attempt < 3) await new Promise(r => setTimeout(r, retryDelay));
                    }
                }
                if (!uploaded) {
                    console.warn('[submit.js] All grid upload attempts failed, keeping base64 fallback');
                }
            }
        }

        const adminImportEnabled = form.querySelector('#sfAdminImportEnabled');
        const adminCreatedAtCustom = form.querySelector('#sfAdminCreatedAtCustom');

        if (adminImportEnabled && !adminImportEnabled.checked) {
            form.querySelectorAll('[name="admin_created_at_mode"], [name="admin_created_at_custom"], [name="admin_initial_state"]').forEach((field) => {
                field.disabled = true;
            });
        }

        if (adminImportEnabled && adminImportEnabled.checked && adminCreatedAtCustom) {
            const selectedMode = form.querySelector('[name="admin_created_at_mode"]:checked')?.value || 'now';
            adminCreatedAtCustom.disabled = selectedMode !== 'custom';
        }

        const formData = new FormData(form);

        form.querySelectorAll('[name="admin_created_at_mode"], [name="admin_created_at_custom"], [name="admin_initial_state"]').forEach((field) => {
            field.disabled = false;
        });

        // For translation children, use submission_type = 'translation' UNLESS approver_ids
        // are set (bundle review flow), in which case use 'review' so the supervisor is notified.
        if (isTranslationChild) {
            const approverIds = parseApproverIds(form.querySelector('input[name="approver_ids"]'));
            const isBundleReview = !isDraft && approverIds.length > 0;
            formData.append('submission_type', isBundleReview ? 'review' : (isDraft ? 'draft' : 'translation'));
        } else {
            formData.append('submission_type', isDraft ? 'draft' : 'review');
        }
        formData.append('is_ajax', '1');

        // Include extra images data in FormData for AJAX submission
        if (window.ExtraImagesUpload) {
            const extraImages = window.ExtraImagesUpload.getImages();
            if (extraImages && extraImages.length > 0) {
                formData.append('extra_images', JSON.stringify(extraImages));
            }
        }

        let response;
        let retryCount = 0;
        const maxRetries = 2;

        while (retryCount <= maxRetries) {
            try {
                response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                });

                // If server error (5xx), retry
                if (response.status >= 500 && retryCount < maxRetries) {
                    retryCount++;
                    const retryDelay = 2000 * retryCount;
                    console.warn(`[submit.js] Server error ${response.status}, retry ${retryCount}/${maxRetries}`);
                    await new Promise(r => setTimeout(r, retryDelay));
                    continue;
                }

                break; // Success or non-retryable error
            } catch (fetchErr) {
                if (retryCount < maxRetries && (fetchErr instanceof TypeError)) {
                    retryCount++;
                    const retryDelay = 2000 * retryCount;
                    console.warn(`[submit.js] Fetch failed, retry ${retryCount}/${maxRetries}:`, fetchErr);
                    await new Promise(r => setTimeout(r, retryDelay));
                    continue;
                }
                throw fetchErr; // Rethrow if all retries exhausted
            }
        }

        const raw = await response.text();
        let result = null;
        try { result = JSON.parse(raw); } catch (_) { }

        if (!response.ok) {
            hideLoading();
            const msg =
                (result && (result.error || result.message))
                    ? (result.error || result.message)
                    : `Palvelin vastasi virheellä: ${response.status}`;

            const debug = result && result.debug ? ` (${result.debug})` : '';
            throw new Error(msg + debug);
        }

        if (!result) {
            hideLoading();
            throw new Error('Tuntematon vastaus palvelimelta.');
        }

        if (result.ok && result.flash_id) {
            // Mark submission in progress to prevent beforeunload from saving draft
            if (window.autoSave) {
                window.autoSave.submissionInProgress = true;
                window.autoSave.stop(); // Stop autosave interval immediately
            }

            // Delete autosave drafts - palvelin hoitaa tämän nyt luotettavammin,
            // mutta tehdään myös client-puolella varmuuden vuoksi
            // HUOM: Ei poisteta kun tallennetaan luonnosta (isDraft=true)
            if (window.autoSave && !isDraft) {
                try {
                    const deletionPromises = [];

                    // Delete current draft if exists
                    if (window.autoSave.currentDraftId) {
                        deletionPromises.push(window.autoSave.deleteDraft(window.autoSave.currentDraftId));
                    }

                    // Also delete any drafts from SF_USER_DRAFTS (shown in recovery overlay)
                    // Filter out current draft to avoid duplicate deletion attempts
                    const userDrafts = (window.SF_USER_DRAFTS || [])
                        .filter(draft => draft.id !== window.autoSave.currentDraftId);

                    // Add all user draft deletions to the promise array
                    deletionPromises.push(...userDrafts.map(draft => window.autoSave.deleteDraft(draft.id)));

                    // Use allSettled to ensure all deletions are attempted even if some fail
                    await Promise.allSettled(deletionPromises);

                    // Clear current draft ID and global state after deletions complete
                    window.autoSave.currentDraftId = null;
                    window.SF_USER_DRAFTS = []; // Tyhjennä myös tämä
                } catch (e) {
                    console.warn('Draft deletion failed:', e);
                }
            }

            // “lähetetään tarkistettavaksi” näkyy aina saman ajan (ms)
            showLoading(
                isDraft
                    ? (i18n.draft_saved || 'Luonnos tallennettu.')
                    : isAdminImportSave
                        ? (i18n.flash_saved || 'SafetyFlash tallennettu.')
                        : isInlineSave
                            ? (i18n.changes_saved || 'Muutokset tallennettu.')
                            : isTranslationChild
                                ? (i18n.translation_saved || 'Kieliversio tallennettu.')
                                : (i18n.sending_for_review || 'Lähetetään tarkistettavaksi.'),
                isAdminImportSave
                    ? (i18n.processing_continues || 'Prosessointi jatkuu taustalla.')
                    : isTranslationChild
                        ? (i18n.redirecting || 'Siirrytään...')
                        : (i18n.processing_continues || 'Käsittely jatkuu taustalla.')
            );
            await sleep(1200); // <-- säädä tarvittaessa
            hideLoading();

            showToast(i18n.data_received_processing || 'Data received. Processing continues in background.', 'success');

            // Set redirectInProgress flag to prevent beforeunload from saving a new draft
            if (window.autoSave) {
                window.autoSave.redirectInProgress = true;
            }

            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

            if (isTranslationChild) {
                if (isDraft) {
                    // Draft save: stay in edit mode so user can continue editing or add more languages
                    window.location.href = `${baseUrl}/index.php?page=form&id=${encodeURIComponent(result.flash_id)}&step=6&saved=1`;
                } else {
                    // Check if approvers were submitted (bundle review) for appropriate notice
                    const approverIds = parseApproverIds(form.querySelector('input[name="approver_ids"]'));
                    const notice = approverIds.length > 0 ? 'sent_to_review' : 'translation_saved';
                    window.location.href = `${baseUrl}/index.php?page=view&id=${encodeURIComponent(result.flash_id)}&notice=${notice}`;
                }
            } else {
                window.location.href = `${baseUrl}/index.php?page=list&bg_process=${encodeURIComponent(result.flash_id)}`;
            }
            return;
        }

        if (result.error) {
            throw new Error(result.error);
        }

        throw new Error('Tuntematon vastaus palvelimelta.');
    } catch (err) {
        console.error('Error during submission:', err);
        hideLoading();
        const i18n = window.SF_I18N || {};

        // Check if error is due to network failure (TypeError for fetch errors)
        if (err instanceof TypeError || err.name === 'TypeError') {
            showOfflineNotification(isDraft);
            if (draftBtn) draftBtn.disabled = false;
            if (reviewBtn) reviewBtn.disabled = false;
            if (translationBtn) translationBtn.disabled = false;
            return;
        }

        alert(`${i18n.save_failed || 'Save failed: '}${err.message}`);
        if (draftBtn) draftBtn.disabled = false;
        if (reviewBtn) reviewBtn.disabled = false;
        if (translationBtn) translationBtn.disabled = false;
    }
}

function checkAndTrackBackgroundProcess() {
    const urlParams = new URLSearchParams(window.location.search);
    const processId = urlParams.get('bg_process');
    if (processId) {
        showProgressToast(processId);

        const newUrl =
            window.location.pathname +
            window.location.search.replace(/&?bg_process=[^&]+/, '');
        window.history.replaceState({ path: newUrl }, '', newUrl);
    }
}

export function bindSubmit() {
    const form = getEl('sf-form');
    if (!form) return;

    // Ei kovakoodattuja polkuja: käytä base_url:ia
    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
    if (baseUrl) {
        form.action = `${baseUrl}/app/api/save_flash.php`;
    }

    const draftBtn = getEl('sfSaveDraft');
    const reviewBtn = getEl('sfSubmitReview');
    const confirmModal = getEl('sfConfirmModal');
    const confirmSubmitBtn = getEl('sfConfirmSubmit');

    // Expose doSubmit globally for use by supervisor-approval.js
    window.sfFormSubmit = doSubmit;

    if (draftBtn) {
        draftBtn.addEventListener('click', (e) => {
            e.preventDefault();
            doSubmit(form, true);
        });
    }

    // Bind translation save button
    const translationBtn = getEl('sf-save-translation-btn');
    if (translationBtn) {
        translationBtn.addEventListener('click', (e) => {
            e.preventDefault();
            doSubmit(form, false);
        });
    }

    if (reviewBtn) {
        reviewBtn.addEventListener('click', (e) => {
            if (!shouldSkipSupervisorValidation(form)) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            doSubmit(form, false);
        }, true);

        reviewBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            if (shouldSkipSupervisorValidation(form)) {
                doSubmit(form, false);
                return;
            }

            // Validate before opening modal
            const errors = validateStep(6);
            if (errors.length > 0) {
                showValidationErrors(errors);
                return;
            }

            // If validation passes, show the confirmation modal
            if (typeof window.sfShowSubmitModal === 'function') {
                window.sfShowSubmitModal();
            } else if (confirmModal) {
                confirmModal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
            } else {
                doSubmit(form, false);
            }
        });
    }

    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (confirmModal) {
                confirmModal.classList.add('hidden');
                document.body.classList.remove('sf-modal-open');
            }
            doSubmit(form, false);
        });
    }

    form.addEventListener('submit', (e) => e.preventDefault());

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAndTrackBackgroundProcess);
    } else {
        checkAndTrackBackgroundProcess();
    }
}
function updateAdminImportPanel() {
    const enabledToggle = document.getElementById('sfAdminImportEnabled');
    const fields = document.getElementById('sfAdminImportFields');
    const customInput = document.getElementById('sfAdminCreatedAtCustom');
    const summary = document.getElementById('sfAdminImportSummary');

    if (!enabledToggle || !fields) {
        return;
    }

    const isEnabled = enabledToggle.checked;
    const selectedState = document.querySelector('[name="admin_initial_state"]')?.value || '';
    const supervisorSection = document.getElementById('sfSupervisorApprovalSection');

    fields.hidden = !isEnabled;
    fields.style.display = isEnabled ? 'grid' : 'none';

    if (supervisorSection) {
        const shouldHideSupervisorSection = isEnabled && selectedState !== 'pending_supervisor';
        supervisorSection.style.display = shouldHideSupervisorSection ? 'none' : '';
    }

    if (!customInput) {
        return;
    }

    const selectedMode = document.querySelector('[name="admin_created_at_mode"]:checked')?.value || 'now';
    customInput.disabled = selectedMode !== 'custom';

    if (isEnabled && selectedMode === 'custom' && customInput.value === '') {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        customInput.value = now.toISOString().slice(0, 16);
    }

    if (summary) {
        if (!isEnabled) {
            summary.textContent = summary.dataset.defaultText || 'Oletuksena SafetyFlash tallennetaan julkaistuna nykyhetkeen.';
        } else if (selectedMode === 'occurred_at') {
            summary.textContent = 'Luontipäiväksi asetetaan tapahtumapäivä.';
        } else if (selectedMode === 'custom') {
            summary.textContent = 'Luontipäiväksi asetetaan valittu päivämäärä.';
        } else {
            summary.textContent = 'Luontipäiväksi asetetaan nykyhetki.';
        }
    }

    const form = getEl('sf-form');
    if (form) {
        updateAdminImportSubmitButton(form);
    }
}

document.addEventListener('change', function (event) {
    const target = event.target;

    if (!target) {
        return;
    }

    if (
        target.id === 'sfAdminImportEnabled' ||
        target.name === 'admin_created_at_mode' ||
        target.id === 'sfAdminCreatedAtCustom' ||
        target.name === 'admin_initial_state'
    ) {
        updateAdminImportPanel();
    }
});

document.addEventListener('DOMContentLoaded', updateAdminImportPanel);