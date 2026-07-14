// assets/js/system-notice.js

(function () {
    'use strict';
function clearSystemNoticeSessionOnLogout() {
    document.addEventListener('click', function (event) {
        const logoutLink = event.target.closest('a[href*="logout"], button[data-logout], .logout-link, .sf-logout-link');

        if (!logoutLink) {
            return;
        }

        try {
            Object.keys(window.sessionStorage).forEach(function (key) {
                if (key.indexOf('sf_system_notice_closed_') === 0) {
                    window.sessionStorage.removeItem(key);
                }
            });
        } catch (error) {
            // Ignore storage errors.
        }
    });
}
    function initSystemNotice() {
        const shell = document.querySelector('.sf-system-notice-shell[data-system-notice-key]');
        if (!shell) {
            return;
        }

        const noticeKey = shell.getAttribute('data-system-notice-key');
        const storageKey = 'sf_system_notice_closed_' + noticeKey;

        try {
if (window.sessionStorage.getItem(storageKey) === '1') {
    shell.remove();
    return;
}
        } catch (error) {
            // localStorage can be unavailable in some private browser modes.
        }

        const closeButton = shell.querySelector('.sf-system-notice-close');
        if (!closeButton) {
            return;
        }

        closeButton.addEventListener('click', function () {
            try {
				window.sessionStorage.setItem(storageKey, '1');
            } catch (error) {
                // Ignore storage errors.
            }

            shell.style.opacity = '0';
            shell.style.transform = 'translateY(-8px)';
            shell.style.transition = 'opacity 180ms ease, transform 180ms ease';

            setTimeout(function () {
                shell.remove();
            }, 190);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSystemNotice);
    } else {
        initSystemNotice();
    }

document.addEventListener('sf:page-loaded', initSystemNotice);
clearSystemNoticeSessionOnLogout();
})();