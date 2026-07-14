/**
 * Preview Polling Module
 * Auto-updates preview images when generation completes
 */
(function () {
    'use strict';

    const POLL_INTERVAL = 3000; // 3 seconds
    const MAX_POLLS = 40; // Max 2 minutes of polling

    let activePollers = {};

    function getBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    /**
     * Start polling for a flash preview
     * @param {number} flashId - Flash ID to poll
     * @param {object} options - Callback options
     */
    function startPolling(flashId, options = {}) {
        // Validate and sanitize flashId
        flashId = parseInt(flashId);
        if (isNaN(flashId) || flashId <= 0) {
            console.error('Invalid flashId for polling:', flashId);
            return;
        }

        if (activePollers[flashId]) {
            return; // Already polling
        }

        let pollCount = 0;

        const poll = async () => {
            try {
                const response = await fetch(`${getBaseUrl()}/app/api/check_preview_status.php?id=${encodeURIComponent(flashId)}`, {
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    console.error('Preview status HTTP error:', response.status);

                    if (options.onProgress) {
                        options.onProgress(flashId, 0, 'http_error');
                    }

                    return;
                }

                const data = await response.json();

                if (!data.ok) {
                    console.error('Preview status check failed:', data.error || 'Unknown error');

                    if (options.onProgress) {
                        options.onProgress(flashId, 0, 'api_error');
                    }

                    return;
                }

                if (options.onProgress) {
                    options.onProgress(flashId, data.progress, data.status);
                }

                if (data.ready && data.preview_url) {
                    stopPolling(flashId);

                    if (options.onComplete) {
                        options.onComplete(flashId, data.preview_url, data.preview_url_2);
                    }

                    return;
                }

                if (data.failed) {
                    stopPolling(flashId);

                    if (options.onFailed) {
                        options.onFailed(flashId);
                    }

                    return;
                }
            } catch (err) {
                console.error('Preview polling error:', err);
            } finally {
                pollCount++;

                if (pollCount >= MAX_POLLS && activePollers[flashId]) {
                    stopPolling(flashId);

                    if (options.onTimeout) {
                        options.onTimeout(flashId);
                    }
                }
            }
        };

        // Start polling
        poll();
        activePollers[flashId] = setInterval(poll, POLL_INTERVAL);
    }

    function stopPolling(flashId) {
        if (activePollers[flashId]) {
            clearInterval(activePollers[flashId]);
            delete activePollers[flashId];
        }
    }

    /**
     * Initialize polling for all pending previews on page
     */
    function initPagePolling() {
        // Find all cards with pending preview status
        const pendingCards = document.querySelectorAll('[data-preview-status="pending"], [data-preview-status="processing"]');

        pendingCards.forEach(card => {
            const flashId = card.dataset.flashId;
            const id = parseInt(flashId);
            if (!flashId || isNaN(id) || id <= 0) return;

            const progressBar = card.querySelector('.sf-preview-progress-bar');
            const progressText = card.querySelector('.sf-preview-progress-text');
            const thumbImg = card.querySelector('.card-thumb img, .sf-preview-thumb');
            const overlay = card.querySelector('.sf-generating-overlay');

            startPolling(id, {
                onProgress: (id, progress, status) => {
                    // List-sivu käyttää vain spinneriä, ei progress baria
                    // Progress bar päivitetään vain jos elementit löytyvät (view-sivu)
                    if (progressBar) {
                        progressBar.style.width = progress + '%';
                    }
                    if (progressText) {
                        progressText.textContent = progress + '%';
                    }
                },
                onComplete: (id, previewUrl, previewUrl2) => {
    const existingViewImg = card.querySelector('#viewPreviewImage, #viewPreviewImage1, .preview-image');

    if (existingViewImg && previewUrl) {
        existingViewImg.style.opacity = '0';
        existingViewImg.src = previewUrl;
        existingViewImg.classList.remove('sf-preview-image-loading');
        existingViewImg.onload = () => {
            existingViewImg.style.transition = 'opacity 0.5s ease';
            existingViewImg.style.opacity = '1';
            existingViewImg.classList.add('loaded');
        };
    }

    if (!existingViewImg && previewUrl) {
        const pendingPlaceholder = card.querySelector('.skeleton-preview-placeholder');

        if (pendingPlaceholder) {
            const loader = document.createElement('div');
            loader.className = 'sf-preview-image-loader';
            loader.setAttribute('data-sf-preview-loader', '');

            const image = document.createElement('img');
            image.src = previewUrl;
            image.alt = 'Preview';
            image.className = 'preview-image preview-image-clickable loaded';
            image.id = 'viewPreviewImage';
            image.loading = 'eager';
            image.decoding = 'async';
            image.setAttribute('data-preview-fullscreen-trigger', 'true');
            image.setAttribute('tabindex', '0');
            image.setAttribute('role', 'button');

            loader.appendChild(image);
            pendingPlaceholder.replaceWith(loader);
        }
    }

    if (previewUrl2) {
        const thumbImg2 = card.querySelector('#viewPreviewImage2, .sf-preview-thumb-2, .card-thumb-2 img');
        const viewCard2 = card.querySelector('#viewPreview2');

        if (viewCard2) {
            viewCard2.style.display = '';
        }

        if (thumbImg2) {
            thumbImg2.style.opacity = '0';
            thumbImg2.src = previewUrl2;
            thumbImg2.classList.remove('sf-preview-image-loading');
            thumbImg2.onload = () => {
                thumbImg2.style.transition = 'opacity 0.5s ease';
                thumbImg2.style.opacity = '1';
                thumbImg2.classList.add('loaded');
            };
        }
    }

    if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => overlay.remove(), 500);
    }

    const previewSpinner = document.getElementById('previewSpinner');
    if (previewSpinner) {
        previewSpinner.classList.add('loaded');
    }

    card.dataset.previewStatus = 'completed';
},
                onFailed: (id) => {
                    if (progressText) {
                        progressText.textContent = window.SF_I18N?.preview_error || 'Error';
                    }
                    if (overlay) {
                        overlay.classList.add('sf-generating-failed');
                    }
                },
                onTimeout: (id) => {
                    if (progressText) {
                        progressText.textContent = window.SF_I18N?.refresh_page || 'Refresh page';
                    }
                }
            });
        });
    }

    // Export for use
    window.SFPreviewPolling = {
        start: startPolling,
        stop: stopPolling,
        init: initPagePolling
    };

    // Auto-init on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPagePolling);
    } else {
        initPagePolling();
    }
})();