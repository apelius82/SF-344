(function () {
    'use strict';

    var config = window.SF_SESSION || {};
    var baseUrl = String(config.baseUrl || window.SF_BASE_URL || '').replace(/\/$/, '');
    var timeoutSeconds = Number(config.timeoutSeconds || 0);
    var warningSeconds = Number(config.warningSeconds || 300);

    if (!baseUrl || !timeoutSeconds || timeoutSeconds <= 0) {
        return;
    }

    if (warningSeconds >= timeoutSeconds) {
        warningSeconds = Math.max(0, timeoutSeconds - 15);
    }

    var lastUserActivity = Date.now();
    var lastKeepaliveSent = 0;
    var warningVisible = false;
    var loggedOut = false;

    var activityEvents = [
        'click',
        'keydown',
        'submit',
        'touchstart',
        'pointerdown',
        'scroll'
    ];

    function text(key, fallback) {
        return (window.SF_I18N && window.SF_I18N[key]) ? window.SF_I18N[key] : fallback;
    }

    function ensureModal() {
        var existing = document.getElementById('sfInactivityModal');
        if (existing) {
            return existing;
        }

        var modal = document.createElement('div');
        modal.id = 'sfInactivityModal';
        modal.className = 'sf-modal hidden sf-modal-small sf-modal-centered';
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        modal.innerHTML = ''
            + '<div class="sf-modal-content">'
            + '  <div class="sf-modal-header">'
            + '    <h3>' + escapeHtml(text('sessionInactiveTitle', 'Istunto vanhenee')) + '</h3>'
            + '  </div>'
            + '  <div class="sf-modal-body">'
            + '    <p id="sfInactivityText">' + escapeHtml(text('sessionInactiveText', 'Sinut kirjataan pian ulos toimimattomuuden takia.')) + '</p>'
            + '  </div>'
            + '  <div class="sf-modal-actions">'
            + '    <button type="button" class="sf-btn sf-btn-primary" id="sfContinueSessionBtn">'
            +          escapeHtml(text('sessionContinueButton', 'Jatka käyttöä'))
            + '    </button>'
            + '    <a class="sf-btn sf-btn-danger" href="' + escapeHtml(baseUrl + '/app/api/logout.php') + '">'
            +          escapeHtml(text('sessionLogoutButton', 'Kirjaudu ulos'))
            + '    </a>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(modal);

        var continueBtn = modal.querySelector('#sfContinueSessionBtn');
        if (continueBtn) {
            continueBtn.addEventListener('click', function () {
                registerActivity(true);
                hideWarning();
            });
        }

        return modal;
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function showWarning(remainingSeconds) {
        if (warningVisible || loggedOut) {
            return;
        }

        warningVisible = true;

        var modal = ensureModal();
        var textEl = modal.querySelector('#sfInactivityText');

        if (textEl) {
            textEl.textContent = text(
                'sessionInactiveText',
                'Sinut kirjataan pian ulos toimimattomuuden takia.'
            ) + ' ' + text(
                'sessionInactiveHint',
                'Jatka käyttöä painamalla painiketta tai liikkumalla sovelluksessa.'
            );
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sf-modal-open');
    }

    function hideWarning() {
        var modal = document.getElementById('sfInactivityModal');
        warningVisible = false;

        if (modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.body.classList.remove('sf-modal-open');
    }

    function logoutDueToInactivity() {
        if (loggedOut) {
            return;
        }

        loggedOut = true;
        window.location.href = baseUrl + '/app/api/logout.php?reason=inactivity';
    }

    function sendKeepalive(force) {
        var now = Date.now();

        if (!force && now - lastKeepaliveSent < 60000) {
            return;
        }

        lastKeepaliveSent = now;

        fetch(baseUrl + '/app/api/session_keepalive.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-SF-User-Activity': '1'
            }
        }).catch(function () {});
    }

    function registerActivity(forceKeepalive) {
        lastUserActivity = Date.now();

        if (warningVisible) {
            hideWarning();
        }

        sendKeepalive(!!forceKeepalive);
    }

    function onActivity() {
        registerActivity(false);
    }

    activityEvents.forEach(function (eventName) {
        window.addEventListener(eventName, onActivity, { passive: true, capture: true });
    });

    window.setInterval(function () {
        var inactiveSeconds = Math.floor((Date.now() - lastUserActivity) / 1000);
        var remainingSeconds = timeoutSeconds - inactiveSeconds;

        if (remainingSeconds <= 0) {
            logoutDueToInactivity();
            return;
        }

        if (remainingSeconds <= warningSeconds) {
            showWarning(remainingSeconds);
        }
    }, 15000);

    sendKeepalive(true);
})();