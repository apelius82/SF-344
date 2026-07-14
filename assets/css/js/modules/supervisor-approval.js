// assets/js/modules/supervisor-approval.js
// Handles search-first supervisor/approver selection logic in the form

// Global supervisors cache
let allSupervisors = [];

/**
 * Get i18n text with fallback
 */
function getI18n(key, fallback) {
    const i18n = window.SF_I18N || {};
    return i18n[key] || fallback;
}

/**
 * Check if supervisor approval should be hidden based on flash type
 * ALL types (red, yellow, green) need supervisor approval
 * @returns {boolean} true if supervisor section should be hidden
 */
function shouldHideSupervisorSection() {
    // Kaikki tyypit (red, yellow, green) tarvitsevat työmaavastaavan hyväksynnän
    // Palauta aina false - älä piilota koskaan
    return false;
}

/**
 * Force supervisor section visibility check
 * Called when navigating to step 6 (preview/submit step)
 */
export function checkAndShowSupervisorSection() {
    const siteField = document.getElementById('sf-worksite');
    const section = document.getElementById('sfSupervisorApprovalSection');

    console.log('[Supervisor Approval] Checking supervisor section on step 6');

    if (!section) {
        console.log('[Supervisor Approval] Section not found');
        return;
    }

    // Get worksite value (might be prefilled from related flash)
    const worksite = siteField?.value;
    const worksiteName = siteField?.selectedOptions[0]?.text || '-';

    console.log('[Supervisor Approval] Worksite value:', worksite);

    // Update worksite name display
    const worksiteNameEl = document.getElementById('sfSelectedWorksiteName');
    if (worksiteNameEl) {
        worksiteNameEl.textContent = worksiteName;
    }

    if (worksite) {
        // Worksite is set, load supervisors and show section
        section.style.display = 'block';
        loadWorksiteSupervisors(worksite);
    } else {
        // No worksite yet - still show section but with empty state
        section.style.display = 'block';
        const container = document.getElementById('sfWorksiteSupervisors');
        const emptyMsg = document.getElementById('sfNoSupervisors');
        if (container) container.innerHTML = '';
        if (emptyMsg) {
            emptyMsg.style.display = 'block';
            emptyMsg.textContent = getI18n('select_worksite_first', 'Valitse ensin työmaa nähdäksesi sen vastuuhenkilöt.');
        }
    }
}

/**
 * Load and display worksite supervisors as chip cards
 */
async function loadWorksiteSupervisors(worksiteId) {
    const container = document.getElementById('sfWorksiteSupervisors');
    const emptyMsg = document.getElementById('sfNoSupervisors');

    if (!container) return;

// Show loading state
container.innerHTML = `
    <div class="sf-supervisor-chips-loading">
        <span class="sf-spinner-small"></span>
        ${escapeHtml(getI18n('loading_text', 'Ladataan...'))}
    </div>
`;
    if (emptyMsg) emptyMsg.style.display = 'none';

    try {
        // Detect base URL
        let base = window.SF_BASE_URL || '';
        if (!base) {
            const pathParts = window.location.pathname.split('/');
            const indexPhp = pathParts.findIndex(p => p === 'index.php');
            if (indexPhp > 0) {
                base = pathParts.slice(0, indexPhp).join('/');
            }
        }

        // Fetch worksite-specific supervisors
        const apiUrl = `${base}/app/api/get_worksite_supervisors.php?worksite=${encodeURIComponent(worksiteId)}`;
        console.log('[Supervisor Approval] Fetching worksite supervisors from:', apiUrl);

        const response = await fetch(apiUrl, {
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (data.ok && data.supervisors && data.supervisors.length > 0) {
            // Render supervisors as chip cards
            const globalLabel = getI18n('global_worksite_label', 'Globaali');
            const noNameLabel = getI18n('no_name_label', 'Ei nimeä');
            container.innerHTML = data.supervisors.map(sup => {
                const firstName = sup.first_name || '';
                const lastName = sup.last_name || '';
                const name = `${firstName} ${lastName}`.trim() || noNameLabel;
                const worksite = sup.worksite || globalLabel;
                return `
                    <div class="sf-supervisor-chip" data-supervisor-id="${sup.id}" data-supervisor-name="${escapeHtml(name)}" data-supervisor-worksite="${escapeHtml(worksite)}">
                        <span class="sf-supervisor-chip-name">${escapeHtml(name)}</span>
                        <span class="sf-supervisor-chip-status">${escapeHtml(getI18n('supervisor_chip_select', 'Valitse'))}</span>
                    </div>
                `;
            }).join('');

            if (emptyMsg) emptyMsg.style.display = 'none';

            // Add click handlers to chips
            container.querySelectorAll('.sf-supervisor-chip').forEach(chip => {
                chip.addEventListener('click', () => toggleSupervisorChip(chip));
            });
        } else {
            // No supervisors found
            container.innerHTML = '';
            if (emptyMsg) {
                emptyMsg.style.display = 'block';
                emptyMsg.textContent = getI18n('no_supervisors_for_worksite', 'Tälle työmaalle ei ole määritetty vastuuhenkilöitä.');
            }
        }

        // Load all supervisors for search functionality
        await loadAllSupervisorsForSearch();

    } catch (err) {
        console.error('[Supervisor Approval] Error loading worksite supervisors:', err);
        container.innerHTML = `<p class="sf-error-message">${getI18n('error_loading_supervisors', 'Virhe ladattaessa vastuuhenkilöitä')}</p>`;
    }
}

/**
 * Load all supervisors for search functionality
 */
async function loadAllSupervisorsForSearch() {
    try {
        let base = window.SF_BASE_URL || '';
        if (!base) {
            const pathParts = window.location.pathname.split('/');
            const indexPhp = pathParts.findIndex(p => p === 'index.php');
            if (indexPhp > 0) {
                base = pathParts.slice(0, indexPhp).join('/');
            }
        }

        const apiUrl = `${base}/app/api/get_worksite_supervisors.php?all=1`;
        const response = await fetch(apiUrl, {
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (data.ok && data.supervisors) {
            allSupervisors = data.supervisors;
            console.log('[Supervisor Approval] Loaded all supervisors for search:', allSupervisors.length);
        }
    } catch (err) {
        console.error('[Supervisor Approval] Error loading all supervisors:', err);
    }
}

/**
 * Toggle supervisor chip selection
 */
function toggleSupervisorChip(chip) {
    const isSelected = chip.classList.contains('selected');
    const supervisorId = chip.dataset.supervisorId;
    const supervisorName = chip.dataset.supervisorName;
    const supervisorWorksite = chip.dataset.supervisorWorksite;

    if (isSelected) {
        // Deselect
        chip.classList.remove('selected');
        chip.querySelector('.sf-supervisor-chip-status').textContent = getI18n('supervisor_chip_select', 'Valitse');

        // Remove from hidden inputs
        removeApproverFromInputs(supervisorId);
    } else {
        // Select
        chip.classList.add('selected');
        chip.querySelector('.sf-supervisor-chip-status').textContent = getI18n('supervisor_chip_selected', 'Valittu');

        // Add to hidden inputs
        addApproverToInputs(supervisorId, supervisorName, supervisorWorksite);
    }

    updateSelectedCount();
}

/**
 * Update selected count display
 */
function updateSelectedCount() {
    const countEl = document.getElementById('sfSelectedCount');
    if (!countEl) return;

    // Count both worksite chips and search result chips
    const worksiteSelected = document.querySelectorAll('#sfWorksiteSupervisors .sf-supervisor-chip.selected').length;
    const searchSelected = document.querySelectorAll('#sfSearchResults .sf-supervisor-chip.selected').length;
    const total = worksiteSelected + searchSelected;

    countEl.textContent = total;
}

/**
 * Add approver to hidden inputs
 */
function addApproverToInputs(id, name, worksite) {
    // Get existing approver IDs
    const hiddenInput1 = document.getElementById('approverIds');
    const hiddenInput2 = document.getElementById('selectedApprovers');

    let approverIds = [];
    if (hiddenInput1 && hiddenInput1.value) {
        try {
            approverIds = JSON.parse(hiddenInput1.value);
        } catch (e) {
            approverIds = [];
        }
    }

    // Add new ID if not already present
    if (!approverIds.includes(id)) {
        approverIds.push(id);
    }

    // Update hidden inputs
    const idsJson = JSON.stringify(approverIds);
    if (hiddenInput1) hiddenInput1.value = idsJson;
    if (hiddenInput2) hiddenInput2.value = idsJson;
}

/**
 * Remove approver from hidden inputs
 */
function removeApproverFromInputs(id) {
    const hiddenInput1 = document.getElementById('approverIds');
    const hiddenInput2 = document.getElementById('selectedApprovers');

    let approverIds = [];
    if (hiddenInput1 && hiddenInput1.value) {
        try {
            approverIds = JSON.parse(hiddenInput1.value);
        } catch (e) {
            approverIds = [];
        }
    }

    // Remove the ID
    approverIds = approverIds.filter(existingId => existingId !== id);

    // Update hidden inputs
    const idsJson = JSON.stringify(approverIds);
    if (hiddenInput1) hiddenInput1.value = idsJson;
    if (hiddenInput2) hiddenInput2.value = idsJson;
}

/**
 * Initialize supervisor approval functionality with search-first UI
 */
export function initSupervisorApproval() {
    console.log('[Supervisor Approval] Initializing search-first UI...');

    // Get the worksite field (looking for id="sf-worksite" based on form.php)
    const siteField = document.getElementById('sf-worksite');
    const section = document.getElementById('sfSupervisorApprovalSection');

    if (!section) {
        console.log('[Supervisor Approval] Section not found, skipping initialization');
        return;
    }

    console.log('[Supervisor Approval] Elements found, binding events...');

    // Listen for type changes - show supervisor section for ALL types
    const typeRadios = document.querySelectorAll('input[name="type"]');
    typeRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            console.log('[Supervisor Approval] Type changed to:', this.value);
            // Näytä supervisor-osio kaikille tyypeille kun työmaa on valittu
            if (siteField?.value) {
                loadWorksiteSupervisors(siteField.value);
            } else {
                // No worksite selected yet, keep section hidden
                section.style.display = 'none';
            }
        });
    });

    // Load supervisors when worksite changes
    if (siteField) {
        siteField.addEventListener('change', async function () {
            const worksite = this.value;
            const worksiteName = this.selectedOptions[0]?.text || '-';
            console.log('[Supervisor Approval] Worksite changed to:', worksite);

            // Update worksite name display
            const worksiteNameEl = document.getElementById('sfSelectedWorksiteName');
            if (worksiteNameEl) {
                worksiteNameEl.textContent = worksiteName;
            }

            await loadWorksiteSupervisors(worksite);
        });
    }

    // Trigger initial load if worksite is already selected
    if (siteField?.value) {
        console.log('[Supervisor Approval] Initial worksite value:', siteField.value);
        const worksiteName = siteField.selectedOptions[0]?.text || '-';
        const worksiteNameEl = document.getElementById('sfSelectedWorksiteName');
        if (worksiteNameEl) {
            worksiteNameEl.textContent = worksiteName;
        }
        loadWorksiteSupervisors(siteField.value);
    }

    // Initialize search functionality
    initializeSearch();

    // Initialize submit button interception
    initSubmitInterception();

    // Expose function for external calls (from preview-update.js)
    window.sfCheckSupervisorSection = checkAndShowSupervisorSection;
}

/**
 * Initialize search functionality for finding supervisors from other worksites
 */
function initializeSearch() {
    const searchInput = document.getElementById('sfSupervisorSearch');
    const clearBtn = document.getElementById('sfClearSearch');
    const searchResults = document.getElementById('sfSearchResults');

    if (!searchInput) return;

    // Search on input
    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();

        // Show/hide clear button
        if (clearBtn) {
            clearBtn.style.display = query ? 'block' : 'none';
        }

        // Perform search
        if (query) {
            performSearch(query);
        } else {
            // Hide search results when query is empty
            if (searchResults) {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
        }
    });

    // Clear search
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            clearBtn.style.display = 'none';
            if (searchResults) {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
            searchInput.focus();
        });
    }
}

/**
 * Perform search for supervisors from other worksites
 */
function performSearch(query) {
    const searchResults = document.getElementById('sfSearchResults');
    if (!searchResults) return;

    // Filter supervisors
    const filtered = allSupervisors.filter(sup => {
        const firstName = sup.first_name || '';
        const lastName = sup.last_name || '';
        const name = `${firstName} ${lastName}`.trim().toLowerCase();
        const worksite = (sup.worksite || '').toLowerCase();
        return name.includes(query) || worksite.includes(query);
    });

    if (filtered.length === 0) {
        searchResults.innerHTML = `<p class="sf-no-results-message">${getI18n('no_search_results', 'Ei tuloksia haulla.')}</p>`;
        searchResults.style.display = 'block';
        return;
    }

    // Render results as chips
    const globalLabel = getI18n('global_worksite_label', 'Globaali');
    const noNameLabel = getI18n('no_name_label', 'Ei nimeä');
    const html = filtered.map(sup => {
        const firstName = sup.first_name || '';
        const lastName = sup.last_name || '';
        const name = `${firstName} ${lastName}`.trim() || noNameLabel;
        const worksite = sup.worksite || globalLabel;
        return `
            <div class="sf-supervisor-chip" data-supervisor-id="${sup.id}" data-supervisor-name="${escapeHtml(name)}" data-supervisor-worksite="${escapeHtml(worksite)}">
                <span class="sf-supervisor-chip-name">${escapeHtml(name)}</span>
                <span class="sf-chip-role" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 4px;">${escapeHtml(worksite)}</span>
                <span class="sf-supervisor-chip-status">${escapeHtml(getI18n('supervisor_chip_select', 'Valitse'))}</span>
            </div>
        `;
    }).join('');

    searchResults.innerHTML = `
        <div class="sf-supervisor-chips" style="margin-top: 12px;">
            ${html}
        </div>
    `;
    searchResults.style.display = 'block';

    // Add click handlers
    searchResults.querySelectorAll('.sf-supervisor-chip').forEach(chip => {
        chip.addEventListener('click', () => toggleSupervisorChip(chip));
    });
}

/**
 * Get i18n message with fallback
 */
function getI18nMessage(key, fallback) {
    const i18n = window.SF_I18N || {};
    return i18n[key] || fallback;
}

/**
 * Update selection count (kept for backward compatibility with old search)
 */
window.updateSupervisorSelection = function () {
    // Count both worksite chips and any old-style checkboxes
    const worksiteSelected = document.querySelectorAll('#sfWorksiteSupervisors .sf-supervisor-chip.selected').length;
    const searchSelected = document.querySelectorAll('#sfSearchResults .sf-supervisor-chip.selected').length;
    const checkboxSelected = document.querySelectorAll('input[name="approver_ids[]"]:checked').length;
    const count = worksiteSelected + searchSelected + checkboxSelected;

    // Update new counter
    const counterEl = document.getElementById('sfSelectedCount');
    if (counterEl) {
        counterEl.textContent = count;
    }

    // Backward compatibility: update old counter if it exists
    const countDisplay = document.getElementById('sfSelectedSupervisorCount');
    const summarySection = document.getElementById('sfSupervisorSummary');

    if (summarySection) {
        summarySection.style.display = count > 0 ? 'block' : 'none';
    }

    if (countDisplay) {
        const i18n = window.SF_I18N || {};
        const countLabel = count === 1
            ? (i18n.site_manager_singular || 'työmaavastaava')
            : (i18n.site_managers_count || 'työmaavastaavaa');
        const selectedLabel = i18n.site_managers_selected_label || 'valittu';
        countDisplay.textContent = `${count} ${countLabel} ${selectedLabel}`;
    }

    // Update hidden inputs with all selected IDs
    const hiddenInput1 = document.getElementById('selectedApprovers');
    const hiddenInput2 = document.getElementById('approverIds');

    if (hiddenInput1 || hiddenInput2) {
        // Collect IDs from both sources
        const ids = [];

        // From checkboxes
        document.querySelectorAll('input[name="approver_ids[]"]:checked').forEach(cb => {
            ids.push(cb.value);
        });

        // From chips
        document.querySelectorAll('.sf-supervisor-chip.selected').forEach(chip => {
            const id = chip.dataset.supervisorId;
            if (id && !ids.includes(id)) {
                ids.push(id);
            }
        });

        const idsJson = JSON.stringify(ids);
        if (hiddenInput1) hiddenInput1.value = idsJson;
        if (hiddenInput2) hiddenInput2.value = idsJson;
    }
};

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (text == null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

/**
 * Show error message using toast or fallback to alert
 */
function showError(message) {
    if (typeof window.sfToast === 'function') {
        window.sfToast('error', message);
    } else {
        alert(message);
    }
}

/**
 * Create or update hidden form input
 */
function setHiddenInput(form, name, value) {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    input.value = value;
    return input;
}

/**
 * Show confirmation modal before submitting
 */
window.sfShowSubmitModal = function () {
    // Check BOTH checkboxes AND chips
    const checkboxes = document.querySelectorAll('input[name="approver_ids[]"]:checked');
    const selectedChips = document.querySelectorAll('.sf-supervisor-chip.selected');
    const i18n = window.SF_I18N || {};

    console.log('[SupervisorApproval] Modal check:', {
        checkboxes: checkboxes.length,
        chips: selectedChips.length
    });

    if (checkboxes.length === 0 && selectedChips.length === 0) {
        showError(i18n.validation_select_at_least_one_site_manager || 'Valitse vähintään yksi työmaavastaava');
        return;
    }

    // Populate summary
    const summaryContainer = document.getElementById('sfModalSupervisorsSummary');
    if (!summaryContainer) return;

    summaryContainer.innerHTML = '';

    // Add checkboxes to summary
    checkboxes.forEach(cb => {
        const name = cb.dataset.supervisorName || '';
        const worksite = cb.dataset.supervisorWorksite || '';

        const item = document.createElement('div');
        item.className = 'sf-supervisor-summary-item';
        item.innerHTML = `
            <span class="sf-supervisor-summary-avatar">${name.charAt(0)}</span>
            <div class="sf-supervisor-summary-info">
                <div class="sf-supervisor-summary-name">${escapeHtml(name)}</div>
                <div class="sf-supervisor-summary-worksite">${escapeHtml(worksite)}</div>
            </div>
        `;
        summaryContainer.appendChild(item);
    });

    // Add chips to summary
    selectedChips.forEach(chip => {
        const name = chip.dataset.supervisorName || '';
        const worksite = chip.dataset.supervisorWorksite || '';

        const item = document.createElement('div');
        item.className = 'sf-supervisor-summary-item';
        item.innerHTML = `
            <span class="sf-supervisor-summary-avatar">${name.charAt(0)}</span>
            <div class="sf-supervisor-summary-info">
                <div class="sf-supervisor-summary-name">${escapeHtml(name)}</div>
                <div class="sf-supervisor-summary-worksite">${escapeHtml(worksite)}</div>
            </div>
        `;
        summaryContainer.appendChild(item);
    });

    // Show modal
    const modal = document.getElementById('sfSubmitConfirmModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent background scroll
    }
};

/**
 * Close modal
 */
window.sfCloseSubmitModal = function () {
    const modal = document.getElementById('sfSubmitConfirmModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
};

/**
 * Edit supervisors - close modal and scroll to section
 */
window.sfEditSupervisors = function () {
    window.sfCloseSubmitModal();
    const section = document.getElementById('sfSupervisorApprovalSection');
    if (section) {
        section.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
};

/**
 * Confirm and submit form with all data
 */
window.sfConfirmSubmit = function () {
    const form = document.getElementById('sf-form');
    if (!form) {
        console.error('[SupervisorApproval] Form not found');
        return;
    }

    // Verify title field is present and filled
    const titleInput = form.querySelector('input[name="title"], #sf-title');
    if (!titleInput || !titleInput.value.trim()) {
        console.error('[SupervisorApproval] Title field missing or empty');
showError(getI18n('validation_title_required', 'Otsikko puuttuu'));
window.sfCloseSubmitModal();
return;
    }

    // Collect selected approver IDs from BOTH checkboxes AND chips
    const approverIds = [];

    // From checkboxes
    document.querySelectorAll('input[name="approver_ids[]"]:checked').forEach(cb => {
        approverIds.push(cb.value);
    });

    // From chips
    document.querySelectorAll('.sf-supervisor-chip.selected').forEach(chip => {
        const id = chip.dataset.supervisorId;
        if (id && !approverIds.includes(id)) {
            approverIds.push(id);
        }
    });

    // Set approver_ids as JSON (required by save_flash.php)
    setHiddenInput(form, 'approver_ids', JSON.stringify(approverIds));

    // Get submission comment if provided
    const submissionComment = document.getElementById('submissionComment')?.value?.trim() || '';
    setHiddenInput(form, 'submission_comment', submissionComment);

    console.log('[SupervisorApproval] Submitting form with:', {
        title: titleInput.value,
        approvers: approverIds,
        submissionType: 'review',
        submissionComment: submissionComment ? 'Yes' : 'No'
    });

    // Close the modal
    window.sfCloseSubmitModal();

    // Use normal submission flow for ALL cases (new submission AND resubmission)
    // This ensures preview generation happens through save_flash.php
    const IS_DRAFT = false;
    if (window.sfFormSubmit && typeof window.sfFormSubmit === 'function') {
        window.sfFormSubmit(form, IS_DRAFT);
    } else {
        // Fallback: direct form submission
        setHiddenInput(form, 'submission_type', 'review');
        form.submit();
    }
};

/**
 * Initialize submit button interception
 */
export function initSubmitInterception() {
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSubmitInterception);
    } else {
        setupSubmitInterception();
    }
}

function setupSubmitInterception() {
    const submitBtn = document.getElementById('sfSubmitReview');

    if (!submitBtn) {
        console.warn('[SupervisorApproval] Final submit button #sfSubmitReview not found');
        return;
    }

    // Remove any existing listeners by cloning
    const newBtn = submitBtn.cloneNode(true);
    submitBtn.parentNode.replaceChild(newBtn, submitBtn);

    newBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const form = document.getElementById('sf-form');
        const adminImportEnabled = form?.querySelector('#sfAdminImportEnabled')?.checked === true;
        const adminImportState = form?.querySelector('[name="admin_initial_state"]')?.value || '';
        const skipSupervisorValidation = adminImportEnabled && adminImportState !== '' && adminImportState !== 'pending_supervisor';

        if (skipSupervisorValidation) {
            if (window.sfFormSubmit && typeof window.sfFormSubmit === 'function') {
                window.sfFormSubmit(form, false);
                return;
            }

            form.submit();
            return;
        }

        console.log('[SupervisorApproval] Submit button clicked');

        // Validate that at least one supervisor is selected

        // Validate that at least one supervisor is selected
        // Check ALL sources: chips, checkboxes, AND hidden inputs
        const checkboxes = document.querySelectorAll('input[name="approver_ids[]"]:checked');
        const selectedChips = document.querySelectorAll('.sf-supervisor-chip.selected');

        // Also check hidden inputs that might contain selection data
        const hiddenInput = document.getElementById('approverIds') || document.getElementById('selectedApprovers');
        let hiddenIds = [];
        if (hiddenInput && hiddenInput.value) {
            try {
                hiddenIds = JSON.parse(hiddenInput.value);
            } catch (e) {
                hiddenIds = [];
            }
        }

        console.log('[SupervisorApproval] Validation check:', {
            checkboxes: checkboxes.length,
            chips: selectedChips.length,
            hiddenIds: hiddenIds.length
        });

        // Must have at least one selection from any source
        if (checkboxes.length === 0 && selectedChips.length === 0 && hiddenIds.length === 0) {
showError(getI18n('validation_select_at_least_one_site_manager', 'Valitse vähintään yksi työmaavastaava'));
return;
        }

        // Show confirmation modal
        window.sfShowSubmitModal();
    });

    console.log('[SupervisorApproval] Submit interception initialized');
}