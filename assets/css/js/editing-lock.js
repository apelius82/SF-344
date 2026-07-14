(function () {
    'use strict';

    const flashId = window.SF_FLASH_ID || null;
    const baseUrl = window.SF_BASE_URL || '';
    const HEARTBEAT_INTERVAL = 30000; // 30 seconds
    let heartbeatInterval = null;
    let lockAcquired = false;
    let releaseInProgress = false;

    if (!flashId) return; // Not editing an existing flash

    function acquireLock() {
        fetch(baseUrl + '/app/api/editing_lock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=acquire&flash_id=' + flashId,
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    lockAcquired = true;
                    startHeartbeat();
                }
            })
            .catch(err => console.warn('Failed to acquire editing lock:', err));
    }

    function releaseLock() {
        if (!lockAcquired || releaseInProgress) return;

        releaseInProgress = true;

        // Use sendBeacon for reliability when page is closing
        const data = new FormData();
        data.append('action', 'release');
        data.append('flash_id', flashId);

        navigator.sendBeacon(baseUrl + '/app/api/editing_lock.php', data);
        lockAcquired = false;
    }

    function startHeartbeat() {
        // Send heartbeat every 30 seconds
        heartbeatInterval = setInterval(function () {
            fetch(baseUrl + '/app/api/editing_lock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=heartbeat&flash_id=' + flashId,
                credentials: 'same-origin'
            }).catch(() => { });
        }, HEARTBEAT_INTERVAL);
    }

    function stopHeartbeat() {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
    }

    // Acquire lock when form loads (if warning was dismissed)
    window.continueEditing = function () {
        const banner = document.getElementById('editingWarningBanner');
        if (banner) banner.remove();
        acquireLock();
    };

    window.cancelEditing = function () {
        window.history.back();
    };

    // If no warning shown, acquire lock immediately
    if (!document.getElementById('editingWarningBanner')) {
        acquireLock();
    }

    // Release lock when leaving page
    window.addEventListener('beforeunload', releaseLock);
    window.addEventListener('pagehide', releaseLock);

    // Also release on form submit success (handled separately in save logic)
})();