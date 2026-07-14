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
export function updateProgressIndicators() {
    const currentStep = state.currentStep;
    const maxAccessible = getMaxAccessibleStep();

    // Use new BEM selectors
    qsa('.sf-form-progress__step').forEach(btn => {
        const stepNum = parseInt(btn.dataset.step, 10);

        // Remove all state classes (using BEM modifiers)
        btn.classList.remove('sf-form-progress__step--active', 'sf-form-progress__step--completed', 'sf-form-progress__step--accessible', 'sf-form-progress__step--locked');

        if (stepNum === currentStep) {
            // Current step
            btn.classList.add('sf-form-progress__step--active');
            btn.disabled = false;
            btn.setAttribute('aria-current', 'step');
        } else {
            btn.removeAttribute('aria-current');
            if (stepNum < currentStep) {
                // Previous steps - mark as completed if they pass validation
                const errors = validateStep(stepNum);
                if (errors.length === 0) {
                    btn.classList.add('sf-form-progress__step--completed');
                }
                btn.classList.add('sf-form-progress__step--accessible');
                btn.disabled = false;
            } else if (stepNum <= maxAccessible) {
                // Future steps that are accessible
                btn.classList.add('sf-form-progress__step--accessible');
                btn.disabled = false;
            } else {
                // Locked future steps
                btn.classList.add('sf-form-progress__step--locked');
                btn.disabled = true;
            }
        }
    });

    // Update progress bar width and ARIA attributes (using new IDs)
    const progressFill = getEl('sfProgressFill');
    const progressTrack = qs('.sf-form-progress__track');
    if (progressFill && progressTrack) {
        const progress = ((currentStep - 1) / (state.maxSteps - 1)) * 100;
        progressFill.style.width = `${progress}%`;
        progressTrack.setAttribute('aria-valuenow', currentStep.toString());
    }
}

export async function showStep(stepNumber, skipScroll = false) {
    state.currentStep = stepNumber;

    qsa('.sf-step-content').forEach(stepEl => {
        const isActive = parseInt(stepEl.dataset.step, 10) === stepNumber;
        stepEl.classList.toggle('active', isActive);

        if (isActive) {
            const prevBtn = stepEl.querySelector('.sf-prev-btn');
            if (prevBtn) prevBtn.style.display = (stepNumber === 1) ? 'none' : '';
        }
    });

    await updateUIForStep(stepNumber);
    updateProgressIndicators();

    // Issue 7: Rebind annotation frames when showing steps 5 or 6
    if ((stepNumber === 5 || stepNumber === 6) && window.Annotations && typeof window.Annotations._bindFrames === 'function') {
        setTimeout(() => {
            window.Annotations._bindFrames();
        }, 100);
    }

    if (!skipScroll) {
        setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 50);
    }
}

// Expose for non-module scripts (image edit flow) as soon as the module loads
// SFShowStep: Used by image-edit.js and other legacy scripts to navigate between steps
// SFUpdateProgress: Used by other modules to update progress indicators when form state changes
if (typeof window !== 'undefined') {
    window.SFShowStep = showStep;
    window.SFUpdateProgress = updateProgressIndicators;
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

    // Bind clickable progress steps (using new BEM selector)
    document.querySelectorAll('.sf-form-progress__step').forEach(btn => {
        if (btn.dataset.sfNavBound) return;
        btn.dataset.sfNavBound = '1';
        btn.addEventListener('click', () => {
            const targetStep = parseInt(btn.dataset.step, 10);
            if (btn.disabled || btn.classList.contains('sf-form-progress__step--locked')) {
                return;
            }
            navigateToStep(targetStep);
        });

        // Add keyboard navigation support
        btn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const targetStep = parseInt(btn.dataset.step, 10);
                if (!btn.disabled && !btn.classList.contains('sf-form-progress__step--locked')) {
                    navigateToStep(targetStep);
                }
            }
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

    // Update indicators when standalone toggle changes
    const standaloneToggle = document.getElementById('sf-standalone-investigation');
    if (standaloneToggle) {
        standaloneToggle.addEventListener('change', () => {
            // Immediate update when toggle changes
            updateProgressIndicators();
        });
    }
}