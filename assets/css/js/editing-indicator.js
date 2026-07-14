// Editing indicator - polls for active editing sessions
(function () {
    'use strict';

    const baseUrl = window.SF_BASE_URL || '';
    const DEFAULT_EDITING_TEXT = '{name} is editing...'; // Language-neutral fallback
    let pollingInterval = null;
    let isPolling = false;

    async function fetchEditingStatus() {
        try {
            const response = await fetch(baseUrl + '/app/api/editing_status.php');
            if (!response.ok) return null;
            return await response.json();
        } catch (e) {
            console.error('Editing status fetch error:', e);
            return null;
        }
    }

    function updateIndicators(data) {
        if (!data || !data.enabled) {
            // Feature disabled - hide all indicators
            document.querySelectorAll('.sf-editing-indicator').forEach(el => {
                el.style.display = 'none';
            });
            return;
        }

        // Create map of flash_id -> editor info
        const editorMap = {};
        (data.editors || []).forEach(e => {
            editorMap[e.flash_id] = e;
        });

        // Update each card
        document.querySelectorAll('.sf-editing-indicator').forEach(el => {
            const flashId = parseInt(el.dataset.flashId, 10);
            const editor = editorMap[flashId];

            if (editor) {
                const i18n = window.SF_LIST_I18N || {};
                const text = (i18n.editingIndicator || DEFAULT_EDITING_TEXT).replace('{name}', editor.editor_name);
                const textEl = el.querySelector('.sf-editing-text');
                if (textEl) {
                    textEl.textContent = text;
                }
                el.style.display = 'flex';
            } else {
                el.style.display = 'none';
            }
        });
    }

    async function poll() {
        const data = await fetchEditingStatus();
        updateIndicators(data);

        // Get interval from response or default
        const interval = (data && data.interval) || 30;

        if (isPolling) {
            pollingInterval = setTimeout(poll, interval * 1000);
        }
    }

    function startPolling() {
        if (isPolling) return;
        isPolling = true;
        poll();
    }

    function stopPolling() {
        isPolling = false;
        if (pollingInterval) {
            clearTimeout(pollingInterval);
            pollingInterval = null;
        }
    }

    // Start/stop based on page visibility
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    // Start polling on page load
    if (document.querySelector('.sf-editing-indicator')) {
        startPolling();
    }
})();