// assets/js/modules/autosave.js
// Autosave functionality for SafetyFlash forms

export const autoSave = {
    intervalId: null,
    intervalMs: 30000, // 30 seconds
    isEditing: false,
    currentDraftId: null,
    beforeUnloadBound: false,
    submissionInProgress: false,
    redirectInProgress: false,

    /**
     * Initialize autosave
     */
    init() {
        // Check if we're editing an existing flash
        const idInput = document.querySelector('input[name="id"]');
        const relatedFlashElement = document.getElementById('sf-related-flash-id');
        const hasNonEmptyValue = (element) => !!element?.value?.trim();
        const hasId = hasNonEmptyValue(idInput);
        const hasRelatedFlash = hasNonEmptyValue(relatedFlashElement);

        // Investigation flash creation can include id in URL/context, but should still autosave
        const isCreatingInvestigation = hasId && hasRelatedFlash;
        this.isEditing = hasId && !isCreatingInvestigation;

        // Don't autosave when editing existing flashes
        if (this.isEditing) {
            return;
        }

        // Initialize draft recovery overlay
        this.initDraftRecoveryOverlay();

        // Don't start autosave immediately - wait until user reaches content step
        // Autosave will be started by startIfContentStep() when user enters step 3
        this.watchForContentStep();

        // Save before unload (bind only once)
        if (!this.beforeUnloadBound) {
            // Capture reference to autoSave object to avoid 'this' binding issues in arrow function
            const self = this;
            window.addEventListener('beforeunload', () => {
                // Don't save if submission is in progress or completed
                if (self.submissionInProgress || self.redirectInProgress) {
                    return;
                }

                // Don't save if not on content step yet
                if (!self.isOnContentStep()) {
                    return;
                }

                const formData = self.collectFormData();
                if (formData) {
                    const data = JSON.stringify({
                        csrf_token: self.getCsrfToken(),
                        draft_id: self.currentDraftId,
                        flash_type: formData.type,
                        form_data: formData,
                    });
                    const blob = new Blob([data], { type: 'application/json' });
                    navigator.sendBeacon(self.getBaseUrl() + '/app/api/drafts_save.php', blob);
                }
            });
            this.beforeUnloadBound = true;
        }
    },

    /**
     * Start autosave interval
     */
    start() {
        if (this.intervalId) {
            return;
        }

        this.intervalId = setInterval(() => {
            this.saveNow();
        }, this.intervalMs);
    },

    /**
     * Stop autosave interval
     */
    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    },
    /**
     * Watch for when user enters content step (step 3) to start autosave
     */
    watchForContentStep() {
        const self = this;

        // Check if already on content step
        if (this.isOnContentStep()) {
            this.start();
            return;
        }

        // Watch for step changes via MutationObserver
        const observer = new MutationObserver(() => {
            if (self.isOnContentStep() && !self.intervalId) {
                self.start();
            }
        });

        // Observe .sf-step-content elements for class changes (active added/removed)
        document.querySelectorAll('.sf-step-content').forEach(stepEl => {
            observer.observe(stepEl, {
                attributes: true,
                attributeFilter: ['class']
            });
        });

        // Also observe progress steps (using new BEM selectors)
        document.querySelectorAll('.sf-form-progress__step').forEach(stepEl => {
            observer.observe(stepEl, {
                attributes: true,
                attributeFilter: ['class']
            });
        });

        // Fallback: watch for clicks on "Next" buttons
        document.querySelectorAll('.sf-next-btn, #sfNext, #sfNext1, #sfNext2').forEach(btn => {
            btn.addEventListener('click', () => {
                // Small delay to let step change complete
                setTimeout(() => {
                    if (self.isOnContentStep() && !self.intervalId) {
                        self.start();
                    }
                }, 200);
            });
        });
    },

    /**
     * Check if user is on content step (step 3 or later)
     */
    isOnContentStep() {        // Check for step 3+ being visible/active via .sf-step-content.active
        const activeStepContent = document.querySelector('.sf-step-content.active');
        if (activeStepContent) {
            const stepNum = parseInt(activeStepContent.dataset.step, 10);
            if (stepNum >= 3) {
                return true;
            }
        }

        // Check for active step in progress bar (using new BEM selectors)
        const activeProgressStep = document.querySelector('.sf-form-progress__step.sf-form-progress__step--active .sf-form-progress__number');
        if (activeProgressStep) {
            const stepNum = parseInt(activeProgressStep.dataset.stepNum, 10);
            if (stepNum >= 3) {
                return true;
            }
        }

        return false;
    },    /**
     * Get base URL
     */
    getBaseUrl() {
        return (typeof window.SF_BASE_URL !== 'undefined' ? window.SF_BASE_URL : '').replace(/\/$/, '');
    },

    /**
     * Save draft now
     */
    async saveNow() {
        const formData = this.collectFormData();

        if (!formData) {
            return;
        }

        try {
            const response = await fetch(`${this.getBaseUrl()}/app/api/drafts_save.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: this.getCsrfToken(),
                    draft_id: this.currentDraftId,
                    flash_type: formData.type,
                    form_data: formData,
                }),
            });

            const result = await response.json();

            // API returns 'ok' not 'success'
            if (result.ok && result.draft_id) {
                this.currentDraftId = result.draft_id;
                this.showAutosaveNotification();
            }
        } catch (error) {
            console.error('Autosave error:', error);
        }
    },

    /**
     * Show subtle autosave notification
     */
    showAutosaveNotification() {
        // Remove existing notification if any
        const existing = document.getElementById('sfAutosaveNotification');
        if (existing) {
            existing.remove();
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.id = 'sfAutosaveNotification';
        notification.className = 'sf-autosave-notification';
        notification.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            <span>${window.SF_I18N?.draft_saved || 'Luonnos tallennettu'}</span>
        `;

        document.body.appendChild(notification);

        // Trigger animation
        requestAnimationFrame(() => {
            notification.classList.add('sf-autosave-visible');
        });

        // Remove after 2 seconds
        setTimeout(() => {
            notification.classList.remove('sf-autosave-visible');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 2000);
    },
    /**
     * Collect form data for saving
     */
    collectFormData() {
        const form = document.getElementById('sf-form');
        if (!form) {
            return null;
        }

        const data = {
            lang: this.getFieldValue('input[name="lang"]:checked'),
            type: this.getFieldValue('input[name="type"]:checked'),
            title: this.getFieldValue('#sf-title'),
            short_text: this.getFieldValue('#sf-short-text'),
            description: this.getFieldValue('#sf-description'),
            root_causes: this.getFieldValue('#sf-root-causes'),
            actions: this.getFieldValue('#sf-actions'),
            worksite: this.getFieldValue('#sf-worksite'),
            site_detail: this.getFieldValue('#sf-site-detail'),
            event_date: this.getFieldValue('#sf-date'),
        };

        // Only save if at least one field has content
        const hasContent = Object.values(data).some(val => val && typeof val === 'string' && val.trim());

        return hasContent ? data : null;
    },

    /**
     * Get field value helper
     */
    getFieldValue(selector) {
        const el = document.querySelector(selector);
        if (!el) {
            return '';
        }

        if (el.type === 'radio' || el.type === 'checkbox') {
            return el.value || '';
        }

        return el.value || '';
    },

    /**
     * Get CSRF token
     */
    getCsrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    },

    /**
     * Load a draft
     */
    async loadDraft(draftId) {
        try {
            const response = await fetch(this.getBaseUrl() + '/app/api/drafts_load.php', {
                method: 'GET',
                credentials: 'same-origin',
            });

            const result = await response.json();

            // API returns 'ok' and 'drafts' array
            if (result.ok && result.drafts) {
                const draft = result.drafts.find(d => d.id === draftId);
                if (draft) {
                    this.currentDraftId = draftId;
                    this.applyDraftToForm(draft);
                }
            }
        } catch (error) {
            console.error('Load draft error:', error);
        }
    },

    /**
     * Apply draft data to form
     */
    applyDraftToForm(draft) {
        const formData = typeof draft.form_data === 'string'
            ? JSON.parse(draft.form_data)
            : draft.form_data;

        // Apply language
        if (formData.lang) {
            const langRadio = document.querySelector(`input[name="lang"][value="${formData.lang}"]`);
            if (langRadio) {
                langRadio.checked = true;
                langRadio.dispatchEvent(new Event('change', { bubbles: true }));
                const langBox = langRadio.closest('.sf-lang-box');
                if (langBox) {
                    langBox.click();
                }
            }
        }

        // Apply type
        if (formData.type) {
            const typeRadio = document.querySelector(`input[name="type"][value="${formData.type}"]`);
            if (typeRadio) {
                typeRadio.checked = true;
                typeRadio.dispatchEvent(new Event('change', { bubbles: true }));
                const typeBox = typeRadio.closest('.sf-type-box');
                if (typeBox) {
                    typeBox.click();
                }
            }
        }

        // Apply text fields
        this.setFieldValue('#sf-title', formData.title);
        this.setFieldValue('#sf-short-text', formData.short_text);
        this.setFieldValue('#sf-description', formData.description);
        this.setFieldValue('#sf-root-causes', formData.root_causes);
        this.setFieldValue('#sf-actions', formData.actions);
        this.setFieldValue('#sf-worksite', formData.worksite);
        this.setFieldValue('#sf-site-detail', formData.site_detail);
        this.setFieldValue('#sf-date', formData.event_date);

        // Trigger input events to update UI
        document.querySelectorAll('#sf-form input, #sf-form textarea, #sf-form select').forEach(el => {
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    },

    /**
     * Set field value helper
     */
    setFieldValue(selector, value) {
        const el = document.querySelector(selector);
        if (el && value) {
            el.value = value;
        }
    },

    /**
     * Delete a draft
     */
    async deleteDraft(draftId) {
        try {
            const response = await fetch(this.getBaseUrl() + '/app/api/drafts_delete.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: this.getCsrfToken(),
                    draft_id: draftId,
                }),
            });

            const result = await response.json();

            // API returns 'ok' not 'success'
            if (result.ok && draftId === this.currentDraftId) {
                this.currentDraftId = null;
            }

            return result.ok;
        } catch (error) {
            console.error('Delete draft error:', error);
            return false;
        }
    },

    /**
     * Show spinner on button
     */
    showButtonSpinner(btn) {
        if (!btn) return;
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.innerHTML = '<span class="sf-btn-spinner"></span> ' + btn.textContent;
        btn.classList.add('sf-btn-loading');
    },

    /**
     * Hide spinner on button
     */
    hideButtonSpinner(btn) {
        if (!btn) return;
        btn.disabled = false;
        btn.textContent = btn.dataset.originalText || btn.textContent;
        btn.classList.remove('sf-btn-loading');
    },

    /**
     * Disable all overlay buttons
     */
    disableOverlayButtons(overlay) {
        overlay.querySelectorAll('button').forEach(btn => {
            btn.disabled = true;
        });
    },

    /**
     * Draft recovery overlay handling
     */
    initDraftRecoveryOverlay() {
        const overlay = document.getElementById('sfDraftRecoveryOverlay');
        if (!overlay) {
            return;
        }

        const self = this;

        // Block form interaction until choice is made
        const form = document.getElementById('sf-form');
        if (form) {
            form.style.pointerEvents = 'none';
            form.style.opacity = '0.3';
        }

        const hideOverlay = () => {
            overlay.style.display = 'none';
            if (form) {
                form.style.pointerEvents = '';
                form.style.opacity = '';
            }
        };

        // Continue with draft
        overlay.querySelectorAll('.sf-draft-continue').forEach(btn => {
            btn.addEventListener('click', async function () {
                const draftId = parseInt(this.dataset.draftId, 10);

                // Show spinner and disable all buttons
                self.showButtonSpinner(this);
                self.disableOverlayButtons(overlay);

                try {
                    await self.loadDraft(draftId);
                    hideOverlay();
                } catch (error) {
                    console.error('Error loading draft:', error);
                    self.hideButtonSpinner(this);
                    // Re-enable buttons on error
                    overlay.querySelectorAll('button').forEach(b => b.disabled = false);
                }
            });
        });

        // Discard single draft
        overlay.querySelectorAll('.sf-draft-discard').forEach(btn => {
            btn.addEventListener('click', async function () {
                const draftId = parseInt(this.dataset.draftId, 10);

                // Show spinner and disable all buttons
                self.showButtonSpinner(this);
                self.disableOverlayButtons(overlay);

                // Prevent beforeunload from saving a new draft
                self.submissionInProgress = true;
                self.stop();

                try {
                    // Wait for deletion to complete
                    const deleted = await self.deleteDraft(draftId);

                    if (deleted) {
                        this.closest('.sf-draft-item').remove();

                        // If no more drafts, hide overlay and allow new autosave
                        if (overlay.querySelectorAll('.sf-draft-item').length === 0) {
                            hideOverlay();
                            // Reset and allow new autosave for fresh form
                            self.submissionInProgress = false;
                            self.currentDraftId = null;
                            self.watchForContentStep();
                        } else {
                            // More drafts remain, re-enable buttons
                            overlay.querySelectorAll('button').forEach(b => b.disabled = false);
                            self.submissionInProgress = false;
                            self.watchForContentStep();
                        }
                    } else {
                        throw new Error('Delete failed');
                    }
                } catch (error) {
                    console.error('Error deleting draft:', error);
                    self.hideButtonSpinner(this);
                    overlay.querySelectorAll('button').forEach(b => b.disabled = false);
                    self.submissionInProgress = false;
                    self.watchForContentStep();
                }
            });
        });

        // Start new (discard all)
        const startNewBtn = document.getElementById('sfDraftStartNew');
        if (startNewBtn) {
            startNewBtn.addEventListener('click', async function () {
                // Show spinner and disable all buttons
                self.showButtonSpinner(this);
                self.disableOverlayButtons(overlay);

                // Prevent beforeunload from saving a new draft during deletion
                self.submissionInProgress = true;
                self.stop();

                try {
                    const drafts = window.SF_USER_DRAFTS || [];

                    // Delete all drafts and wait for each to complete
                    for (const draft of drafts) {
                        await self.deleteDraft(draft.id);
                    }

                    hideOverlay();

                    // Reset and allow new autosave for fresh form
                    self.submissionInProgress = false;
                    self.currentDraftId = null;
                    self.watchForContentStep();
                } catch (error) {
                    console.error('Error deleting drafts:', error);
                    self.hideButtonSpinner(this);
                    overlay.querySelectorAll('button').forEach(b => b.disabled = false);
                    self.submissionInProgress = false;
                    self.watchForContentStep();
                }
            });
        }
    },
};