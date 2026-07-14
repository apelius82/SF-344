// assets/js/pwa-install.js

(function () {
    'use strict';

    let deferredPrompt = null;
    let isIOS = false;
    let isInitialized = false;

    const OLD_PROMPT_DISMISSED_KEY = 'sf_pwa_push_prompt_dismissed';
    const PROMPT_DISMISSED_UNTIL_KEY = 'sf_pwa_push_prompt_dismissed_until';
    const PROMPT_DISMISS_DAYS = 30;

    function detectIOS() {
        const ua = window.navigator.userAgent;
        return /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    }

    function clearOldDismissState() {
        try {
            window.localStorage.removeItem(OLD_PROMPT_DISMISSED_KEY);
        } catch (error) {}
    }

    function isPromptDismissedTemporarily() {
        try {
            const dismissedUntil = parseInt(window.localStorage.getItem(PROMPT_DISMISSED_UNTIL_KEY) || '0', 10);

            if (!dismissedUntil) {
                return false;
            }

            if (Date.now() > dismissedUntil) {
                window.localStorage.removeItem(PROMPT_DISMISSED_UNTIL_KEY);
                return false;
            }

            return true;
        } catch (error) {
            return false;
        }
    }

    function dismissPromptTemporarily() {
        try {
            const dismissedUntil = Date.now() + PROMPT_DISMISS_DAYS * 24 * 60 * 60 * 1000;
            window.localStorage.setItem(PROMPT_DISMISSED_UNTIL_KEY, String(dismissedUntil));
        } catch (error) {}
    }

    function showInstallButton() {
        const installBtn = document.getElementById('sf-install-btn');

        if (installBtn) {
            installBtn.classList.remove('hidden');
        }
    }

    function hideInstallButton() {
        const installBtn = document.getElementById('sf-install-btn');

        if (installBtn) {
            installBtn.classList.add('hidden');
        }
    }

    function showPrompt() {
        const prompt = document.getElementById('sfPwaInstallPrompt');

        if (!prompt || isPromptDismissedTemporarily()) {
            return;
        }

        prompt.classList.remove('hidden');
    }

    function hidePrompt() {
        const prompt = document.getElementById('sfPwaInstallPrompt');

        if (!prompt) {
            return;
        }

        prompt.classList.add('hidden');
    }

    async function shouldShowPromptBecausePushIsMissing() {
        if (!window.SafetyFlashPush) {
            return true;
        }

        if (!window.SafetyFlashPush.supported) {
            return true;
        }

        if (typeof window.SafetyFlashPush.isPermissionDenied === 'function' && window.SafetyFlashPush.isPermissionDenied()) {
            return true;
        }

        try {
            const status = await window.SafetyFlashPush.getStatus();

            if (!status || !status.ok) {
                return true;
            }

            return status.has_active_subscription !== true;
        } catch (error) {
            return true;
        }
    }

    async function refreshPromptVisibility() {
        clearOldDismissState();

        if (isPromptDismissedTemporarily()) {
            hidePrompt();
            return;
        }

        const shouldShow = await shouldShowPromptBecausePushIsMissing();

        if (shouldShow) {
            showPrompt();
        } else {
            hidePrompt();
        }
    }

    function closeInstallModal() {
        const installModal = document.getElementById('sfInstallModal');
        const installContent = installModal ? installModal.querySelector('.sf-modal-content') : null;

        if (!installModal) {
            return;
        }

        if (installContent) {
            installContent.style.transition = '';
            installContent.style.transform = '';
        }

        installModal.classList.add('hidden');
        installModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sf-modal-open');
    }

    function openInstallModal() {
        const installModal = document.getElementById('sfInstallModal');
        const installMessage = document.getElementById('sfInstallMessage');
        const installMessageIOS = document.getElementById('sfInstallMessageIOS');
        const installConfirmBtn = document.getElementById('sfInstallConfirm');

        if (!installModal) {
            return;
        }

        if (isIOS) {
            if (installMessage) {
                installMessage.style.display = 'none';
            }

            if (installMessageIOS) {
                installMessageIOS.style.display = 'block';
            }

            if (installConfirmBtn) {
                installConfirmBtn.style.display = 'none';
            }
        } else {
            if (installMessage) {
                installMessage.style.display = 'block';
            }

            if (installMessageIOS) {
                installMessageIOS.style.display = 'none';
            }

            if (installConfirmBtn) {
                installConfirmBtn.style.display = 'inline-flex';
            }
        }

        installModal.classList.remove('hidden');
        installModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sf-modal-open');
    }

    function handleInstallClick() {
        if (!deferredPrompt) {
            openInstallModal();
            return;
        }

        closeInstallModal();

        deferredPrompt.prompt();

        deferredPrompt.userChoice.then(function (choiceResult) {
            if (choiceResult.outcome === 'accepted') {
                hideInstallButton();
            }

            deferredPrompt = null;
        });
    }

    function bindInstallModalClose(installModal) {
        if (!installModal) {
            return;
        }

        const closeButtons = installModal.querySelectorAll('[data-modal-close], [data-close-install-modal], .sf-install-modal-close, .sf-modal-close, .sf-modal-close-btn');

        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeInstallModal);
        });
    }

    function bindInstallModalSwipe(installModal) {
        if (!installModal) {
            return;
        }

        const installContent = installModal.querySelector('.sf-modal-content');

        if (!installContent) {
            return;
        }

        const dragZones = installModal.querySelectorAll('[data-install-modal-drag-handle], [data-install-modal-drag-zone]');

        let touchStartY = 0;
        let touchCurrentY = 0;
        let isDragging = false;

        function startDrag(event) {
            if (!event.touches || event.touches.length !== 1) {
                return;
            }

            isDragging = true;
            touchStartY = event.touches[0].clientY;
            touchCurrentY = touchStartY;
            installContent.style.transition = 'none';
        }

        function moveDrag(event) {
            if (!isDragging || !event.touches || event.touches.length !== 1) {
                return;
            }

            touchCurrentY = event.touches[0].clientY;

            const distance = Math.max(0, touchCurrentY - touchStartY);

            if (distance > 0) {
                installContent.style.transform = 'translateY(' + distance + 'px)';
            }
        }

        function endDrag() {
            if (!isDragging) {
                return;
            }

            const distance = Math.max(0, touchCurrentY - touchStartY);

            isDragging = false;
            installContent.style.transition = 'transform 180ms ease';

            if (distance > 80) {
                installContent.style.transform = 'translateY(100%)';

                window.setTimeout(function () {
                    closeInstallModal();
                }, 160);
            } else {
                installContent.style.transform = 'translateY(0)';
            }

            touchStartY = 0;
            touchCurrentY = 0;
        }

        dragZones.forEach(function (zone) {
            zone.addEventListener('touchstart', startDrag, { passive: true });
            zone.addEventListener('touchmove', moveDrag, { passive: true });
            zone.addEventListener('touchend', endDrag);
            zone.addEventListener('touchcancel', endDrag);
        });
    }

    function init() {
        if (isInitialized) {
            refreshPromptVisibility().catch(function () {});
            return;
        }

        isInitialized = true;
        isIOS = detectIOS();

        const installBtn = document.getElementById('sf-install-btn');
        const installModal = document.getElementById('sfInstallModal');
        const installConfirmBtn = document.getElementById('sfInstallConfirm');
        const promptOpenBtn = document.getElementById('sfPwaInstallPromptOpen');
        const promptDismissBtn = document.getElementById('sfPwaInstallPromptDismiss');

        if (installModal && installModal.parentElement !== document.body) {
            document.body.appendChild(installModal);
        }

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferredPrompt = event;
            showInstallButton();
        });

        if (installBtn) {
            installBtn.addEventListener('click', openInstallModal);
        }

        if (installConfirmBtn) {
            installConfirmBtn.addEventListener('click', handleInstallClick);
        }

        bindInstallModalClose(installModal);
        bindInstallModalSwipe(installModal);

        if (promptOpenBtn) {
            promptOpenBtn.addEventListener('click', openInstallModal);
        }

        if (promptDismissBtn) {
            promptDismissBtn.addEventListener('click', function () {
                dismissPromptTemporarily();
                hidePrompt();
            });
        }

        window.addEventListener('sf:push-subscribed', function () {
            hidePrompt();
        });

        window.addEventListener('sf:push-unsubscribed', function () {
            refreshPromptVisibility().catch(function () {});
        });

        refreshPromptVisibility().catch(function () {});

        window.setTimeout(function () {
            refreshPromptVisibility().catch(function () {});
        }, 800);

        window.setTimeout(function () {
            refreshPromptVisibility().catch(function () {});
        }, 2000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('sf:page-loaded', init);
})();