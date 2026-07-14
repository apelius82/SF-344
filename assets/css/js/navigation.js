import { state, getters } from './state.js';
import { updateUIForStep, updatePreview, handleConditionalFields } from './preview-update.js';
import { validateStep, showValidationErrors } from './validation.js';

const { getEl, qs, qsa } = getters;

/**
 * Get the highest step the user can currently access
 * User can go back to any completed step, but can only go forward if current steps are valid
 */
function getMaxAccessibleStep() {
    // Always can access step 1
    let maxStep = 1;

    // Check each step's validity to determine how far user can go
    for (let step = 1; step < state.maxSteps; step++) {
        const errors = validateStep(step);
        if (errors.length === 0) {
            maxStep = step + 1;
        } else {
            break;
        }
    }

    return maxStep;
}

/**
 * Update progress step indicators
 */
function updateProgressIndicators() {
    const currentStep = state.currentStep;
    const maxAccessible = getMaxAccessibleStep();

    qsa('.sf-progress-step').forEach(btn => {
        const stepNum = parseInt(btn.dataset.step, 10);

        // Remove all state classes
        btn.classList.remove('active', 'completed', 'accessible', 'locked');

        if (stepNum === currentStep) {
            // Current step
            btn.classList.add('active');
            btn.disabled = false;
            btn.setAttribute('aria-current', 'step');
        } else {
            btn.removeAttribute('aria-current');
            if (stepNum < currentStep) {
                // Previous steps - mark as completed if they pass validation
                const errors = validateStep(stepNum);
                if (errors.length === 0) {
                    btn.classList.add('completed');
                }
                btn.classList.add('accessible');
                btn.disabled = false;
            } else if (stepNum <= maxAccessible) {
                // Future steps that are accessible
                btn.classList.add('accessible');
                btn.disabled = false;
            } else {
                // Locked future steps
                btn.classList.add('locked');
                btn.disabled = true;
            }
        }
    });

    // Update progress bar width and ARIA attributes
    const progressBar = getEl('sfProgressBar');
    const progressTrack = qs('.sf-progress-track');
    if (progressBar && progressTrack) {
        const progress = ((currentStep - 1) / (state.maxSteps - 1)) * 100;
        progressBar.style.width = `${progress}%`;
        progressTrack.setAttribute('aria-valuenow', currentStep.toString());
    }
}

export function showStep(stepNumber, skipScroll = false) {
    state.currentStep = stepNumber;

    qsa('.sf-step-content').forEach(stepEl => {
        const isActive = parseInt(stepEl.dataset.step, 10) === stepNumber;
        stepEl.classList.toggle('active', isActive);

        if (isActive) {
            const prevBtn = stepEl.querySelector('.sf-prev-btn');
            if (prevBtn) prevBtn.style.display = (stepNumber === 1) ? 'none' : '';
        }
    });

    updateUIForStep(stepNumber);
    updateProgressIndicators();

    if (!skipScroll) {
        setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 50);
    }

    // Auto-scroll to supervisor section when entering step 6
    if (stepNumber === 6) {
        setTimeout(() => {
            const supervisorSection = document.getElementById('sfSupervisorApprovalSection');
            if (supervisorSection && supervisorSection.style.display !== 'none') {
                supervisorSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 800);
    }
}

// Expose for non-module scripts (image edit flow) as soon as the module loads
if (typeof window !== 'undefined') {
    window.SFShowStep = showStep;
}

/**
 * Navigate to a specific step with validation
 */
function navigateToStep(targetStep) {
    const currentStep = state.currentStep;

    // Going backwards is always allowed
    if (targetStep < currentStep) {
        showStep(targetStep);
        return;
    }

    // Going forwards requires validation of all steps in between
    for (let step = currentStep; step < targetStep; step++) {
        const errors = validateStep(step);
        if (errors.length > 0) {
            // Show errors for the first invalid step
            showStep(step);
            showValidationErrors(errors);
            return;
        }
    }

    // All intermediate steps are valid, navigate to target
    showStep(targetStep);
}

export function bindStepButtons() {
    const { maxSteps } = state;

    // Bind Next buttons
    document.querySelectorAll('.sf-next-btn, #sfNext').forEach(btn => {
        if (btn.dataset.sfNavBound) return;
        btn.dataset.sfNavBound = '1';
        btn.addEventListener('click', () => {
            const errors = validateStep(state.currentStep);
            if (errors.length > 0) {
                showValidationErrors(errors);
                return;
            }
            const next = Math.min(state.currentStep + 1, maxSteps);
            showStep(next);
        });
    });

    // Bind Prev buttons
    document.querySelectorAll('.sf-prev-btn').forEach(btn => {
        if (btn.dataset.sfNavBound) return;
        btn.dataset.sfNavBound = '1';
        btn.addEventListener('click', () => {
            const prev = Math.max(state.currentStep - 1, 1);
            showStep(prev);
        });
    });

    // Bind clickable progress steps
    document.querySelectorAll('.sf-progress-step').forEach(btn => {
        if (btn.dataset.sfNavBound) return;
        btn.dataset.sfNavBound = '1';
        btn.addEventListener('click', () => {
            const targetStep = parseInt(btn.dataset.step, 10);
            if (btn.disabled || btn.classList.contains('locked')) {
                return;
            }
            navigateToStep(targetStep);
        });
    });

    // Initial update of progress indicators
    updateProgressIndicators();

    // Update indicators when form fields change
    document.querySelectorAll('#sf-form input, #sf-form select, #sf-form textarea').forEach(el => {
        el.addEventListener('change', () => {
            // Debounce the update
            clearTimeout(window.sfProgressUpdateTimeout);
            window.sfProgressUpdateTimeout = setTimeout(updateProgressIndicators, 100);
        });
    });
}