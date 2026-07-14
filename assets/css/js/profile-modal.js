
// assets/js/profile-modal.js
(function () {
    "use strict";

    const base = window.SF_BASE_URL || '';
    const MOBILE_BREAKPOINT = 768;
    const KEYBOARD_SCROLL_DELAY = 120; // allow mobile virtual keyboard/layout resize before centering field
    let deferredInstallPrompt = null;
	let notificationsInitialSnapshot = '';
    let notificationsHasUnsavedChanges = false;
    function showToast(message, type = 'success') {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
        }
    }

    function setPasswordFeedback(message, type) {
        const feedback = document.getElementById('sfPasswordModalFeedback');
        if (!feedback) {
            showToast(message, type);
            return;
        }

        feedback.textContent = message || '';
        feedback.style.display = message ? 'block' : 'none';
        feedback.style.color = type === 'success' ? '#059669' : '#dc2626';
    }

    function clearPasswordFeedback() {
        setPasswordFeedback('', 'success');
    }

	    function setPushStatusText(message, type) {
        const statusText = document.getElementById('sfPushStatusText');
        const installPrompt = document.getElementById('sfPushInstallPrompt');

        if (!statusText) {
            return;
        }

        statusText.textContent = message || '';
        statusText.dataset.state = type || 'neutral';

        if (installPrompt) {
            const shouldShowPrompt = type === 'neutral' || type === 'warning';
            installPrompt.hidden = !shouldShowPrompt;
        }
    }

    function isIosDevice() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function updateInstallAppButtonState() {
        const installButton = document.getElementById('sfInstallAppButton');
        const installHint = document.getElementById('sfInstallAppHint');

        if (!installButton) {
            return;
        }

        if (deferredInstallPrompt) {
            installButton.hidden = false;
            installButton.disabled = false;

            if (installHint) {
                installHint.hidden = true;
            }

            return;
        }

        if (isIosDevice()) {
            installButton.hidden = false;
            installButton.disabled = false;

            if (installHint) {
                installHint.hidden = false;
            }

            return;
        }

        installButton.hidden = false;
        installButton.disabled = false;

        if (installHint) {
            installHint.hidden = true;
        }
    }

    async function handleInstallAppButtonClick() {
        const installHint = document.getElementById('sfInstallAppHint');

        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();

            const choiceResult = await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;

            if (choiceResult && choiceResult.outcome === 'accepted') {
                showToast(window.SF_I18N?.pushInstallAccepted || 'Sovelluksen asennus käynnistetty.', 'success');
            }

            updateInstallAppButtonState();
            return;
        }

        if (installHint) {
            installHint.hidden = false;
        }

        showToast(
            window.SF_I18N?.pushInstallManualHint || 'Asenna sovellus selaimen valikosta lisäämällä se aloitusnäyttöön.',
            'success'
        );
    }

    function getPushPermissionHelpMessage() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isAndroid = /Android/i.test(navigator.userAgent);

        if (isIOS) {
            return window.SF_I18N?.pushPermissionDeniedIos ||
                'Ilmoitukset on estetty. Avaa iPhonen Asetukset → Ilmoitukset → SafetyFlash ja salli ilmoitukset.';
        }

        if (isAndroid) {
            return window.SF_I18N?.pushPermissionDeniedAndroid ||
                'Ilmoitukset on estetty selaimen asetuksissa. Avaa Chromen sivustoasetukset → Ilmoitukset → safetyflash.tapojarvi.online → Salli.';
        }

        return window.SF_I18N?.pushPermissionDeniedGeneric ||
            'Ilmoitukset on estetty selaimen asetuksissa. Salli ilmoitukset sivustolle ja yritä uudelleen.';
    }

    function setPushPreferenceControlsEnabled(isEnabled) {
        const pushPreferenceInputs = document.querySelectorAll('[data-push-category]');
        const matrix = document.querySelector('.sf-notification-matrix');

        pushPreferenceInputs.forEach(function (input) {
            input.disabled = !isEnabled;

            const row = input.closest('.sf-notification-matrix-row');
            if (row) {
                row.classList.toggle('sf-push-preference-disabled', !isEnabled);
            }
        });

        document.querySelectorAll('[data-notif-bulk-channel="push"]').forEach(function (button) {
            button.disabled = !isEnabled;
        });

        if (matrix) {
            matrix.classList.toggle('sf-push-master-disabled', !isEnabled);
        }
    }

    function getNotificationsForm() {
        return document.getElementById('sfProfileNotificationsForm');
    }

    function getNotificationsFloatingSaveButton() {
        return document.getElementById('sfNotificationsFloatingSave');
    }

    function isNotificationsTabActive() {
        const notificationsTab = document.querySelector('#modalProfile .sf-profile-tab[data-tab="notifications"]');
        return Boolean(notificationsTab && notificationsTab.classList.contains('active'));
    }

    function getNotificationsSnapshot() {
        const form = getNotificationsForm();

        if (!form) {
            return '';
        }

        const values = [];

        form.querySelectorAll('input[type="checkbox"][data-notif-channel="email"][data-notif-category], input[type="checkbox"][data-push-category]').forEach(function (input) {
            const key = input.getAttribute('data-notif-category') || input.getAttribute('data-push-category') || input.name || input.id;
            values.push(key + ':' + (input.checked ? '1' : '0'));
        });

        values.sort();

        return values.join('|');
    }

    function refreshNotificationsFloatingSaveButton() {
        const button = getNotificationsFloatingSaveButton();

        if (!button) {
            return;
        }

        const shouldShow = notificationsHasUnsavedChanges &&
            isNotificationsTabActive() &&
            window.matchMedia('(max-width: 768px)').matches;

        button.hidden = !shouldShow;
        button.classList.toggle('is-visible', shouldShow);
        button.classList.remove('is-saved');
        button.disabled = false;

        const text = button.querySelector('.sf-notifications-floating-save-text');
        if (text) {
            text.textContent = window.SF_I18N?.save || 'Tallenna';
        }
    }

    function resetNotificationsDirtyState() {
        notificationsInitialSnapshot = getNotificationsSnapshot();
        notificationsHasUnsavedChanges = false;
        refreshNotificationsFloatingSaveButton();
    }

    function updateNotificationsDirtyState() {
        const currentSnapshot = getNotificationsSnapshot();
        notificationsHasUnsavedChanges = currentSnapshot !== notificationsInitialSnapshot;
        refreshNotificationsFloatingSaveButton();
    }

    function markNotificationsSavedState() {
        const button = getNotificationsFloatingSaveButton();

        notificationsInitialSnapshot = getNotificationsSnapshot();
        notificationsHasUnsavedChanges = false;

        if (!button) {
            return;
        }

        button.hidden = false;
        button.classList.add('is-visible');
        button.classList.add('is-saved');
        button.disabled = true;

        const text = button.querySelector('.sf-notifications-floating-save-text');
        if (text) {
            text.textContent = window.SF_I18N?.saved || 'Tallennettu';
        }

        window.setTimeout(function () {
            button.hidden = true;
            button.classList.remove('is-visible');
            button.classList.remove('is-saved');
            button.disabled = false;

            if (text) {
                text.textContent = window.SF_I18N?.save || 'Tallenna';
            }
        }, 900);
    }
	
    async function updatePushNotificationUi() {
        const toggle = document.getElementById('sfPushNotificationsToggle');
        const unsupportedNotice = document.getElementById('sfPushUnsupportedNotice');

        if (!toggle) {
            return;
        }

        if (!window.SafetyFlashPush || window.SafetyFlashPush.supported !== true) {
            toggle.checked = false;
            toggle.disabled = true;
            setPushPreferenceControlsEnabled(false);

            if (unsupportedNotice) {
                unsupportedNotice.hidden = false;
            }

            setPushStatusText(
                window.SF_I18N?.pushNotSupported || 'Push-ilmoitukset eivät ole käytettävissä tällä laitteella.',
                'warning'
            );
            return;
        }

        if (unsupportedNotice) {
            unsupportedNotice.hidden = true;
        }

        toggle.disabled = true;
        setPushStatusText(
            window.SF_I18N?.pushStatusChecking || 'Tarkistetaan push-ilmoitusten tila...',
            'neutral'
        );

        try {
            if (window.SafetyFlashPush.isPermissionDenied && window.SafetyFlashPush.isPermissionDenied()) {
                toggle.checked = false;
                toggle.disabled = false;
                setPushPreferenceControlsEnabled(false);
                setPushStatusText(getPushPermissionHelpMessage(), 'warning');
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const browserSubscription = await registration.pushManager.getSubscription();
            const status = await window.SafetyFlashPush.getStatus();

            const isEnabled = Boolean(browserSubscription && status.ok && status.has_active_subscription);

            toggle.checked = isEnabled;
            toggle.disabled = false;
            setPushPreferenceControlsEnabled(isEnabled);

            if (isEnabled) {
                setPushStatusText(
                    window.SF_I18N?.pushStatusEnabled || 'Push-ilmoitukset ovat käytössä tällä laitteella.',
                    'success'
                );
            } else {
                setPushStatusText(
                    window.SF_I18N?.pushStatusDisabled || 'Push-ilmoitukset eivät ole käytössä tällä laitteella.',
                    'neutral'
                );
            }
        } catch (error) {
            console.error('Push status check failed:', error);
            toggle.checked = false;
            toggle.disabled = false;
            setPushPreferenceControlsEnabled(false);
            setPushStatusText(
                window.SF_I18N?.pushStatusError || 'Push-ilmoitusten tilaa ei voitu tarkistaa.',
                'error'
            );
        }
    }

    async function handlePushNotificationToggleChange(toggle) {
        if (!toggle || !window.SafetyFlashPush || window.SafetyFlashPush.supported !== true) {
            return;
        }

        const shouldEnable = toggle.checked;
        toggle.disabled = true;

        setPushStatusText(
            shouldEnable
                ? (window.SF_I18N?.pushStatusEnabling || 'Otetaan push-ilmoitukset käyttöön...')
                : (window.SF_I18N?.pushStatusDisabling || 'Poistetaan push-ilmoitukset käytöstä...'),
            'neutral'
        );

        try {
            const result = shouldEnable
                ? await window.SafetyFlashPush.requestPermissionAndSubscribe()
                : await window.SafetyFlashPush.unsubscribe();

            if (!result || result.ok !== true) {
                toggle.checked = !shouldEnable;

                const message = result?.permissionDenied === true
                    ? getPushPermissionHelpMessage()
                    : (result?.error || window.SF_I18N?.pushStatusError || 'Push-ilmoituksen päivitys epäonnistui.');

                setPushStatusText(message, result?.permissionDenied === true ? 'warning' : 'error');

                if (result?.permissionDenied === true) {
                    showToast(
                        window.SF_I18N?.pushPermissionDeniedToast || 'Ilmoitukset on estetty selaimen asetuksissa.',
                        'error'
                    );
                }

                return;
            }

            setPushPreferenceControlsEnabled(shouldEnable);

            setPushStatusText(
                shouldEnable
                    ? (window.SF_I18N?.pushStatusEnabled || 'Push-ilmoitukset ovat käytössä tällä laitteella.')
                    : (window.SF_I18N?.pushStatusDisabled || 'Push-ilmoitukset eivät ole käytössä tällä laitteella.'),
                shouldEnable ? 'success' : 'neutral'
            );

            showToast(
                shouldEnable
                    ? (window.SF_I18N?.pushEnabledToast || 'Push-ilmoitukset otettu käyttöön.')
                    : (window.SF_I18N?.pushDisabledToast || 'Push-ilmoitukset poistettu käytöstä.'),
                'success'
            );
        } catch (error) {
            console.error('Push toggle update failed:', error);
            toggle.checked = !shouldEnable;
            setPushStatusText(
                window.SF_I18N?.pushStatusError || 'Push-ilmoituksen päivitys epäonnistui.',
                'error'
            );
        } finally {
            toggle.disabled = false;
        }
    }

    function clearProfileUiLocks() {
        document.body.classList.remove('sf-modal-open');
        document.body.classList.remove('sf-loading');
        document.body.removeAttribute('aria-busy');
    }

    function restoreModalStateIfNeeded() {
        const visibleModal = document.querySelector('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)');
        if (visibleModal) {
            document.body.classList.add('sf-modal-open');
        }
    }

    function openProfileModalElement(modal) {
        if (!modal) return;

        if (typeof window.sfOpenModal === 'function') {
            window.sfOpenModal(modal);
            return;
        }

        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
    }

    function closeProfileModalElement(modal) {
        if (!modal) return;

        if (typeof window.sfCloseModal === 'function') {
            window.sfCloseModal(modal);

            window.setTimeout(function () {
                if (modal.classList.contains('hidden')) {
                    clearProfileUiLocks();
                    restoreModalStateIfNeeded();
                }
            }, 360);

            return;
        }

        modal.classList.add('hidden');

        clearProfileUiLocks();
        restoreModalStateIfNeeded();
    }

    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.sf-profile-tab');
        if (!tab) return;

        var tabName = tab.dataset.tab;
        var modal = tab.closest('.sf-modal');
        if (!modal) return;

        modal.querySelectorAll('.sf-profile-tab').forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        modal.querySelectorAll('.sf-profile-tab-content').forEach(function (c) {
            c.classList.remove('active');
        });

        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        var targetContent = modal.querySelector('[data-tab-content="' + tabName + '"]');
        if (targetContent) {
            targetContent.classList.add('active');
        }

        if (tabName === 'password') {
            clearPasswordFeedback();
        }

        refreshNotificationsFloatingSaveButton();
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredInstallPrompt = event;
        updateInstallAppButtonState();
    });

    window.addEventListener('appinstalled', function () {
        deferredInstallPrompt = null;
        showToast(window.SF_I18N?.pushInstallAccepted || 'Sovellus asennettu.', 'success');
        updateInstallAppButtonState();
    });

    document.addEventListener('click', function (event) {
        const installButton = event.target.closest('#sfInstallAppButton');

        if (!installButton) {
            return;
        }

        event.preventDefault();
        handleInstallAppButtonClick();
    });	
	
    document.addEventListener('click', function (e) {
        const opener = e.target.closest('[data-modal-open="modalProfile"]');
        if (!opener) return;

        e.preventDefault();
        e.stopPropagation();

        const profileTab = opener.dataset.profileTab;
        openProfileModal(profileTab);
    });

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }

        const closeButton = e.target.closest(
            '#modalProfile [data-modal-close], ' +
            '#modalProfile .sf-modal-close, ' +
            '#modalProfile .modal-close, ' +
            '#modalProfile .sf-close, ' +
            '#modalProfile .btn-close, ' +
            '#modalProfile [aria-label="Close"], ' +
            '#modalProfile [aria-label="Sulje"]'
        );

        if (closeButton) {
            e.preventDefault();
            e.stopPropagation();
            closeProfileModalElement(modal);
            return;
        }

        if (e.target === modal) {
            e.preventDefault();
            e.stopPropagation();
            closeProfileModalElement(modal);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }

        closeProfileModalElement(modal);
    });

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) return;

        const logoutOpener = e.target.closest('[data-modal-open="#sfLogoutModal"]');
        if (!logoutOpener) return;

        closeProfileModalElement(modal);
        // Allow event to propagate so modals.js can open the logout modal
    }, true);

    async function openProfileModal(tabToOpen) {
        const modal = document.getElementById('modalProfile');
        if (!modal) return;

        document.body.classList.add('sf-loading');
        document.body.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(base + '/app/api/profile_get.php', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.ok && data.user) {
                document.getElementById('modalProfileFirst').value = data.user.first_name || '';
                document.getElementById('modalProfileLast').value = data.user.last_name || '';
                document.getElementById('modalProfileEmail').value = data.user.email || '';
                document.getElementById('modalProfileRole').textContent = data.user.role_name || '-';

                const worksiteSelect = document.getElementById('modalProfileWorksite');
                if (worksiteSelect && data.worksites) {
                    const firstOption = worksiteSelect.options[0];
                    worksiteSelect.innerHTML = '';
                    worksiteSelect.appendChild(firstOption);

                    data.worksites.forEach(function (ws) {
                        const option = document.createElement('option');
                        option.value = ws.id;
                        option.textContent = ws.name;
                        if (parseInt(ws.id, 10) === parseInt(data.user.home_worksite_id || 0, 10)) {
                            option.selected = true;
                        }
                        worksiteSelect.appendChild(option);
                    });
                }

                // Load per-category email notification preferences
                if (data.notification_preferences) {
                    Object.keys(data.notification_preferences).forEach(function (category) {
                        const checkbox = document.querySelector('[data-notif-channel="email"][data-notif-category="' + category + '"]');
                        if (checkbox) {
                            checkbox.checked = data.notification_preferences[category] === true;
                        }
                    });
                }

                // Load per-category push notification preferences
                document.querySelectorAll('[data-push-category]').forEach(function (checkbox) {
                    checkbox.checked = false;
                });

                if (data.push_preferences) {
                    Object.keys(data.push_preferences).forEach(function (category) {
                        const checkbox = document.querySelector('[data-push-category="' + category + '"]');
                        if (checkbox) {
                            checkbox.checked = data.push_preferences[category] === true;
                        }
                    });
                }

                setPushPreferenceControlsEnabled(false);
                updatePushNotificationUi();
                updateInstallAppButtonState();

                window.setTimeout(function () {
                    resetNotificationsDirtyState();
                }, 150);
            }
        } catch (err) {
            console.error('Error loading profile:', err);
        } finally {
            document.body.classList.remove('sf-loading');
            document.body.removeAttribute('aria-busy');
        }

        modal.querySelectorAll('.sf-profile-tab').forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        modal.querySelectorAll('.sf-profile-tab-content').forEach(function (c) {
            c.classList.remove('active');
        });

        const targetTab = tabToOpen || 'basics';
        const validTabs = ['basics', 'settings', 'notifications', 'password'];
        const safeTab = validTabs.includes(targetTab) ? targetTab : 'basics';
        const tabButton = modal.querySelector('[data-tab="' + safeTab + '"]');
        const tabContent = modal.querySelector('[data-tab-content="' + safeTab + '"]');

        if (tabButton) tabButton.classList.add('active');
        if (tabButton) tabButton.setAttribute('aria-selected', 'true');
        if (tabContent) tabContent.classList.add('active');

        clearPasswordFeedback();

        const passwordFormElement = document.getElementById('sfPasswordModalForm');
        if (passwordFormElement) {
            passwordFormElement.reset();
        }

        openProfileModalElement(modal);
    }

    async function submitProfileModalForm(form, options) {
    const submitButton = options && options.submitter
        ? options.submitter
        : form.querySelector('button[type="submit"]');
    const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
    const formData = new FormData(form);

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('sf-btn-loading');
        submitButton.innerHTML = '<span class="sf-btn-spinner" aria-hidden="true"></span>' + (window.SF_I18N?.saving_draft || 'Tallennetaan...');
    }

    try {
        const response = await fetch(base + '/app/api/profile_update.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const result = await response.json();

        if (response.ok && result.ok === true) {
    const successMessage = result.message || window.SF_I18N?.profileUpdated || window.SF_I18N?.saved || 'Tallennettu';

    if (submitButton) {
        submitButton.innerHTML = '✓ ' + successMessage;
    }

    window.setTimeout(function () {
        if (options.closeModal === true) {
            const modal = document.getElementById('modalProfile');

            if (modal) {
                closeProfileModalElement(modal);
            }

            window.setTimeout(function () {
                showToast(successMessage, 'success');
            }, 180);

            return;
        }

        if (form.id === 'sfProfileNotificationsForm') {
            markNotificationsSavedState();
        }

        // Ilmoitukset-välilehti pysyy auki, joten globaalia toastia ei näytetä.
        // Muuten toast jää modalin blur-taustan alle.
    }, 450);

    return;
}

        showToast(result.error || (window.SF_I18N?.error || 'Virhe tallennuksessa'), 'error');
    } catch (err) {
        console.error('Profile update error:', err);
        showToast(window.SF_I18N?.error || 'Virhe tallennuksessa', 'error');
    } finally {
        window.setTimeout(function () {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.classList.remove('sf-btn-loading');
                submitButton.innerHTML = originalButtonHtml;
            }
        }, 650);
    }
}
    function setNotificationPreferenceChannel(channel, enabled) {
        let selector = '';

        if (channel === 'email') {
            selector = '[data-notif-channel="email"][data-notif-category]';
        }

        if (channel === 'push') {
            selector = '[data-push-category]:not(:disabled)';
        }

        if (!selector) {
            return;
        }

        document.querySelectorAll(selector).forEach(function (checkbox) {
            checkbox.checked = enabled;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-notif-bulk-channel][data-notif-bulk-value]');

        if (!button) {
            return;
        }

        event.preventDefault();

        if (button.disabled) {
            return;
        }

        const channel = button.dataset.notifBulkChannel;
        const enabled = button.dataset.notifBulkValue === '1';

        setNotificationPreferenceChannel(channel, enabled);
    });

    document.addEventListener('change', function (event) {
        const form = getNotificationsForm();

        if (!form || !form.contains(event.target)) {
            return;
        }

        updateNotificationsDirtyState();
    });

    window.addEventListener('resize', function () {
        refreshNotificationsFloatingSaveButton();
    });

    document.addEventListener('submit', function (e) {
        const form = e.target;

        if (!form || !form.matches('#sfProfileModalForm, #sfProfileSettingsForm, #sfProfileNotificationsForm')) {
            return;
        }

        e.preventDefault();

        if (form.id === 'sfProfileNotificationsForm') {
            submitProfileModalForm(form, {
                closeModal: false,
                submitter: e.submitter || null
            });
            return;
        }

        submitProfileModalForm(form, {
            closeModal: true,
            submitter: e.submitter || null
        });
    });

    const pushToggle = document.getElementById('sfPushNotificationsToggle');
    if (pushToggle) {
        pushToggle.addEventListener('change', function () {
            handlePushNotificationToggleChange(pushToggle);
        });
    }

    const passwordForm = document.getElementById('sfPasswordModalForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            clearPasswordFeedback();

            const newPass = document.getElementById('modalNewPassword').value;
            const confirmPass = document.getElementById('modalConfirmPassword').value;
            const submitButton = this.querySelector('button[type="submit"]');

            if (newPass !== confirmPass) {
                const message = window.SF_I18N?.passwordsMismatch || 'Salasanat eivät täsmää';
                setPasswordFeedback(message, 'error');
                showToast(message, 'error');
                return;
            }

            const formData = new FormData(this);

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const response = await fetch(base + '/app/api/profile_password.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const rawText = await response.text();
                let result = null;

                try {
                    result = JSON.parse(rawText);
                } catch (parseError) {
                    console.error('Password change JSON parse error:', parseError, rawText);
                    const message = 'Salasanan vaihto onnistui mahdollisesti, mutta palvelimen vastausta ei voitu lukea.';
                    setPasswordFeedback(message, 'error');
                    showToast(message, 'error');
                    return;
                }

                if (response.ok && result.ok) {
                    this.reset();

                    const successMessage = result.message || window.SF_I18N?.passwordChanged || 'Salasana vaihdettu onnistuneesti!';
                    setPasswordFeedback(successMessage, 'success');
                    showToast(successMessage, 'success');
                } else {
                    const errorMessage = result.error || window.SF_I18N?.error || 'Virhe salasanan vaihdossa';
                    setPasswordFeedback(errorMessage, 'error');
                    showToast(errorMessage, 'error');
                }
            } catch (err) {
                console.error('Password change error:', err);
                const message = window.SF_I18N?.error || 'Virhe salasanan vaihdossa';
                setPasswordFeedback(message, 'error');
                showToast(message, 'error');
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }

    document.addEventListener('focusin', function (e) {
        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden') || window.innerWidth > MOBILE_BREAKPOINT) {
            return;
        }
        const target = e.target;
        if (!target || !target.matches('#modalProfile input, #modalProfile select, #modalProfile textarea')) {
            return;
        }
        window.setTimeout(function () {
            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }, KEYBOARD_SCROLL_DELAY);
    });
})();