/**
 * SafetyFlash Copy-to-Clipboard Module
 * Provides functionality to copy preview images to clipboard
 */

(function () {
    'use strict';

    /**
     * Copy an HTML element as an image to clipboard
     * @param {HTMLElement} element - The element to capture
     * @param {Object} options - html2canvas options (unused, kept for compatibility)
     * @returns {Promise<boolean>} - Resolves with true on success
     */
    async function copyImageToClipboard(element, options = {}) {
        // Check if Clipboard API is supported
        if (!navigator.clipboard || !ClipboardItem) {
            throw new Error('Clipboard API not supported in this browser');
        }

        // Check if HTTPS or localhost
        if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
            throw new Error('Clipboard API requires HTTPS or localhost');
        }

        try {
            // Find the image element
            const img = element.querySelector('img');
            if (!img || !img.src) {
                throw new Error('No image found');
            }

            // Fetch the original image
            const response = await fetch(img.src);
            if (!response.ok) {
                throw new Error('Failed to fetch image');
            }
            const blob = await response.blob();

            // Convert to PNG if needed (clipboard requires PNG)
            let pngBlob = blob;
            if (blob.type === 'image/jpeg' || blob.type === 'image/jpg') {
                const imageBitmap = await createImageBitmap(blob);
                const canvas = document.createElement('canvas');
                canvas.width = imageBitmap.width;
                canvas.height = imageBitmap.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(imageBitmap, 0, 0);
                pngBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            }

            // Create ClipboardItem and write to clipboard
            const clipboardItem = new ClipboardItem({ 'image/png': pngBlob });
            await navigator.clipboard.write([clipboardItem]);

            return true;
        } catch (error) {
            console.error('Copy to clipboard failed:', error);
            throw error;
        }
    }

    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - 'success' or 'error'
     */
    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - 'success' or 'error'
     */
    function showCopyToast(message, type = 'success') {
        // Use global toast if available (from header. php)
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
            return;
        }

        // Fallback:  create inline toast - SIMPLIFIED VERSION
        const existingToast = document.getElementById('sfCopyToast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.id = 'sfCopyToast';

        // Add error class if needed
        if (type === 'error') {
            toast.classList.add('error');
        }

        const iconSvg = type === 'error'
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';

        toast.innerHTML = `
        <span style="flex-shrink: 0; width: 20px; height: 20px;">${iconSvg}</span>
        <span>${message}</span>
    `;

        document.body.appendChild(toast);

        // Trigger animation AFTER element is in DOM
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });
        });

        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    /**
     * Add a copy button to a target element
     * @param {HTMLElement} targetElement - The element to add button to
     * @param {Object} buttonOptions - Button configuration
     * @returns {HTMLElement} - The created button element
     */
    /**
     * Add a copy button to a target element
     * @param {HTMLElement} targetElement - The element to add button to
     * @param {Object} buttonOptions - Button configuration
     * @returns {HTMLElement} - The created button element
     */
    /**
     * Add a copy button to a target element
     * @param {HTMLElement} targetElement - The element to add button to
     * @param {Object} buttonOptions - Button configuration
     * @returns {HTMLElement} - The created button element
     */
    function addCopyButton(targetElement, buttonOptions = {}) {
        const {
            label = 'Copy image',
            copyingLabel = 'Copying...',
            successMessage = 'Image copied to clipboard!',
            errorMessage = 'Copy failed',
            iconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>',
            position = 'top-right',
            className = '',
            analyticsEvent = '',
            analyticsMetadata = {}
        } = buttonOptions;

        // Create button
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `sf-copy-btn ${className}`.trim();
        button.setAttribute('data-position', position);
        button.setAttribute('aria-label', label);
        button.setAttribute('data-tooltip', label);
        button.setAttribute('data-success-message', successMessage);
        button.setAttribute('data-error-message', errorMessage);
        button.innerHTML = `
        ${iconSvg}
        <span class="sf-copy-btn-label">${label}</span>
    `;

        // Add click handler
        button.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            // Check if already copying
            if (button.disabled) return;

            // Save original HTML
            const originalHtml = button.innerHTML;

            // Disable button and show loading
            button.disabled = true;
            button.innerHTML = `
            <span class="sf-spinner"></span>
            <span class="sf-copy-btn-label">${copyingLabel}</span>
        `;

            try {
                // Copy the target element
                await copyImageToClipboard(targetElement);

                // SUCCESS: Change button to show success text
if (analyticsEvent && window.SafetyFlashAnalytics && typeof window.SafetyFlashAnalytics.track === 'function') {
    window.SafetyFlashAnalytics.track({
        event_type: analyticsEvent,
        metadata: Object.assign({}, analyticsMetadata, {
            result: 'success'
        })
    });
}

button.innerHTML = `
<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
    <polyline points="20 6 9 17 4 12"></polyline>
</svg>
<span class="sf-copy-btn-label" style="display: inline ! important;">${successMessage}</span>
`;
                button.classList.add('success-flash');
                button.disabled = false; // Re-enable immediately

                // Restore original button after 2 seconds
                setTimeout(() => {
                    button.classList.remove('success-flash');
                    button.innerHTML = originalHtml;
                }, 2000);

} catch (error) {
    console.error('Copy failed:', error);

    if (analyticsEvent && window.SafetyFlashAnalytics && typeof window.SafetyFlashAnalytics.track === 'function') {
        window.SafetyFlashAnalytics.track({
            event_type: analyticsEvent + '_failed',
            metadata: Object.assign({}, analyticsMetadata, {
                result: 'failed',
                error_message: error && error.message ? error.message : ''
            })
        });
    }

                // ERROR: Change button to show error text
                let errorMsg = errorMessage;
                if (error.message.includes('not supported')) {
                    errorMsg += ' (Browser not supported)';
                } else if (error.message.includes('HTTPS')) {
                    errorMsg += ' (HTTPS required)';
                }

                button.innerHTML = `
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <span class="sf-copy-btn-label" style="display: inline !important;">${errorMsg}</span>
            `;
                button.classList.add('error-flash');
                button.disabled = false; // Re-enable immediately

                // Restore original button after 2.5 seconds
                setTimeout(() => {
                    button.classList.remove('error-flash');
                    button.innerHTML = originalHtml;
                }, 2500);
            }
        });

        // Add button to target element's parent
        if (targetElement.parentElement) {
            targetElement.parentElement.style.position = 'relative';
            targetElement.parentElement.appendChild(button);
        }

        return button;
    }    // Export functions globally
    window.SafetyFlashCopy = {
        copyImageToClipboard,
        showCopyToast,
        addCopyButton
    };
})();