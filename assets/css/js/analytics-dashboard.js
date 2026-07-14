// assets/js/analytics-dashboard.js
(function () {
    'use strict';

    var baseUrl = window.SF_BASE_URL || '';
    var i18n = window.SF_ANALYTICS_I18N || {};
    var currentPeriod = 'year';
    var currentYear = new Date().getFullYear();
    var currentMonth = '';
    var currentDate = '';
    var currentUserFilter = 'exclude_admins';
    var currentFetchController = null;
    var currentRequestId = 0;

    function getTodayDateString() {
    var today = new Date();
    var year = today.getFullYear();
    var month = String(today.getMonth() + 1).padStart(2, '0');
    var day = String(today.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function formatNumber(value) {
        value = Number(value || 0);
        return value.toLocaleString('fi-FI');
    }

    function formatPercent(value) {
        value = Number(value || 0);
        return value.toLocaleString('fi-FI', {
            minimumFractionDigits: value % 1 === 0 ? 0 : 1,
            maximumFractionDigits: 1
        }) + ' %';
    }

    function formatSeconds(value) {
        value = Number(value || 0);

        if (value < 60) {
            return Math.round(value) + ' s';
        }

        var minutes = Math.floor(value / 60);
        var seconds = Math.round(value % 60);

        return minutes + ' min ' + seconds + ' s';
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }

        var date = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString('fi-FI', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatYesNo(value) {
        return Number(value || 0) === 1
            ? (i18n.yes || 'Kyllä')
            : (i18n.no || 'Ei');
    }

    function setLoading(isLoading) {
        var el = document.getElementById('sfAnalyticsLoading');
        var page = document.querySelector('.sf-analytics-page');

        if (el) {
            el.classList.toggle('visible', !!isLoading);
        }

        if (page) {
            page.classList.toggle('is-loading', !!isLoading);
        }
    }

    function setSummary(summary) {
        summary = summary || {};

        var fields = {
            active_today: formatNumber(summary.active_today),
            active_period: formatNumber(summary.active_period),
            page_views: formatNumber(summary.page_views),
            flash_views: formatNumber(summary.flash_views),
            pwa_users: formatNumber(summary.pwa_users),
            pwa_install_rate: formatPercent(summary.pwa_install_rate),
            push_permission_granted: formatNumber(summary.push_permission_granted),
            push_permission_denied: formatNumber(summary.push_permission_denied),
            push_permission_default: formatNumber(summary.push_permission_default),
            read_100_rate: formatPercent(summary.read_100_rate),
            avg_duration_seconds: formatSeconds(summary.avg_duration_seconds),
            push_sent: formatNumber(summary.push_sent),
            push_clicked: formatNumber(summary.push_clicked),
            push_opened: formatNumber(summary.push_opened),
            push_ctr: formatPercent(summary.push_ctr),
            push_open_rate: formatPercent(summary.push_open_rate)
        };

        Object.keys(fields).forEach(function (key) {
            var nodes = document.querySelectorAll('[data-analytics-value="' + key + '"]');
            nodes.forEach(function (node) {
                node.textContent = fields[key];
            });
        });
    }

    function loadData() {
        var requestId = ++currentRequestId;

        if (currentFetchController) {
            currentFetchController.abort();
        }

        currentFetchController = new AbortController();

        setLoading(true);
        renderLoadingState();

        var params = new URLSearchParams();
        params.set('period', currentPeriod);
        params.set('year', String(currentYear));
        params.set('user_filter', currentUserFilter);

        if (currentPeriod === 'month' && currentMonth) {
            params.set('month', String(currentMonth));
        }

        if (currentPeriod === 'day') {
            params.set('date', currentDate || getTodayDateString());
        }

        fetch(baseUrl + '/app/api/analytics_stats.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: currentFetchController.signal,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            })
            .then(function (data) {
                if (requestId !== currentRequestId) {
                    return;
                }

                if (!data || !data.ok) {
                    throw new Error('Invalid analytics response');
                }

                setSummary(data.summary || {});
                renderInternalClicks(data.internal_clicks || [], data.internal_click_users || []);
                renderCopyEvents(data.copy_events || []);
                renderPwaChart(data.pwa_usage || []);
                renderBars('sfAnalyticsDevices', data.devices || [], 'device_label', 'users', {
                    secondaryKey: 'events',
                    secondaryLabel: i18n.events || 'Tapahtumat'
                });
                renderTable('sfAnalyticsTopPages', normalizePageRows(data.top_pages || []), [
                    { key: 'page_label', label: 'Sivu' },
                    { key: 'views', label: i18n.views || 'Katselut', format: formatNumber },
                    { key: 'users', label: i18n.users || 'Käyttäjät', format: formatNumber }
                ]);
                renderTopFlashes(data.top_flashes || []);
                renderBars('sfAnalyticsSources', normalizeSourceRows(data.traffic_sources || []), 'source_label', 'events', {
                    secondaryKey: 'users',
                    secondaryLabel: i18n.users || 'Käyttäjät'
                });
                renderTable('sfAnalyticsWorksites', data.worksites || [], [
                    { key: 'site', label: 'Työmaa' },
                    { key: 'flash_views', label: i18n.views || 'Katselut', format: formatNumber },
                    { key: 'users', label: i18n.users || 'Käyttäjät', format: formatNumber }
                ]);

                renderTable('sfAnalyticsDailyActivity', data.daily_activity || [], [
                    { key: 'activity_date', label: i18n.date || 'Päivä' },
                    { key: 'users', label: i18n.users || 'Käyttäjät', format: formatNumber },
                    { key: 'page_views', label: i18n.views || 'Katselut', format: formatNumber },
                    { key: 'flash_views', label: 'SafetyFlash', format: formatNumber },
                    { key: 'full_reads', label: i18n.fullReads || 'Luettu loppuun', format: formatNumber },
                    { key: 'push_opens', label: i18n.pushOpens || 'Push-avaukset', format: formatNumber },
                    { key: 'pwa_users', label: i18n.pwa || 'PWA', format: formatNumber },
                    { key: 'events', label: i18n.events || 'Tapahtumat', format: formatNumber }
                ]);

                renderTable('sfAnalyticsUserActivity', data.user_activity || [], [
                    { key: 'user_name', label: i18n.user || 'Käyttäjä' },
                    { key: 'email', label: 'Email' },
                    { key: 'role_name', label: i18n.role || 'Rooli' },
                    { key: 'events', label: i18n.events || 'Tapahtumat', format: formatNumber },
                    { key: 'flash_views', label: i18n.views || 'Katselut', format: formatNumber },
                    { key: 'full_reads', label: i18n.fullReads || 'Luettu loppuun', format: formatNumber },
                    { key: 'unique_flashes', label: i18n.uniqueFlashes || 'SafetyFlashit', format: formatNumber },
                    { key: 'active_days', label: i18n.activeDays || 'Aktiiviset päivät', format: formatNumber },
                    { key: 'push_opens', label: i18n.pushOpens || 'Push-avaukset', format: formatNumber },
                    { key: 'used_pwa', label: i18n.pwaUsed || 'PWA käytössä', format: formatYesNo },
                    { key: 'last_active_at', label: i18n.lastActive || 'Viimeksi aktiivinen', format: formatDateTime }
                ]);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                renderError();
            })
            .finally(function () {
                if (requestId === currentRequestId) {
                    setLoading(false);
                }
            });
    }

    function renderError() {
        setSummary({
            active_today: 0,
            active_period: 0,
            page_views: 0,
            flash_views: 0,
            pwa_users: 0,
            pwa_install_rate: 0,
            push_permission_granted: 0,
            push_permission_denied: 0,
            push_permission_default: 0,
            read_100_rate: 0,
            avg_duration_seconds: 0,
            push_sent: 0,
            push_clicked: 0,
            push_opened: 0,
            push_ctr: 0,
            push_open_rate: 0
        });

        renderChartMessage(i18n.error || 'Analytiikan lataus epäonnistui. Päivitä sivu tai kokeile toista ajanjaksoa.');

        var containers = [
            'sfAnalyticsInternalClicks',
            'sfAnalyticsInternalClickUsers',
            'sfAnalyticsDevices',
            'sfAnalyticsTopPages',
            'sfAnalyticsTopFlashes',
            'sfAnalyticsSources',
            'sfAnalyticsWorksites',
            'sfAnalyticsDailyActivity',
            'sfAnalyticsUserActivity'
        ];

        containers.forEach(function (id) {
            var el = document.getElementById(id);

            if (el) {
                el.innerHTML = '<div class="sf-analytics-empty-small">' + escapeHtml(i18n.error || 'Analytiikan lataus epäonnistui.') + '</div>';
            }
        });
    }
    function renderLoadingState() {
        var containers = [
            'sfAnalyticsInternalClicks',
            'sfAnalyticsInternalClickUsers',
            'sfAnalyticsDevices',
            'sfAnalyticsTopPages',
            'sfAnalyticsTopFlashes',
            'sfAnalyticsSources',
            'sfAnalyticsWorksites',
            'sfAnalyticsDailyActivity',
            'sfAnalyticsUserActivity'
        ];

        renderChartMessage(i18n.loading || 'Ladataan analytiikkaa…');

        containers.forEach(function (id) {
            var el = document.getElementById(id);

            if (el) {
                el.innerHTML = '<div class="sf-analytics-skeleton-list">' +
                    '<span></span><span></span><span></span><span></span>' +
                '</div>';
            }
        });
    }

    function renderChartMessage(message) {
        var canvas = document.getElementById('sfAnalyticsTrendChart');

        if (!canvas) {
            return;
        }

        var ctx = canvas.getContext('2d');
        var ratio = window.devicePixelRatio || 1;
        var width = canvas.clientWidth || canvas.parentElement.clientWidth || 600;
        var height = 260;

        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.height = height + 'px';

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);

        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(0, 0, width, height);

        ctx.fillStyle = '#64748b';
        ctx.font = '700 14px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(message, width / 2, height / 2);
        ctx.textAlign = 'left';
    }
    function renderTrendChart(series) {
        var canvas = document.getElementById('sfAnalyticsTrendChart');

        if (!canvas) {
            return;
        }

        var ctx = canvas.getContext('2d');
        var ratio = window.devicePixelRatio || 1;
        var width = canvas.clientWidth || canvas.parentElement.clientWidth || 600;
        var height = 260;

        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.height = height + 'px';

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);

        var padding = 34;
        var chartWidth = width - padding * 2;
        var chartHeight = height - padding * 2;

        var maxValue = 1;

        series.forEach(function (item) {
            maxValue = Math.max(
                maxValue,
                Number(item.page_views || 0),
                Number(item.flash_views || 0),
                Number(item.users || 0)
            );
        });

        drawGrid(ctx, padding, chartWidth, chartHeight, maxValue);
        drawLine(ctx, series, 'page_views', padding, chartWidth, chartHeight, maxValue, '#111827');
        drawLine(ctx, series, 'flash_views', padding, chartWidth, chartHeight, maxValue, '#FEE000');
        drawLine(ctx, series, 'users', padding, chartWidth, chartHeight, maxValue, '#2563eb');

        renderTrendLegend();
    }

    function renderTrendLegend() {
        var legend = document.getElementById('sfAnalyticsTrendLegend');

        if (!legend) {
            return;
        }

        legend.innerHTML =
            '<span><i class="sf-analytics-dot sf-analytics-dot-dark"></i>' + escapeHtml(i18n.views || 'Katselut') + '</span>' +
            '<span><i class="sf-analytics-dot sf-analytics-dot-yellow"></i>SafetyFlash</span>' +
            '<span><i class="sf-analytics-dot sf-analytics-dot-blue"></i>' + escapeHtml(i18n.users || 'Käyttäjät') + '</span>';
    }

    function drawGrid(ctx, padding, chartWidth, chartHeight, maxValue) {
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.28)';
        ctx.lineWidth = 1;

        for (var i = 0; i <= 4; i++) {
            var y = padding + (chartHeight / 4) * i;
            ctx.beginPath();
            ctx.moveTo(padding, y);
            ctx.lineTo(padding + chartWidth, y);
            ctx.stroke();
        }

        ctx.fillStyle = '#94a3b8';
        ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(String(Math.round(maxValue)), 6, padding + 4);
        ctx.fillText('0', 14, padding + chartHeight);
    }

    function drawLine(ctx, series, key, padding, chartWidth, chartHeight, maxValue, color) {
        if (!series.length) {
            return;
        }

        ctx.strokeStyle = color;
        ctx.lineWidth = 3;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.beginPath();

        series.forEach(function (item, index) {
            var x = padding + (series.length === 1 ? 0 : (chartWidth / (series.length - 1)) * index);
            var value = Number(item[key] || 0);
            var y = padding + chartHeight - ((value / maxValue) * chartHeight);

            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });

        ctx.stroke();
    }
    function getInteractionLabel(eventType) {
        var labels = {
            view_tab_comments_open: 'Kommentit-välilehti',
            view_tab_events_open: 'Tapahtumat-välilehti',
            view_tab_additional_info_open: 'Lisätiedot-välilehti',
            view_tab_versions_open: 'Versiot-välilehti',
            view_tab_media_open: 'Media-välilehti',
            view_playlist_modal_open: 'Ajolista avattu',
            view_image_copy: 'View-kuva kopioitu',
            view_image_copy_failed: 'View-kuvan kopiointi epäonnistui',
            dashboard_module_copy_image: 'Dashboard-moduuli kopioitu kuvaksi',
            dashboard_module_copy_image_failed: 'Dashboard-moduulin kopiointi epäonnistui',
            dashboard_monthly_flash_open: 'Kuukauden SafetyFlash avattu',
            dashboard_injury_flash_open: 'Tapaturmalistasta avattu'
        };

        return labels[eventType] || eventType;
    }

    function renderInternalClicks(rows, userRows) {
        var container = document.getElementById('sfAnalyticsInternalClicks');

        if (container) {
            if (!rows.length) {
                container.innerHTML = '<div class="sf-analytics-empty-small">' + escapeHtml(i18n.noData || 'Ei dataa') + '</div>';
            } else {
                var max = 1;

                rows.forEach(function (row) {
                    max = Math.max(max, Number(row.clicks || 0));
                });

                container.innerHTML = rows.map(function (row) {
                    var clicks = Number(row.clicks || 0);
                    var users = Number(row.users || 0);
                    var width = Math.max(6, Math.round((clicks / max) * 100));

                    return '<div class="sf-analytics-interaction-row">' +
                        '<div class="sf-analytics-interaction-main">' +
                            '<div>' +
                                '<strong>' + escapeHtml(getInteractionLabel(row.event_type)) + '</strong>' +
                                '<span>' + formatNumber(users) + ' ' + escapeHtml(i18n.users || 'käyttäjää') + '</span>' +
                            '</div>' +
                            '<em>' + formatNumber(clicks) + '</em>' +
                        '</div>' +
                        '<div class="sf-analytics-interaction-track">' +
                            '<div style="width:' + width + '%"></div>' +
                        '</div>' +
                    '</div>';
                }).join('');
            }
        }

        renderTable('sfAnalyticsInternalClickUsers', (userRows || []).map(function (row) {
            var actionTypes = String(row.action_types || '')
                .split(',')
                .filter(Boolean)
                .map(getInteractionLabel);

            row.action_summary = actionTypes.slice(0, 3).join(', ');

            if (actionTypes.length > 3) {
                row.action_summary += ' +' + (actionTypes.length - 3);
            }

            return row;
        }), [
            { key: 'user_name', label: i18n.user || 'Käyttäjä' },
            { key: 'email', label: 'Email' },
            { key: 'clicks', label: i18n.clicks || 'Klikkaukset', format: formatNumber },
            { key: 'action_count', label: i18n.actions || 'Toimintoja', format: formatNumber },
            { key: 'action_summary', label: i18n.action || 'Toiminto' },
            { key: 'last_clicked_at', label: i18n.lastClick || 'Viimeisin klikkaus', format: formatDateTime }
        ]);
    }

    function getCopySourceLabel(row) {
        var eventType = row.event_type || '';
        var source = row.source || '';
        var previewCard = row.preview_card || '';
        var moduleTitle = row.module_title || '';
        var moduleKey = row.module_key || '';

        if (eventType.indexOf('dashboard_module_copy_image') === 0) {
            return moduleTitle || moduleKey || 'Dashboard';
        }

        if (source === 'view_preview') {
            if (previewCard === 'single') return 'View: yksi kortti';
            if (previewCard === 'primary') return 'View: kortti 1';
            if (previewCard === 'secondary') return 'View: kortti 2';
            return 'View';
        }

        return source || 'Tuntematon';
    }

    function renderCopyEvents(rows) {
        renderTable('sfAnalyticsCopyEvents', (rows || []).map(function (row) {
            row.copy_source_label = getCopySourceLabel(row);
            row.result_label = row.result === 'failed' || String(row.event_type || '').indexOf('_failed') !== -1
                ? (i18n.failed || 'Epäonnistui')
                : (i18n.success || 'Onnistui');

            row.flash_label = row.flash_id
                ? 'SafetyFlash #' + row.flash_id
                : (row.module_title || row.module_key || 'Dashboard');

            return row;
        }), [
            { key: 'created_at', label: i18n.date || 'Aika', format: formatDateTime },
            { key: 'user_name', label: i18n.user || 'Käyttäjä' },
            { key: 'flash_label', label: 'Kohde' },
            { key: 'site', label: 'Työmaa' },
            { key: 'flash_type', label: 'Tyyppi' },
            { key: 'copy_source_label', label: i18n.source || 'Lähde' },
            { key: 'result_label', label: i18n.status || 'Tila' }
        ]);
    }
    function renderPwaChart(rows) {
        var canvas = document.getElementById('sfAnalyticsPwaChart');
        var legend = document.getElementById('sfAnalyticsPwaLegend');

        if (!canvas) {
            return;
        }

        var pwa = 0;
        var browser = 0;

        rows.forEach(function (row) {
            if (row.usage_mode === 'pwa') {
                pwa += Number(row.users || 0);
            } else {
                browser += Number(row.users || 0);
            }
        });

        var total = pwa + browser;
        var ctx = canvas.getContext('2d');
        var ratio = window.devicePixelRatio || 1;
        var size = 190;

        canvas.width = size * ratio;
        canvas.height = size * ratio;
        canvas.style.width = size + 'px';
        canvas.style.height = size + 'px';

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, size, size);

        var center = size / 2;
        var radius = 72;
        var start = -Math.PI / 2;

        if (total <= 0) {
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 22;
            ctx.beginPath();
            ctx.arc(center, center, radius, 0, Math.PI * 2);
            ctx.stroke();
        } else {
            var pwaAngle = (pwa / total) * Math.PI * 2;

            ctx.strokeStyle = '#FEE000';
            ctx.lineWidth = 22;
            ctx.beginPath();
            ctx.arc(center, center, radius, start, start + pwaAngle);
            ctx.stroke();

            ctx.strokeStyle = '#111827';
            ctx.beginPath();
            ctx.arc(center, center, radius, start + pwaAngle, start + Math.PI * 2);
            ctx.stroke();
        }

        ctx.fillStyle = '#111827';
        ctx.font = '700 24px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(total > 0 ? Math.round((pwa / total) * 100) + '%' : '0%', center, center + 8);

        if (legend) {
            legend.innerHTML =
                '<span><i class="sf-analytics-dot sf-analytics-dot-yellow"></i>' + escapeHtml(i18n.pwa || 'App') + ': ' + formatNumber(pwa) + ' ' + escapeHtml(i18n.users || 'käyttäjää') + '</span>' +
                '<span><i class="sf-analytics-dot sf-analytics-dot-dark"></i>' + escapeHtml(i18n.browser || 'Selain') + ': ' + formatNumber(browser) + ' ' + escapeHtml(i18n.users || 'käyttäjää') + '</span>';
        }
    }

    function renderBars(containerId, rows, labelKey, valueKey, options) {
        var container = document.getElementById(containerId);
        options = options || {};

        if (!container) {
            return;
        }

        if (!rows.length) {
            container.innerHTML = '<div class="sf-analytics-empty-small">' + escapeHtml(i18n.noData || 'Ei dataa') + '</div>';
            return;
        }

        var max = 1;
        rows.forEach(function (row) {
            max = Math.max(max, Number(row[valueKey] || 0));
        });

        container.innerHTML = rows.map(function (row) {
            var label = row[labelKey] || (i18n.unknown || 'Tuntematon');
            var value = Number(row[valueKey] || 0);
            var width = Math.max(4, Math.round((value / max) * 100));
            var secondaryValue = options.secondaryKey ? Number(row[options.secondaryKey] || 0) : null;
            var secondaryText = '';

            if (options.secondaryKey) {
                secondaryText = '<small>' +
                    formatNumber(secondaryValue) +
                    ' ' +
                    escapeHtml(options.secondaryLabel || '') +
                '</small>';
            }

            return '<div class="sf-analytics-bar-row">' +
                '<div class="sf-analytics-bar-meta">' +
                    '<span>' + escapeHtml(label) + secondaryText + '</span>' +
                    '<strong>' + formatNumber(value) + '</strong>' +
                '</div>' +
                '<div class="sf-analytics-bar-track"><div style="width:' + width + '%"></div></div>' +
            '</div>';
        }).join('');
    }
    function normalizePageRows(rows) {
        var labels = i18n.pageLabels || {};

        return rows.map(function (row) {
            var key = String(row.page || 'unknown').toLowerCase();

            row.page_label = labels[key] || row.page || labels.unknown || 'Tuntematon';

            return row;
        });
    }

    function normalizeSourceRows(rows) {
        var labels = i18n.sourceLabels || {};

        return rows.map(function (row) {
            var key = String(row.source || 'unknown').toLowerCase();

            row.source_label = labels[key] || row.source || labels.unknown || 'Tuntematon';

            return row;
        });
    }
	
    function renderTable(containerId, rows, columns) {
        var container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        if (!rows.length) {
            container.innerHTML = '<div class="sf-analytics-empty-small">' + escapeHtml(i18n.noData || 'Ei dataa') + '</div>';
            return;
        }

        var head = columns.map(function (column) {
            return '<th>' + escapeHtml(column.label) + '</th>';
        }).join('');

        var body = rows.map(function (row) {
            var cells = columns.map(function (column) {
                var value = row[column.key];
                if (typeof column.format === 'function') {
                    value = column.format(value);
                }

                return '<td>' + escapeHtml(String(value === null || value === undefined ? '' : value)) + '</td>';
            }).join('');

            return '<tr>' + cells + '</tr>';
        }).join('');

        container.innerHTML = '<table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    function renderTopFlashes(rows) {
        var container = document.getElementById('sfAnalyticsTopFlashes');
        if (!container) {
            return;
        }

        if (!rows.length) {
            container.innerHTML = '<div class="sf-analytics-empty-small">' + escapeHtml(i18n.noData || 'Ei dataa') + '</div>';
            return;
        }

        var html = '<table><thead><tr>' +
            '<th>SafetyFlash</th>' +
            '<th>Työmaa</th>' +
            '<th>' + escapeHtml(i18n.views || 'Katselut') + '</th>' +
            '<th>' + escapeHtml(i18n.uniqueReaders || 'Uniikit lukijat') + '</th>' +
            '<th>' + escapeHtml(i18n.readFullRate || 'Luettu loppuun %') + '</th>' +
            '<th>' + escapeHtml(i18n.avgDuration || 'Keskimääräinen aika') + '</th>' +
            '</tr></thead><tbody>';

        html += rows.map(function (row) {
            var title = row.title || ('SafetyFlash #' + row.flash_id);
            var href = baseUrl + '/index.php?page=view&id=' + encodeURIComponent(row.flash_id);

            return '<tr>' +
                '<td><a href="' + escapeAttribute(href) + '">' + escapeHtml(title) + '</a></td>' +
                '<td>' + escapeHtml(row.site || '') + '</td>' +
                '<td>' + formatNumber(row.views) + '</td>' +
                '<td>' + formatNumber(row.users) + '</td>' +
                '<td>' + formatPercent(row.read_100_rate) + '</td>' +
                '<td>' + formatSeconds(row.avg_duration_seconds) + '</td>' +
            '</tr>';
        }).join('');

        html += '</tbody></table>';

        container.innerHTML = html;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }
    function initRangeButtons() {
        var root = document.querySelector('.sf-analytics-page');

        if (!root || root.getAttribute('data-analytics-ready') === '1') {
            return;
        }

        root.setAttribute('data-analytics-ready', '1');

        var buttons = root.querySelectorAll('.sf-analytics-range-btn');
        var yearSelect = document.getElementById('sfAnalyticsYear');
        var monthSelect = document.getElementById('sfAnalyticsMonth');
        var userFilterSelect = document.getElementById('sfAnalyticsUserFilter');
        var dateInput = document.getElementById('sfAnalyticsDate');

        if (yearSelect) {
            currentYear = parseInt(yearSelect.value || String(new Date().getFullYear()), 10);

            yearSelect.addEventListener('change', function () {
                currentYear = parseInt(yearSelect.value || String(new Date().getFullYear()), 10);
                loadData();
            });
        }
        if (userFilterSelect) {
            currentUserFilter = userFilterSelect.value || 'exclude_admins';

            userFilterSelect.addEventListener('change', function () {
                currentUserFilter = userFilterSelect.value || 'exclude_admins';
                loadData();
            });
        }
        if (monthSelect) {
            currentMonth = monthSelect.value || '';

            monthSelect.addEventListener('change', function () {
                currentMonth = monthSelect.value || '';

                if (currentMonth) {
                    currentPeriod = 'month';

                    buttons.forEach(function (btn) {
                        btn.classList.toggle('active', btn.getAttribute('data-period') === 'month');
                    });
                }

                loadData();
            });
        }
        if (dateInput) {
            currentDate = dateInput.value || getTodayDateString();

            dateInput.addEventListener('change', function () {
                currentDate = dateInput.value || getTodayDateString();
                currentPeriod = 'day';

                buttons.forEach(function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-period') === 'day');
                });

                loadData();
            });
        }
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                buttons.forEach(function (btn) {
                    btn.classList.remove('active');
                });

                button.classList.add('active');
                currentPeriod = button.getAttribute('data-period') || 'year';

                if (currentPeriod === 'year' && monthSelect) {
                    monthSelect.value = '';
                    currentMonth = '';
                }

                if (currentPeriod === 'month' && monthSelect && !monthSelect.value) {
                    monthSelect.value = String(new Date().getMonth() + 1);
                    currentMonth = monthSelect.value;
                }

                if (currentPeriod === 'day') {
                    currentDate = getTodayDateString();

                    if (dateInput) {
                        dateInput.value = currentDate;
                    }
                }

                loadData();
            });
        });
    }

    function initAnalyticsDashboard(forceReload) {
        if (!document.querySelector('.sf-analytics-page')) {
            return;
        }

        initRangeButtons();

        if (forceReload || !window.__sfAnalyticsHasLoaded) {
            window.__sfAnalyticsHasLoaded = true;
            loadData();
        }
    }

    window.SafetyFlashAnalyticsDashboard = {
        init: function () {
            initAnalyticsDashboard(true);
        },
        refresh: function () {
            initAnalyticsDashboard(true);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initAnalyticsDashboard(false);
    });

    window.addEventListener('sf:content:updated', function (event) {
        var detail = event.detail || {};

        if (detail.page === 'settings' && detail.tab === 'analytics') {
            i18n = window.SF_ANALYTICS_I18N || {};
            initAnalyticsDashboard(true);
        }
    });

    window.addEventListener('popstate', function () {
        window.setTimeout(function () {
            if (document.querySelector('.sf-analytics-page')) {
                i18n = window.SF_ANALYTICS_I18N || {};
                initAnalyticsDashboard(true);
            }
        }, 80);
    });
})();