/**
 * Safetyflash - Athena Reminder
 * Hallitsee Athena-muistutusmodalin laukaisua ja toimintoja.
 */
(function () {
    'use strict';

    // Alustetaan globaalit muuttujat view.php:stä
    var cfg = window.SF_ATHENA_CFG || {};
    var flashId      = cfg.flashId      || 0;
    var logFlashId   = cfg.logFlashId   || 0;
    var baseUrl      = cfg.baseUrl      || '';
    var csrfToken    = cfg.csrfToken    || window.SF_CSRF_TOKEN || '';
    var reportUrl    = cfg.reportUrl    || '';

    // localStorage-avain "muistuta myöhemmin" -merkinnälle
    var SNOOZE_KEY = 'sf_athena_snooze_' + logFlashId;

    function openAthenaModal() {
        var modal = document.getElementById('sfAthenaReminderModal');
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        var focusable = modal.querySelector('button, [href], input, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus();
    }

    window.sfAthenaCloseModal = function () {
        var modal = document.getElementById('sfAthenaReminderModal');
        if (!modal) return;
        modal.classList.add('hidden');
        var anyOpen = document.querySelector('.sf-modal:not(.hidden)');
        if (!anyOpen) {
            document.body.classList.remove('sf-modal-open');
        }
    };

    window.sfAthenaRemindLater = function () {
        try {
            localStorage.setItem(SNOOZE_KEY, '1');
        } catch (e) { /* localStorage ei käytettävissä */ }
        window.sfAthenaCloseModal();
    };

    window.sfAthenaMarkDone = function () {
        var btn = document.getElementById('sfAthenaBtnAlreadyDone');
        if (btn) { btn.disabled = true; }

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('flash_id', flashId);

        fetch(baseUrl + '/app/actions/mark_athena_exported.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok) {
                window.sfAthenaCloseModal();
                sfAthenaBadgeSetExported(data.exported_at, data.user_name);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', cfg.i18n && cfg.i18n.marked_done ? cfg.i18n.marked_done : 'Merkitty viedyksi');
                }
            }
        })
        .catch(function () {
            if (btn) { btn.disabled = false; }
        });
    };

    window.sfAthenaDownloadAndMark = function () {
        if (!reportUrl) return;

        var btn = document.getElementById('sfAthenaBtnDownload');
        if (btn) { btn.disabled = true; }

        // Lisää context=athena parametri
        var url = reportUrl + (reportUrl.indexOf('?') === -1 ? '?' : '&') + 'context=athena';

        fetch(url, { method: 'GET', credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var contentType = res.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/pdf') === -1) throw new Error('Not a PDF');
            window.sfAthenaLastContentDisposition = res.headers.get('Content-Disposition') || '';
            return res.blob();
        })
        .then(function (blob) {
            var filename = 'safetyflash_report.pdf';
            var contentDisposition = window.sfAthenaLastContentDisposition || '';

            if (contentDisposition) {
                var utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
                var normalMatch = contentDisposition.match(/filename="?([^"]+)"?/i);

                if (utf8Match && utf8Match[1]) {
                    filename = decodeURIComponent(utf8Match[1]);
                } else if (normalMatch && normalMatch[1]) {
                    filename = normalMatch[1];
                }
            }

            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                URL.revokeObjectURL(a.href);
                document.body.removeChild(a);
            }, 100);

            window.sfAthenaCloseModal();
            // Badge päivitetään varmuuden vuoksi sivun uudelleenlatauksella
            // koska backend on tallentanut merkinnän context=athena-kautta
            if (typeof window.sfToast === 'function') {
                window.sfToast('success', cfg.i18n && cfg.i18n.pdf_downloaded ? cfg.i18n.pdf_downloaded : 'Raportti ladattu');
            }
            // Päivitä badge client-puolella optimistisesti
            var now = new Date();
            var exportedAt = now.getFullYear() + '-' +
                String(now.getMonth() + 1).padStart(2, '0') + '-' +
                String(now.getDate()).padStart(2, '0') + ' ' +
                String(now.getHours()).padStart(2, '0') + ':' +
                String(now.getMinutes()).padStart(2, '0') + ':' +
                String(now.getSeconds()).padStart(2, '0');
            sfAthenaBadgeSetExported(exportedAt, cfg.i18n && cfg.i18n.current_user ? cfg.i18n.current_user : '');
        })
        .catch(function () {
            if (btn) { btn.disabled = false; }
        });
    };

    // Badge-päivitys: muuttaa "ei viety" -> "viety" ilman sivun uudelleenlatausta
    function sfAthenaBadgeSetExported(exportedAt, userName) {
        var badge = document.getElementById('sfAthenaBadge');
        if (!badge) return;

        var formattedDate = exportedAt;
        try {
            var d = new Date(exportedAt.replace(' ', 'T'));
            formattedDate = d.getDate() + '.' + (d.getMonth() + 1) + '.' + d.getFullYear();
        } catch (e) { /* käytä sellaisenaan */ }

        var badgePrefix = cfg.i18n && cfg.i18n.badge_athena_exported ? cfg.i18n.badge_athena_exported : 'Tallennettu Athenaan';
        var text = badgePrefix;

        if (formattedDate) {
            text += ' ' + formattedDate;
        }

        badge.className = 'sf-athena-badge sf-athena-badge--ok';
        badge.removeAttribute('onclick');
        badge.style.removeProperty('cursor');
        if (badge.tagName === 'BUTTON') {
            badge.setAttribute('disabled', 'disabled');
            badge.setAttribute('aria-label', text);
        }

        var iconEl = badge.querySelector('.sf-athena-badge__icon');
        if (iconEl) {
            iconEl.src = (cfg.baseUrl || '') + '/assets/img/icons/check.svg';
        }
        var textEl = badge.querySelector('.sf-athena-badge__text');
        if (textEl) {
            textEl.textContent = text;
        }

        var actionBtn = document.querySelector('.sf-athena-action-btn');
        if (actionBtn) {
            actionBtn.remove();
        }
    }

    // ESC sulkee modalin
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('sfAthenaReminderModal');
            if (modal && !modal.classList.contains('hidden')) {
                window.sfAthenaCloseModal();
            }
        }
    });

    // "Ei vielä viety" -badge avaa modalin
    document.addEventListener('DOMContentLoaded', function () {
        var badge = document.getElementById('sfAthenaBadge');
        if (badge && badge.classList.contains('sf-athena-badge--missing')) {
            badge.addEventListener('click', openAthenaModal);
        }
    });

    // Automaattinen modalin avaus julkaisun jälkeen
    document.addEventListener('DOMContentLoaded', function () {
        if (!cfg.showReminder) return;

        // Tarkista "muistuta myöhemmin" -localStorage-lippu
        try {
            if (localStorage.getItem(SNOOZE_KEY)) return;
        } catch (e) { /* jatketaan */ }

        // Lyhyt viive, jotta sivu ehtii renderöityä
        setTimeout(openAthenaModal, 600);
    });

}());