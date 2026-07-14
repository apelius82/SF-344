// assets/js/dashboard-report.js
(function () {
    'use strict';

    var I18N = (typeof window.SF_REPORT_I18N === 'object' && window.SF_REPORT_I18N)
        ? window.SF_REPORT_I18N
        : { generating: 'Creating report...', generate: 'Create report', error: 'Failed to create report' };

    function getBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function openModal() {
        var modal = document.getElementById('sf-report-modal');
        if (!modal) return;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var firstInput = modal.querySelector('input, select, button:not(.sf-report-modal-close)');
        if (firstInput) firstInput.focus();
    }

    function closeModal() {
        var modal = document.getElementById('sf-report-modal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
        setGenerating(false);
    }

    function setGenerating(active) {
        var btn = document.getElementById('sf-report-generate-btn');
        if (!btn) return;
        var spinner = btn.querySelector('.sf-report-btn-spinner');
        var text = btn.querySelector('.sf-report-btn-text');
        if (active) {
            btn.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            if (text) text.textContent = I18N.generating;
        } else {
            btn.disabled = false;
            if (spinner) spinner.style.display = 'none';
            if (text) text.textContent = I18N.generate;
        }
    }

    // Set quick selection date ranges
    function applyQuickPeriod(period) {
        var startInput = document.getElementById('sf-report-start-date');
        var endInput = document.getElementById('sf-report-end-date');
        if (!startInput || !endInput) return;

        var now = new Date();
        var y = now.getFullYear();
        var m = now.getMonth();
        var d = now.getDate();

        function fmt(date) {
            var yy = date.getFullYear();
            var mm = String(date.getMonth() + 1).padStart(2, '0');
            var dd = String(date.getDate()).padStart(2, '0');
            return yy + '-' + mm + '-' + dd;
        }

        switch (period) {
            case 'thismonth': {
                var start = new Date(y, m, 1);
                var end = new Date(y, m + 1, 0);
                startInput.value = fmt(start);
                endInput.value = fmt(end);
                break;
            }
            case '3months': {
                var start3 = new Date(y, m - 2, 1);
                var end3 = new Date(y, m + 1, 0);
                startInput.value = fmt(start3);
                endInput.value = fmt(end3);
                break;
            }
            case '6months': {
                var start6 = new Date(y, m - 5, 1);
                var end6 = new Date(y, m + 1, 0);
                startInput.value = fmt(start6);
                endInput.value = fmt(end6);
                break;
            }
            case 'thisyear': {
                startInput.value = y + '-01-01';
                endInput.value = fmt(new Date(y, 11, 31));
                break;
            }
            case 'all':
            default:
                startInput.value = '';
                endInput.value = '';
                break;
        }
    }

    function generateReport() {
        var startDate = (document.getElementById('sf-report-start-date') || {}).value || '';
        var endDate = (document.getElementById('sf-report-end-date') || {}).value || '';
        var site = (document.getElementById('sf-report-site') || {}).value || '';
        var includeStats = document.getElementById('sf-report-include-stats') ? document.getElementById('sf-report-include-stats').checked : true;
        var includeWorksites = document.getElementById('sf-report-include-worksites') ? document.getElementById('sf-report-include-worksites').checked : true;
        var includeInjuries = document.getElementById('sf-report-include-injuries') ? document.getElementById('sf-report-include-injuries').checked : true;
        var includeRecent = document.getElementById('sf-report-include-recent') ? document.getElementById('sf-report-include-recent').checked : true;

        if (!includeStats && !includeWorksites && !includeInjuries && !includeRecent) {
            var selectMsg = (window.SF_REPORT_I18N && window.SF_REPORT_I18N.selectContent)
                ? window.SF_REPORT_I18N.selectContent
                : 'Please select at least one content section.';
            alert(selectMsg);
            return;
        }

        setGenerating(true);

        var csrfToken = window.SF_CSRF_TOKEN || '';
        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        if (startDate) formData.append('start_date', startDate);
        if (endDate) formData.append('end_date', endDate);
        if (site) formData.append('site', site);
        formData.append('include_stats', includeStats ? '1' : '0');
        formData.append('include_worksites', includeWorksites ? '1' : '0');
        formData.append('include_injuries', includeInjuries ? '1' : '0');
        formData.append('include_recent', includeRecent ? '1' : '0');

        var baseUrl = getBaseUrl();
        fetch(baseUrl + '/app/api/generate_dashboard_report.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (res) {
                if (!res.ok) {
                    return res.text().then(function (txt) {
                        throw new Error(txt || 'HTTP ' + res.status);
                    });
                }
                var contentType = res.headers.get('Content-Type') || '';
                if (contentType.indexOf('application/pdf') === -1) {
                    return res.text().then(function (txt) {
                        throw new Error(txt || 'Unexpected response');
                    });
                }
                return res.blob();
            })
            .then(function (blob) {
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'dashboard-report-' + new Date().toISOString().slice(0, 10) + '.pdf';
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 100);
                closeModal();
            })
            .catch(function (err) {
                setGenerating(false);
                alert(I18N.error + '\n' + (err.message || ''));
            });
    }

    function init() {
        var reportBtn = document.getElementById('sf-report-btn');
        if (reportBtn) {
            reportBtn.addEventListener('click', openModal);
        }

        var closeBtn = document.getElementById('sf-report-modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        var cancelBtn = document.getElementById('sf-report-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }

        var generateBtn = document.getElementById('sf-report-generate-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', generateReport);
        }

        var backdrop = document.getElementById('sf-report-modal-backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closeModal);
        }

        // Quick period buttons
        var quickBtns = document.querySelectorAll('.sf-report-quick-btn');
        quickBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                quickBtns.forEach(function (b) { b.classList.remove('sf-active'); });
                btn.classList.add('sf-active');
                applyQuickPeriod(btn.dataset.period || 'all');
            });
        });

        // When date inputs change, clear active quick button
        var dateInputs = [
            document.getElementById('sf-report-start-date'),
            document.getElementById('sf-report-end-date')
        ];
        dateInputs.forEach(function (input) {
            if (!input) return;
            input.addEventListener('change', function () {
                quickBtns.forEach(function (b) { b.classList.remove('sf-active'); });
            });
        });

        // Keyboard: close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('sf-report-modal');
                if (modal && modal.style.display !== 'none') {
                    closeModal();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());