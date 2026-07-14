(function () {
    'use strict';

    function getBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function showToast(message, type) {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type || 'info', message);
            return;
        }

        const toast = document.createElement('div');
        toast.className = `sf-toast sf-toast-${type || 'info'} visible`;
        toast.innerHTML = `<div class="sf-toast-content">${message}</div>`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    function showProgressToast() {
        let toast = document.getElementById('sfProgressToast');
        if (toast) toast.remove();

        toast = document.createElement('div');
        toast.className = 'sf-toast sf-toast-info visible';
        toast.id = 'sfProgressToast';
        toast.innerHTML = `
            <div class="sf-toast-content">
                ${window.SF_I18N?.processing_flash || 'Processing...'}
                <div class="sf-progress-bar">
                    <span id="sfProgressValue" style="width: 0%;"></span>
                </div>
                <span id="sfProgressText">0%</span>
            </div>
        `;
        document.body.appendChild(toast);
        return toast;
    }

    function updateProgress(progress) {
        const progressBar = document.getElementById('sfProgressValue');
        const progressText = document.getElementById('sfProgressText');
        if (progressBar) progressBar.style.width = `${progress}%`;
        if (progressText) progressText.textContent = `${progress}%`;
    }

    function markToastSuccess() {
        const toast = document.getElementById('sfProgressToast');
        if (!toast) return;
        const content = toast.querySelector('.sf-toast-content');
        if (content) content.innerHTML = window.SF_I18N?.processing_complete || 'Complete!';
        toast.classList.remove('sf-toast-info');
        toast.classList.add('sf-toast-success');
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 2000);
        }, 2000);
    }

    function markToastError() {
        const toast = document.getElementById('sfProgressToast');
        if (!toast) return;
        toast.className = 'sf-toast sf-toast-danger visible';
        const content = toast.querySelector('.sf-toast-content');
        if (content) content.innerHTML = window.SF_I18N?.processing_failed || 'Failed.';
    }

    function trackProcessStatus(flashId) {
        const baseUrl = getBaseUrl();
        // Use the correct API endpoint that checks is_processing field
        const url = `${baseUrl}/app/api/check_processing_status.php?flash_id=${encodeURIComponent(flashId)}`;

        const intervalId = setInterval(async () => {
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(`Server responded with status: ${response.status}`);
                }

                const data = await response.json();
                // Use is_processing field instead of status
                const isProcessing = data.is_processing === true;
                const progress = Number(data.progress || 0);

                updateProgress(progress);

                // Processing is complete when is_processing = false
                if (!isProcessing || progress >= 100) {
                    clearInterval(intervalId);
                    updateProgress(100);
                    markToastSuccess();

                    // Muilla sivuilla kuin list, ladataan sivu uudelleen näyttämään valmis flash
                    // List-sivulla inline-scripti hoitaa kortin näyttämisen ilman reloadausta
                    if (document.body && document.body.dataset && document.body.dataset.page !== 'list') {
                        setTimeout(() => window.location.reload(), 600);
                    }
                }
            } catch (err) {
                console.error('Error tracking process status:', err);
                clearInterval(intervalId);
                markToastError();
                showToast('Prosessoinnin seuranta epäonnistui.', 'danger');
            }
        }, 3000);
    }

    function removeBgProcessParam() {
        const params = new URLSearchParams(window.location.search);
        params.delete('bg_process');
        const query = params.toString();
        const newUrl = window.location.pathname + (query ? `?${query}` : '') + window.location.hash;
        window.history.replaceState({}, '', newUrl);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const params = new URLSearchParams(window.location.search);
        const flashId = params.get('bg_process');
        if (!flashId) return;

        // HUOM: list.php:ssä on oma inline-scripti joka käsittelee bg_process parametrin
        // ja kortin näyttämisen animaatiolla. Tämä scripti näyttää vain toast-ilmoituksen.
        // EI poisteta bg_process parametria heti, vaan annetaan list.php:n scriptin hoitaa se.
        const isListPage = document.body && document.body.dataset && document.body.dataset.page === 'list';

        if (!isListPage) {
            // Muilla sivuilla näytetään progress toast ja seurataan statusta
            showProgressToast();
            trackProcessStatus(flashId);
            removeBgProcessParam();
        }
        // List-sivulla ei tehdä mitään - list.php:n inline-scripti hoitaa kaiken
    });
})();