// assets/js/dashboard.js
(function () {
    'use strict';

    // -------------------------------------------------------
    // Injury Heatmap – module-level state
    // -------------------------------------------------------
    var injuryData          = null;  // latest API response
    var activeBpFilter      = null;  // svg_id of selected body part (main dashboard)
    var activeModalBpFilter = null;  // svg_id of selected body part (modal)
    var currentTimeParams   = {};    // mirror of the stats time filter params
    var DASHBOARD_MAX_ITEMS = 4;     // max items shown on dashboard list
	var pendingMonthlyStats = null;
	var monthlyChartLoaded = false;
    var monthlyActiveType = 'all';

    // i18n strings injected by PHP
    var I18N = (typeof window.SF_INJURY_I18N === 'object' && window.SF_INJURY_I18N)
        ? window.SF_INJURY_I18N
        : { empty: 'No injuries', noMatch: 'No cases', activeFilter: 'Filtered:', noData: 'No data' };

    // -------------------------------------------------------
    // Time filter functionality
    // -------------------------------------------------------
    function initTimeFilter() {
        const filterButtons = document.querySelectorAll('.sf-time-filter-btn');
        const monthSelect = document.getElementById('sf-filter-month');
        const yearSelect = document.getElementById('sf-filter-year');
        const siteSelect = document.getElementById('sf-dashboard-site-filter');
        const injurySiteSelect = document.getElementById('sf-injury-site-filter');
        const statsContainer = document.querySelector('.sf-dashboard-modern') || document.querySelector('.sf-dashboard');

        if (!statsContainer) return;

        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;

        function getDashboardSiteValue() {
            return siteSelect ? String(siteSelect.value || '') : '';
        }

        function syncInjurySiteFilter() {
            if (!injurySiteSelect) return;

            injurySiteSelect.value = getDashboardSiteValue();
        }

        function buildCurrentParams() {
            const activeButton = document.querySelector('.sf-time-filter-btn.sf-active');
            const activePeriod = activeButton ? activeButton.dataset.period : 'all';
            const month = monthSelect ? monthSelect.value : '';
            const year = yearSelect ? yearSelect.value : '';

            let params;

            if (month !== '') {
                params = {
                    month: month,
                    year: year || String(currentYear)
                };
            } else if (year !== '') {
                params = {
                    month: '',
                    year: year
                };
            } else {
                params = {
                    period: activePeriod || 'all'
                };
            }

            const site = getDashboardSiteValue();

            if (site !== '') {
                params.site = site;
            }

            return params;
        }

        function refreshDashboard(options) {
            const params = buildCurrentParams();

            syncInjurySiteFilter();
            updateDashboardSitePills();

            currentTimeParams = params;

            fetchStats(params, options || {});
            fetchInjuryData(Object.assign({}, params, { site: getDashboardSiteValue() }));
        }

        filterButtons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();

                const period = this.dataset.period || 'all';

                filterButtons.forEach(b => b.classList.remove('sf-active'));
                this.classList.add('sf-active');

                if (monthSelect && yearSelect) {
                    switch (period) {
                        case 'thismonth':
                            monthSelect.value = String(currentMonth);
                            yearSelect.value = String(currentYear);
                            break;
                        case 'thisyear':
                            monthSelect.value = '';
                            yearSelect.value = String(currentYear);
                            break;
                        case 'all':
                            monthSelect.value = '';
                            yearSelect.value = '';
                            break;
                        default:
                            monthSelect.value = '';
                            yearSelect.value = '';
                            break;
                    }
                }

                refreshDashboard({ dim: true });
            });
        });

        if (monthSelect) {
            monthSelect.addEventListener('change', function () {
                filterButtons.forEach(b => b.classList.remove('sf-active'));

                if (monthSelect.value !== '' && yearSelect && yearSelect.value === '') {
                    yearSelect.value = String(currentYear);
                }

                refreshDashboard({ dim: true });
            });
        }

        if (yearSelect) {
            yearSelect.addEventListener('change', function () {
                filterButtons.forEach(b => b.classList.remove('sf-active'));
                refreshDashboard({ dim: true });
            });
        }

        if (siteSelect) {
            siteSelect.addEventListener('change', function () {
                refreshDashboard({ dim: true });
            });
        }

        function fetchStats(params, options) {
            options = options || {};

            const shouldDimDashboard = options.dim === true;

            if (shouldDimDashboard) {
                statsContainer.style.opacity = '0.5';
                statsContainer.style.pointerEvents = 'none';
            }

            const queryParams = new URLSearchParams();

            if (params.period) {
                queryParams.set('period', params.period);
            }

            if (params.month) {
                queryParams.set('month', params.month);
            }

            if (params.year) {
                queryParams.set('year', params.year);
            }

            if (params.site) {
                queryParams.set('site', params.site);
            }

            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
            const apiUrl = `${baseUrl}/app/api/dashboard-stats.php?${queryParams.toString()}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    updateStats(data);

                    if (window.SF_Skeleton && typeof window.SF_Skeleton.hide === 'function') {
                        window.SF_Skeleton.hide('dashboardSkeletonContainer', 120);
                    }
                })
                .catch(error => {
                    console.error('Failed to fetch stats:', error);

                    if (window.SF_Skeleton && typeof window.SF_Skeleton.hide === 'function') {
                        window.SF_Skeleton.hide('dashboardSkeletonContainer', 120);
                    }
                })
                .finally(() => {
                    statsContainer.style.opacity = '1';
                    statsContainer.style.pointerEvents = 'auto';
                });
        }

        refreshDashboard();
    }
    // Update statistics on page
    function updateStats(data) {
        // Update type statistics
        const redCount = document.querySelector('[data-stat="red"]');
        const yellowCount = document.querySelector('[data-stat="yellow"]');
        const greenCount = document.querySelector('[data-stat="green"]');
        const totalCount = document.querySelector('[data-stat="total"]');

        if (redCount) redCount.textContent = data.originalStats.red || 0;
        if (yellowCount) yellowCount.textContent = data.originalStats.yellow || 0;
        if (greenCount) greenCount.textContent = data.originalStats.green || 0;
        if (totalCount) totalCount.textContent = data.originalStats.total || 0;

        updateWorksiteStats(data.worksiteStats || [], data.locationMode === true, data.selectedSite || '');

pendingMonthlyStats = data.monthlyStats || [];

if (monthlyChartLoaded) {
    updateMonthlyLineChart(pendingMonthlyStats);
} else {
    renderMonthlyChartPlaceholder();
    initMonthlyChartLazyLoad();
}
    }

    function getSelectedDashboardSiteLabel() {
        const siteSelect = document.getElementById('sf-dashboard-site-filter');

        if (!siteSelect) {
            return I18N.allSites || 'Kaikki työmaat';
        }

        const selectedOption = siteSelect.options[siteSelect.selectedIndex];

        if (!siteSelect.value || !selectedOption) {
            return I18N.allSites || 'Kaikki työmaat';
        }

        return selectedOption.textContent.trim() || siteSelect.value;
    }

    function updateMonthlyHeaderTools() {
        const card = document.querySelector('.sf-monthly-card');
        if (!card) return;

        const header = card.querySelector('.sf-section-header');
        if (!header) return;

        let tools = header.querySelector('.sf-monthly-header-tools');

        if (!tools) {
            tools = document.createElement('div');
            tools.className = 'sf-monthly-header-tools';
            header.appendChild(tools);
        }

        tools.innerHTML = `
            <div class="sf-monthly-site-pill" title="${escapeHtml(getSelectedDashboardSiteLabel())}">
                ${escapeHtml(getSelectedDashboardSiteLabel())}
            </div>
        `;

        let filterBar = card.querySelector('.sf-monthly-type-filter-bar');

        if (!filterBar) {
            filterBar = document.createElement('div');
            filterBar.className = 'sf-monthly-type-filter-bar';
            header.insertAdjacentElement('afterend', filterBar);
        }

        const options = [
            { type: 'all', label: I18N.monthlyFilterAll || 'Kaikki', dot: 'all' },
            { type: 'red', label: I18N.red || 'Ensitiedote', dot: 'red' },
            { type: 'yellow', label: I18N.yellow || 'Vaaratilanne', dot: 'yellow' },
            { type: 'green', label: I18N.green || 'Tutkintatiedote', dot: 'green' }
        ];

        filterBar.innerHTML = options.map(function (option) {
            const isActive = monthlyActiveType === option.type;

            return `
                <button type="button"
                        class="sf-monthly-type-filter-btn sf-monthly-type-filter-btn--${escapeHtml(option.dot)}${isActive ? ' sf-active' : ''}"
                        data-monthly-type-filter="${escapeHtml(option.type)}">
                    <span class="sf-monthly-type-filter-dot" aria-hidden="true"></span>
                    <span>${escapeHtml(option.label)}</span>
                </button>
            `;
        }).join('');

        filterBar.querySelectorAll('[data-monthly-type-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                const nextType = this.dataset.monthlyTypeFilter || 'all';

                if (monthlyActiveType === nextType) {
                    return;
                }

                monthlyActiveType = nextType;
                updateMonthlyLineChart(pendingMonthlyStats || []);
            });
        });
    }

function renderMonthlyChartPlaceholder() {
    const container = document.getElementById('sf-monthly-chart');
    if (!container || monthlyChartLoaded) return;

    container.innerHTML = `
        <div class="sf-monthly-lazy-placeholder">
            <div class="sf-monthly-lazy-spinner" aria-hidden="true"></div>
            <div class="sf-monthly-lazy-title">Ladataan kuukausigraafia</div>
            <div class="sf-monthly-lazy-text">Graafi avautuu, kun vierität tähän kohtaan.</div>
        </div>
    `;
}

function initMonthlyChartLazyLoad() {
    const container = document.getElementById('sf-monthly-chart');
    if (!container || container.dataset.lazyBound === '1') return;

    container.dataset.lazyBound = '1';

    function loadMonthlyChart() {
        if (monthlyChartLoaded) return;

        monthlyChartLoaded = true;
        updateMonthlyLineChart(pendingMonthlyStats || []);
    }

    if (!('IntersectionObserver' in window)) {
        loadMonthlyChart();
        return;
    }

    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;

            observer.disconnect();
            loadMonthlyChart();
        });
    }, {
        root: null,
        rootMargin: '160px 0px',
        threshold: 0.15
    });

    observer.observe(container);
}
	
    function updateMonthlyLineChart(monthlyStats) {
        const container = document.getElementById('sf-monthly-chart');
        if (!container) return;

        if (!monthlyStats.length) {
            container.innerHTML = '<div class="sf-pending-empty"><span>' + escapeHtml(I18N.noData || 'No data') + '</span></div>';
            renderMonthlySelection(null, null);
            return;
        }

        const width = 760;
        const height = 360;
        const paddingLeft = 46;
        const paddingRight = 34;
        const paddingTop = 128;
        const paddingBottom = 72;
        const chartWidth = width - paddingLeft - paddingRight;
        const chartHeight = height - paddingTop - paddingBottom;
        const axisY = height - paddingBottom;
        const monthlyTypes = monthlyActiveType === 'all'
            ? ['red', 'yellow', 'green']
            : [monthlyActiveType];

        const maxValue = Math.max(
            ...monthlyStats.flatMap(item => monthlyTypes.map(type => item[type] || 0)),
            1
        );

        function xForIndex(index) {
            return paddingLeft + (chartWidth / Math.max(1, monthlyStats.length - 1)) * index;
        }

        function pointFor(item, index, key) {
            const x = xForIndex(index);
            const y = axisY - ((item[key] || 0) / maxValue) * chartHeight;
            return { x, y, value: item[key] || 0 };
        }

        function pointsFor(key) {
            return monthlyStats.map((item, index) => {
                const point = pointFor(item, index, key);
                return `${point.x},${point.y}`;
            }).join(' ');
        }

        function getMonthlyTypeLabel(key) {
            if (key === 'red') return I18N.red || 'Ensitiedote';
            if (key === 'yellow') return I18N.yellow || 'Vaaratilanne';
            if (key === 'green') return I18N.green || 'Tutkintatiedote';
            return key;
        }

        function monthGuides() {
            return monthlyStats.map((item, index) => {
                const x = xForIndex(index);

                return `
                    <g class="sf-monthly-month-guide" data-month-index="${index}">
                        <line x1="${x}" y1="${paddingTop}" x2="${x}" y2="${axisY}" class="sf-monthly-guide-line"></line>
                    </g>
                `;
            }).join('');
        }

        function circlesFor(key, className) {
            return monthlyStats.map((item, index) => {
                const point = pointFor(item, index, key);
                if (point.value <= 0) return '';

                const monthLabel = item.label || '';
                const typeLabel = getMonthlyTypeLabel(key);

                return `
                    <g class="sf-monthly-point-group"
                       data-month-index="${index}"
                       data-type="${key}"
                       tabindex="0"
                       role="button"
                       aria-label="${escapeHtml(monthLabel + ' · ' + typeLabel + ' · ' + point.value)}">
                        <circle cx="${point.x}" cy="${point.y}" r="30" class="sf-monthly-point-hit-area"></circle>
                        <line x1="${point.x}" y1="${point.y + 9}" x2="${point.x}" y2="${axisY}" class="sf-monthly-point-drop-line"></line>
                        <circle cx="${point.x}" cy="${point.y}" r="12" class="sf-monthly-point-ring"></circle>
                        <circle cx="${point.x}" cy="${point.y}" r="7" class="${className}"></circle>
                    </g>
                `;
            }).join('');
        }

        function valueLabelsFor() {
            const labelRows = {
                green: 32,
                yellow: 64,
                red: 96
            };

            const leaders = [];
            const bubbles = [];

            monthlyStats.forEach((item, index) => {
                monthlyTypes.forEach(type => {
                    const point = pointFor(item, index, type);
                    const value = Number(point.value || 0);

                    if (value <= 0) {
                        return;
                    }

                    const labelRadius = value >= 10 ? 12 : 11;
                    const labelX = Math.max(
                        paddingLeft + labelRadius,
                        Math.min(width - paddingRight - labelRadius, point.x)
                    );

                    const labelY = monthlyActiveType === 'all'
                        ? labelRows[type]
                        : Math.max(44, point.y - 42);

                    const leaderEndY = labelY + labelRadius + 8;

                    leaders.push(`
                        <line x1="${point.x}"
                              y1="${point.y - 14}"
                              x2="${labelX}"
                              y2="${leaderEndY}"
                              class="sf-monthly-value-leader"
                              data-month-index="${index}"
                              data-type="${type}"></line>
                    `);

                    bubbles.push(`
                        <g class="sf-monthly-value-label sf-monthly-value-label--${type}"
                           data-month-index="${index}"
                           data-type="${type}"
                           tabindex="0"
                           role="button"
                           aria-label="${escapeHtml((item.label || '') + ' · ' + getMonthlyTypeLabel(type) + ' · ' + value)}">
                            <circle cx="${labelX}" cy="${labelY}" r="${labelRadius}"></circle>
                            <text x="${labelX}" y="${labelY + 3.8}" text-anchor="middle" class="sf-monthly-svg-count">${value}</text>
                        </g>
                    `);
                });
            });

            return {
                leaders: leaders.join(''),
                bubbles: bubbles.join('')
            };
        }

        const labels = monthlyStats.map((item, index) => {
            const x = xForIndex(index);

            return `
                <g class="sf-monthly-label-group" data-month-index="${index}">
                    <line x1="${x}" y1="${axisY}" x2="${x}" y2="${axisY + 6}" class="sf-monthly-label-tick"></line>
					<text x="${x}" y="${height - 16}" text-anchor="middle" class="sf-monthly-svg-label">${escapeHtml(item.label)}</text>
                </g>
            `;
        }).join('');

        const lineMarkup = monthlyTypes.map(function (type) {
            return `<polyline points="${pointsFor(type)}" class="sf-monthly-line sf-monthly-line--${type}" data-type="${type}"></polyline>`;
        }).join('');

        const pointMarkup = monthlyTypes.map(function (type) {
            return circlesFor(type, 'sf-monthly-point sf-monthly-point--' + type);
        }).join('');

        const valueLabelMarkup = valueLabelsFor();
        const leaderMarkup = valueLabelMarkup.leaders;
        const badgeMarkup = valueLabelMarkup.bubbles;

        const singleTypeMode = monthlyActiveType !== 'all';

        updateMonthlyHeaderTools();

        container.innerHTML = `
            <svg class="sf-monthly-svg${singleTypeMode ? ' sf-monthly-svg--single-type' : ''}" viewBox="0 0 ${width} ${height}" role="img">
                ${monthGuides()}
                ${leaderMarkup}
                <line x1="${paddingLeft}" y1="${axisY}" x2="${width - paddingRight}" y2="${axisY}" class="sf-monthly-axis"></line>
                <line x1="${paddingLeft}" y1="${paddingTop}" x2="${paddingLeft}" y2="${axisY}" class="sf-monthly-axis"></line>
                ${lineMarkup}
                ${pointMarkup}
                ${labels}
                ${badgeMarkup}
            </svg>
        `;

        initMonthlySelection(container, monthlyStats);
        renderMonthlySelection(null, null);
    }

    function initMonthlySelection(container, monthlyStats) {
        const points = container.querySelectorAll('.sf-monthly-point-group');
        const guides = container.querySelectorAll('.sf-monthly-month-guide');
        const labels = container.querySelectorAll('.sf-monthly-label-group');
        const badges = container.querySelectorAll('.sf-monthly-value-label');
        const leaders = container.querySelectorAll('.sf-monthly-value-leader');
        const lines = container.querySelectorAll('.sf-monthly-line');
        const svg = container.querySelector('.sf-monthly-svg');

        function bringMonthlyTypeToFront(type) {
            if (!svg || !type) {
                return;
            }

            lines.forEach(line => {
                if (line.dataset.type === type) {
                    svg.appendChild(line);
                }
            });

            points.forEach(point => {
                if (point.dataset.type === type) {
                    svg.appendChild(point);
                }
            });

            badges.forEach(badge => {
                if (badge.dataset.type === type) {
                    svg.appendChild(badge);
                }
            });
        }

        function clearMonthlySelection() {
            points.forEach(point => {
                point.classList.remove('sf-monthly-point-group--active');
                point.classList.remove('sf-monthly-point-group--same-month');
                point.classList.remove('sf-monthly-point-group--muted');
            });

            guides.forEach(guide => {
                guide.classList.remove('sf-monthly-month-guide--active');
            });

            labels.forEach(label => {
                label.classList.remove('sf-monthly-label-group--active');
            });

            badges.forEach(badge => {
                badge.classList.remove('sf-monthly-value-label--active');
                badge.classList.remove('sf-monthly-value-label--hover');
                badge.classList.remove('sf-monthly-value-label--muted');
            });

            leaders.forEach(leader => {
                leader.classList.remove('sf-monthly-value-leader--active');
                leader.classList.remove('sf-monthly-value-leader--muted');
            });

            lines.forEach(line => {
                line.classList.remove('sf-monthly-line--active');
                line.classList.remove('sf-monthly-line--muted');
            });

            if (svg) {
                svg.classList.remove('sf-monthly-svg--selected');
                svg.classList.remove('sf-monthly-svg--active-series');
                svg.removeAttribute('data-active-type');
            }

            renderMonthlySelection(null, null);
        }

        function activateMonthlySelection(monthIndex, type) {
            const monthData = monthlyStats[monthIndex];

            points.forEach(point => {
                const isActive =
                    Number(point.dataset.monthIndex) === monthIndex &&
                    point.dataset.type === type;

                const isSameMonth = Number(point.dataset.monthIndex) === monthIndex;
                const isSameType = point.dataset.type === type;

                point.classList.toggle('sf-monthly-point-group--active', isActive);
                point.classList.toggle('sf-monthly-point-group--same-month', isSameMonth);
                point.classList.toggle('sf-monthly-point-group--muted', !isSameType);
            });

            guides.forEach(guide => {
                guide.classList.toggle('sf-monthly-month-guide--active', Number(guide.dataset.monthIndex) === monthIndex);
            });

            labels.forEach(label => {
                label.classList.toggle('sf-monthly-label-group--active', Number(label.dataset.monthIndex) === monthIndex);
            });

            badges.forEach(badge => {
                const isActiveBadge =
                    Number(badge.dataset.monthIndex) === monthIndex &&
                    badge.dataset.type === type;

                const isSameType = badge.dataset.type === type;

                badge.classList.toggle('sf-monthly-value-label--active', isActiveBadge);
                badge.classList.toggle('sf-monthly-value-label--muted', !isSameType);
            });

            leaders.forEach(leader => {
                const isActiveLeader =
                    Number(leader.dataset.monthIndex) === monthIndex &&
                    leader.dataset.type === type;

                const isSameType = leader.dataset.type === type;

                leader.classList.toggle('sf-monthly-value-leader--active', isActiveLeader);
                leader.classList.toggle('sf-monthly-value-leader--muted', !isSameType);
            });

            lines.forEach(line => {
                const isSameType = line.dataset.type === type;

                line.classList.toggle('sf-monthly-line--active', isSameType);
                line.classList.toggle('sf-monthly-line--muted', !isSameType);
            });

            bringMonthlyTypeToFront(type);

            if (svg) {
                svg.classList.remove('sf-monthly-svg--selected');

                svg.classList.add('sf-monthly-svg--active-series');
                svg.dataset.activeType = type;

                window.requestAnimationFrame(function () {
                    svg.classList.add('sf-monthly-svg--selected');
                });
            }

            renderMonthlySelection(monthData, type);

            const selectionCard = document.querySelector('.sf-monthly-selection-card');

            if (selectionCard) {
                selectionCard.classList.remove('sf-monthly-selection-card--pulse');

                window.requestAnimationFrame(function () {
                    selectionCard.classList.add('sf-monthly-selection-card--pulse');
                });

                window.setTimeout(function () {
                    selectionCard.classList.remove('sf-monthly-selection-card--pulse');
                }, 520);
            }
        }

        function setMonthlyHover(monthIndex, type, isHovering) {
            badges.forEach(badge => {
                const isHoverBadge =
                    Number(badge.dataset.monthIndex) === monthIndex &&
                    badge.dataset.type === type;

                badge.classList.toggle('sf-monthly-value-label--hover', isHovering && isHoverBadge);
            });
        }

        points.forEach(pointGroup => {
            pointGroup.addEventListener('click', function (event) {
                event.stopPropagation();

                activateMonthlySelection(
                    Number(this.dataset.monthIndex),
                    this.dataset.type
                );
            });

            pointGroup.addEventListener('mouseenter', function () {
                setMonthlyHover(
                    Number(this.dataset.monthIndex),
                    this.dataset.type,
                    true
                );
            });

            pointGroup.addEventListener('mouseleave', function () {
                setMonthlyHover(
                    Number(this.dataset.monthIndex),
                    this.dataset.type,
                    false
                );
            });

            pointGroup.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                activateMonthlySelection(
                    Number(this.dataset.monthIndex),
                    this.dataset.type
                );
            });
        });

        badges.forEach(badge => {
            badge.addEventListener('click', function (event) {
                event.stopPropagation();

                activateMonthlySelection(
                    Number(this.dataset.monthIndex),
                    this.dataset.type
                );
            });

            badge.addEventListener('mouseenter', function () {
                setMonthlyHover(
                    Number(this.dataset.monthIndex),
                    this.dataset.type,
                    true
                );
            });

            badge.addEventListener('mouseleave', function () {
                setMonthlyHover(
                    Number(this.dataset.monthIndex),
                    this.dataset.type,
                    false
                );
            });

            badge.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                activateMonthlySelection(
                    Number(this.dataset.monthIndex),
                    this.dataset.type
                );
            });
        });

        if (svg && svg.dataset.resetBound !== '1') {
            svg.dataset.resetBound = '1';

            svg.addEventListener('click', function (event) {
                const interactiveElement = event.target.closest('.sf-monthly-point-group, .sf-monthly-value-label');

                if (interactiveElement) {
                    return;
                }

                clearMonthlySelection();
            });
        }

        if (!window._sfMonthlyOutsideClickBound) {
            window._sfMonthlyOutsideClickBound = true;

            document.addEventListener('click', function (event) {
                const monthlyCard = event.target.closest('.sf-monthly-card');
                const monthlySelectionCard = event.target.closest('.sf-monthly-selection-card');

                if (monthlyCard || monthlySelectionCard) {
                    return;
                }

                document.querySelectorAll('.sf-monthly-svg--active-series').forEach(activeSvg => {
                    const activeContainer = activeSvg.closest('#sf-monthly-chart');

                    if (!activeContainer) {
                        return;
                    }

                    activeContainer.querySelectorAll('.sf-monthly-point-group--active, .sf-monthly-point-group--same-month, .sf-monthly-point-group--muted').forEach(element => {
                        element.classList.remove('sf-monthly-point-group--active');
                        element.classList.remove('sf-monthly-point-group--same-month');
                        element.classList.remove('sf-monthly-point-group--muted');
                    });

                    activeContainer.querySelectorAll('.sf-monthly-month-guide--active').forEach(element => {
                        element.classList.remove('sf-monthly-month-guide--active');
                    });

                    activeContainer.querySelectorAll('.sf-monthly-label-group--active').forEach(element => {
                        element.classList.remove('sf-monthly-label-group--active');
                    });

                    activeContainer.querySelectorAll('.sf-monthly-value-label--active, .sf-monthly-value-label--hover, .sf-monthly-value-label--muted').forEach(element => {
                        element.classList.remove('sf-monthly-value-label--active');
                        element.classList.remove('sf-monthly-value-label--hover');
                        element.classList.remove('sf-monthly-value-label--muted');
                    });

                    activeContainer.querySelectorAll('.sf-monthly-value-leader--active, .sf-monthly-value-leader--muted').forEach(element => {
                        element.classList.remove('sf-monthly-value-leader--active');
                        element.classList.remove('sf-monthly-value-leader--muted');
                    });

                    activeContainer.querySelectorAll('.sf-monthly-line--active, .sf-monthly-line--muted').forEach(element => {
                        element.classList.remove('sf-monthly-line--active');
                        element.classList.remove('sf-monthly-line--muted');
                    });

                    activeSvg.classList.remove('sf-monthly-svg--selected');
                    activeSvg.classList.remove('sf-monthly-svg--active-series');
                    activeSvg.removeAttribute('data-active-type');
                });

                renderMonthlySelection(null, null);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                document.body.click();
            });
        }
    }

    function renderMonthlySelection(monthData, type) {
        const card = document.querySelector('.sf-monthly-selection-card');
        if (!card) return;

const title = card.querySelector('#sf-monthly-selection-title') || card.querySelector('.sf-section-title');
const icon = card.querySelector('.sf-section-icon img');
const list = card.querySelector('.sf-recent-compact-list');
const footer = card.querySelector('.sf-pending-footer');

        if (!list) return;

        if (footer) {
            footer.style.display = monthData ? 'none' : '';
        }

if (!monthData || !type) {
    if (title) {
        title.textContent = I18N.monthlyTitle || 'Kuukauden SafetyFlashit';
    }

    if (icon) {
        icon.src = (window.SF_BASE_URL || '').replace(/\/$/, '') + '/assets/img/icons/dashboard.svg';
    }

            list.innerHTML = `
                <div class="sf-monthly-selection-empty">
                    ${escapeHtml(I18N.monthlyCta || 'Klikkaa graafin pistettä nähdäksesi kuukauden SafetyFlashit.')}
                </div>
            `;
            return;
        }

        const items = monthData.items && monthData.items[type] ? monthData.items[type] : [];
        const count = monthData[type] || 0;
const typeLabel = type === 'red'
    ? (I18N.red || 'Ensitiedote')
    : (type === 'yellow'
        ? (I18N.yellow || 'Vaaratilanne')
        : (I18N.green || 'Tutkintatiedote'));

if (icon) {
    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
    icon.src = baseUrl + (type === 'red'
        ? '/assets/img/icons/type-red.svg'
        : (type === 'yellow'
            ? '/assets/img/icons/type-yellow.svg'
            : '/assets/img/icons/type-green.svg'));
}

        if (title) {
            title.textContent = `${monthData.label} · ${typeLabel} (${count})`;
        }

        if (!items.length) {
            list.innerHTML = `
                <div class="sf-monthly-selection-empty">
                    ${escapeHtml(I18N.monthlyEmpty || 'Tälle kuukaudelle ei löytynyt SafetyFlasheja.')}
                </div>
            `;
            return;
        }

        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        list.innerHTML = items.map(item => {
            const titleText = escapeHtml(item.title || '');
            const site = escapeHtml(item.site || '');
            const date = escapeHtml(formatDate(item.created_at || ''));

return `
                <a href="${baseUrl}/index.php?page=view&id=${encodeURIComponent(item.id)}"
                   class="sf-recent-compact-item sf-monthly-selection-item"
                   data-sf-analytics-click="dashboard_monthly_flash_open"
                   data-sf-analytics-source="dashboard_monthly"
                   data-sf-analytics-target-type="flash"
                   data-sf-analytics-target-id="${escapeHtml(String(item.id || ''))}">
                    <span class="sf-type-dot sf-type-dot--${escapeHtml(type)}"></span>
                    <div class="sf-recent-compact-content">
                        <div class="sf-recent-compact-title">${titleText}</div>
                        <div class="sf-recent-compact-meta">
                            ${site ? `<span>${site}</span><span>·</span>` : ''}
                            ${date ? `<span>${date}</span>` : ''}
                        </div>
                    </div>
                </a>
            `;
        }).join('');
    }

     // Update worksite bars
    function updateWorksiteStats(worksiteStats, locationMode, selectedSite) {
        const container = document.querySelector('.sf-worksite-bars');
        const title = document.getElementById('sf-worksite-card-title');
        const maxVisibleItems = 10;

        if (!container) return;

        if (title) {
            title.textContent = locationMode
                ? (I18N.bySiteDetail || 'Tapahtumapaikat')
                : (I18N.byWorksite || 'Työmaakohtaisesti');
        }

        const worksiteCard = document.querySelector('.sf-worksite-card');
        const header = worksiteCard ? worksiteCard.querySelector('.sf-section-header') : null;

        if (header) {
            let sitePill = header.querySelector('.sf-dashboard-worksite-pill');

            if (locationMode && selectedSite) {
                if (!sitePill) {
                    sitePill = document.createElement('span');
                    sitePill.className = 'sf-dashboard-worksite-pill';
                    header.appendChild(sitePill);
                }

                sitePill.textContent = selectedSite;
                sitePill.setAttribute('title', selectedSite);
            } else if (sitePill) {
                sitePill.remove();
            }
        }

        const maxCount = worksiteStats.length > 0
            ? Math.max(...worksiteStats.map(ws => Number(ws.count || 0)))
            : 1;

        container.innerHTML = '';

        if (!worksiteStats.length) {
            container.innerHTML = `
                <div class="sf-pending-empty">
                    <span>${escapeHtml(I18N.noData || 'No data')}</span>
                </div>
            `;
            return;
        }

        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
        const list = document.createElement('div');
        const hiddenCount = Math.max(0, worksiteStats.length - maxVisibleItems);

        list.className = 'sf-worksite-bars-list';

        worksiteStats.forEach((ws, index) => {
            const label = ws.site || '';
            const count = Number(ws.count || 0);
            const barWidth = maxCount > 0 ? Math.round((count / maxCount) * 100) : 0;
            const row = document.createElement('a');

            if (locationMode) {
                row.href = `${baseUrl}/index.php?page=list&site=${encodeURIComponent(selectedSite)}&q=${encodeURIComponent(label)}`;
            } else {
                row.href = `${baseUrl}/index.php?page=list&site=${encodeURIComponent(label)}`;
            }

            row.className = index >= maxVisibleItems
                ? 'sf-worksite-bar-row sf-worksite-hidden'
                : 'sf-worksite-bar-row';

            row.style.setProperty('--bar-delay', `${Math.min(index, maxVisibleItems) * 0.04}s`);

            row.innerHTML = `
                <span class="sf-worksite-name">${escapeHtml(label)}</span>
                <div class="sf-worksite-bar-wrap">
                    <div class="sf-worksite-bar" style="--bar-width: ${barWidth}%;">
                        <span class="sf-worksite-count">${count}</span>
                    </div>
                </div>
            `;

            list.appendChild(row);
        });

        container.appendChild(list);

        if (hiddenCount <= 0) {
            return;
        }

        const toggleButton = document.createElement('button');
        const collapsedText = (I18N.showMoreLocations || '+ {n} muuta tapahtumapaikkaa').replace('{n}', hiddenCount);
        const expandedText = I18N.showLess || 'Näytä vähemmän';

        toggleButton.type = 'button';
        toggleButton.className = 'sf-worksite-show-all sf-worksite-show-all--compact';
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.innerHTML = `
            <span class="sf-toggle-text">${escapeHtml(collapsedText)}</span>
            <span class="sf-toggle-icon" aria-hidden="true">⌄</span>
        `;

        toggleButton.addEventListener('click', function () {
            const isExpanded = toggleButton.classList.contains('sf-expanded');
            const rows = list.querySelectorAll('.sf-worksite-bar-row');

            rows.forEach((row, index) => {
                if (index < maxVisibleItems) {
                    return;
                }

                if (isExpanded) {
                    row.classList.add('sf-worksite-hidden');
                    row.setAttribute('aria-hidden', 'true');
                } else {
                    row.classList.remove('sf-worksite-hidden');
                    row.removeAttribute('aria-hidden');
                }
            });

            toggleButton.classList.toggle('sf-expanded', !isExpanded);
            toggleButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');

            toggleButton.querySelector('.sf-toggle-text').textContent = isExpanded
                ? collapsedText
                : expandedText;
        });

        container.appendChild(toggleButton);
    }

    // Toggle worksite list expansion
    function initWorksiteToggle() {
        const showAllBtn = document.querySelector('.sf-worksite-show-all');
        if (!showAllBtn) return;

        showAllBtn.addEventListener('click', function (e) {
            e.preventDefault();

            const hiddenItems = document.querySelectorAll('.sf-worksite-hidden');
            const isExpanded = this.classList.contains('sf-expanded');

            if (isExpanded) {
                // Collapse
                hiddenItems.forEach(item => {
                    item.style.display = 'none';
                });
                this.classList.remove('sf-expanded');
                this.querySelector('.sf-toggle-text').textContent = this.dataset.showText;
                this.querySelector('.sf-toggle-icon').textContent = '▼';
            } else {
                // Expand
                hiddenItems.forEach(item => {
                    item.style.display = 'flex';
                });
                this.classList.add('sf-expanded');
                this.querySelector('.sf-toggle-text').textContent = this.dataset.hideText;
                this.querySelector('.sf-toggle-icon').textContent = '▲';
            }
        });
    }

    // -------------------------------------------------------
    // Injury Heatmap
    // -------------------------------------------------------

    /** Return the currently selected worksite from the injury site dropdown */
    function getSiteFilterValue() {
        var dashboardSite = document.getElementById('sf-dashboard-site-filter');

        if (dashboardSite) {
            return dashboardSite.value || '';
        }

        var injurySite = document.getElementById('sf-injury-site-filter');

        return injurySite ? injurySite.value : '';
    }

    /** Fetch injury heatmap data from the API */
    function fetchInjuryData(params) {
        var card = document.getElementById('sf-injury-card');

        if (card) {
            card.classList.add('sf-injury-card--loading');
            card.setAttribute('aria-busy', 'true');
        }

        var qp = new URLSearchParams();
        if (params.period) qp.set('period', params.period);
        if (params.month)  qp.set('month',  params.month);
        if (params.year)   qp.set('year',   params.year);
        if (params.site)   qp.set('site',   params.site);

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
        fetch(baseUrl + '/app/api/injury-heatmap.php?' + qp.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                injuryData = data;
                applyHeatmap(data.bodyPartCounts);
                renderInjuryChart(data.bodyPartCounts);
                renderInjuryList(data.recentFlashes, activeBpFilter);
                updateSiteDropdown(data.sites);

                var modal = document.getElementById('sf-injury-modal');
                if (modal && modal.style.display !== 'none') {
                    renderModalList(data.recentFlashes, activeModalBpFilter);
                }
            })
            .catch(function (error) {
                console.error('Failed to fetch injury heatmap data:', error);
            })
            .finally(function () {
                if (card) {
                    card.classList.remove('sf-injury-card--loading');
                    card.setAttribute('aria-busy', 'false');
                }
            });
    }

    /** Keep the worksite dropdown options current (add new sites, don't remove existing) */
    function updateSiteDropdown(sites) {
        var sel = document.getElementById('sf-injury-site-filter');
        if (!sel || !sites) return;
        var existing = Array.from(sel.options).map(function (o) { return o.value; });
        sites.forEach(function (site) {
            if (!existing.includes(site)) {
                var opt = document.createElement('option');
                opt.value       = site;
                opt.textContent = site;
                sel.appendChild(opt);
            }
        });
    }

    /**
     * Returns the CSS fill colour for a heatmap body part.
     * intensity: 0–1 (count / maxCount)
     */
    function getHeatmapColor(intensity, count) {
        if (count === 0) return '#e5e7eb';
        if (intensity <= 0.25) return '#fde68a';
        if (intensity <= 0.5)  return '#fca5a5';
        if (intensity <= 0.75) return '#f87171';
        return '#dc2626';
    }

    /** Apply heatmap colours to both SVG figures (main dashboard + modal) */
    function applyHeatmap(bodyPartCounts) {
        if (!bodyPartCounts) return;
        var maxCount = bodyPartCounts.reduce(function (m, bp) { return Math.max(m, bp.count); }, 1);

        ['sf-heatmap-svg-front', 'sf-heatmap-svg-back',
         'sf-modal-heatmap-svg-front', 'sf-modal-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            // Reset all parts to default first
            svgEl.querySelectorAll('[id^="bp-"]').forEach(function (el) {
                el.style.fill = '#e5e7eb';
            });

            // Apply counts
            bodyPartCounts.forEach(function (bp) {
                // Front SVG uses exact id; back SVG uses id + '-back'
                var id = (svgId === 'sf-heatmap-svg-back') ? bp.svg_id + '-back' : bp.svg_id;
                // Also try the exact id for back SVG (some parts like upper-back only exist in back SVG)
                var el = svgEl.querySelector('#' + CSS.escape(id))
                      || svgEl.querySelector('#' + CSS.escape(bp.svg_id));
                if (el) {
                    el.style.fill = getHeatmapColor(bp.count / maxCount, bp.count);
                }
            });
        });
    }

    /** Render horizontal bar chart for body-part categories */
    function renderInjuryChart(bodyPartCounts) {
        var container = document.getElementById('sf-injury-chart');
        if (!container || !bodyPartCounts) return;

        // Group by category
        var categories = {};
        bodyPartCounts.forEach(function (bp) {
            if (!categories[bp.category]) categories[bp.category] = 0;
            categories[bp.category] += bp.count;
        });

        var maxCat = Object.values(categories).reduce(function (m, v) { return Math.max(m, v); }, 1);

        container.innerHTML = '';
        Object.entries(categories).forEach(function (entry) {
            var cat   = entry[0];
            var count = entry[1];
            var barWidth = Math.round((count / maxCat) * 100);

            var barClass = count === 0
                ? 'sf-injury-chart-bar sf-injury-chart-bar--zero'
                : 'sf-injury-chart-bar';
            var row = document.createElement('div');
            row.className = 'sf-injury-chart-row';
            row.innerHTML =
                '<span class="sf-injury-chart-label' + (count === 0 ? ' sf-injury-chart-label--zero' : '') + '">' + escapeHtml(cat) + '</span>' +
                '<div class="sf-injury-chart-bar-wrap">' +
                    '<div class="' + barClass + '" style="--bar-width: ' + barWidth + '%;">' +
                        '<span class="sf-injury-chart-count">' + count + '</span>' +
                    '</div>' +
                '</div>';
            container.appendChild(row);
        });
    }

    /** Render (or re-render) the dashboard flash list, limited to 4 items */
    function renderInjuryList(recentFlashes, filterBpId) {
        var container = document.getElementById('sf-injury-flash-list');
        if (!container) return;

        container.innerHTML = '';

        var allFiltered = filterBpId
            ? (recentFlashes || []).filter(function (f) {
                return f.body_parts && f.body_parts.indexOf(filterBpId) !== -1;
              })
            : (recentFlashes || []);

        // Show-all button
        var showAllBtn = document.getElementById('sf-injury-show-all-btn');
        var showAllText = showAllBtn ? showAllBtn.querySelector('.sf-injury-show-all-text') : null;
        if (showAllBtn) {
            if (allFiltered.length > DASHBOARD_MAX_ITEMS) {
                var tpl = (I18N.showAllCount || 'Show all {n}');
                if (showAllText) showAllText.textContent = tpl.replace('{n}', allFiltered.length);
                showAllBtn.style.display = '';
            } else {
                showAllBtn.style.display = 'none';
            }
        }

        var list = allFiltered.slice(0, DASHBOARD_MAX_ITEMS);

        if (list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'sf-pending-empty';
            empty.innerHTML = '<span>' + escapeHtml(filterBpId ? I18N.noMatch : I18N.empty) + '</span>';
            container.appendChild(empty);
            return;
        }

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        list.forEach(function (flash) {
            var item       = document.createElement('a');
            item.href      = baseUrl + '/index.php?page=view&id=' + encodeURIComponent(flash.id);
item.className = 'sf-recent-compact-item sf-injury-flash-item';
item.dataset.flashType = flash.type || '';
item.dataset.sfAnalyticsClick = 'dashboard_injury_flash_open';
item.dataset.sfAnalyticsSource = 'dashboard_injury_recent';
item.dataset.sfAnalyticsTargetType = 'flash';
item.dataset.sfAnalyticsTargetId = String(flash.id || '');

            var dateStr = flash.updated_at ? formatDate(flash.updated_at) : '';

            item.innerHTML =
                '<span class="sf-type-dot sf-type-dot--' + escapeHtml(flash.type) + '"></span>' +
                '<div class="sf-recent-compact-content">' +
                    '<div class="sf-recent-compact-title">' + escapeHtml(flash.title) + '</div>' +
                    '<div class="sf-recent-compact-meta">' +
                        (flash.site ? '<span>' + escapeHtml(flash.site) + '</span><span>·</span>' : '') +
                        '<span class="sf-recent-compact-time">' + escapeHtml(dateStr) + '</span>' +
                    '</div>' +
                '</div>';

            container.appendChild(item);
        });
    }

    /** Render (or re-render) the modal flash list (all items) */
    function renderModalList(recentFlashes, filterBpId) {
        var container = document.getElementById('sf-injury-modal-flash-list');
        if (!container) return;

        container.innerHTML = '';

        var list = filterBpId
            ? (recentFlashes || []).filter(function (f) {
                return f.body_parts && f.body_parts.indexOf(filterBpId) !== -1;
              })
            : (recentFlashes || []);

        if (list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'sf-pending-empty';
            empty.innerHTML = '<span>' + escapeHtml(filterBpId ? I18N.noMatch : I18N.empty) + '</span>';
            container.appendChild(empty);
            return;
        }

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        list.forEach(function (flash) {
            var item       = document.createElement('a');
            item.href      = baseUrl + '/index.php?page=view&id=' + encodeURIComponent(flash.id);
            item.className = 'sf-recent-compact-item sf-injury-flash-item';
            item.dataset.flashType = flash.type || '';

            var dateStr = flash.updated_at ? formatDate(flash.updated_at) : '';

            item.innerHTML =
                '<span class="sf-type-dot sf-type-dot--' + escapeHtml(flash.type) + '"></span>' +
                '<div class="sf-recent-compact-content">' +
                    '<div class="sf-recent-compact-title">' + escapeHtml(flash.title) + '</div>' +
                    '<div class="sf-recent-compact-meta">' +
                        (flash.site ? '<span>' + escapeHtml(flash.site) + '</span><span>·</span>' : '') +
                        '<span class="sf-recent-compact-time">' + escapeHtml(dateStr) + '</span>' +
                    '</div>' +
                '</div>';

            container.appendChild(item);
        });
    }

    /** Highlight a single body part across both SVG figures (main or modal) */
    function highlightBp(partId, svgPrefix) {
        var prefix = svgPrefix || 'sf-heatmap-svg';
        [prefix + '-front', prefix + '-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.querySelectorAll('.sf-bp-active').forEach(function (el) {
                el.classList.remove('sf-bp-active');
            });

            // Try canonical id and back-suffixed id
            [partId, partId + '-back'].forEach(function (id) {
                var el = svgEl.querySelector('#' + CSS.escape(id));
                if (el) el.classList.add('sf-bp-active');
            });
        });
    }

    /** Remove all active highlights (main or modal) */
    function clearBpHighlight(svgPrefix) {
        var prefix = svgPrefix || 'sf-heatmap-svg';
        document.querySelectorAll(
            '#' + prefix + '-front .sf-bp-active, #' + prefix + '-back .sf-bp-active'
        ).forEach(function (el) { el.classList.remove('sf-bp-active'); });
    }

    /** Update the active-filter badge (main or modal) */
    function updateActiveFilterBadge(partId, badgeId) {
        var badge = document.getElementById(badgeId || 'sf-injury-active-filter');
        if (!badge) return;

        if (!partId || !injuryData) {
            badge.style.display = 'none';
            badge.textContent   = '';
            return;
        }

        var bp   = (injuryData.bodyPartCounts || []).find(function (b) { return b.svg_id === partId; });
        var name = bp ? bp.name : partId;
        badge.textContent = I18N.activeFilter + ' ' + name;
        badge.style.display = 'inline';
    }

    /** Normalise a body-part element id to the canonical (front) id */
    function canonicalBpId(rawId) {
        return rawId.endsWith('-back') ? rawId.slice(0, -5) : rawId;
    }

    /** Initialise injury heatmap interactions */
    function initInjuryHeatmap() {
        // Bootstrap with server-side data
        if (typeof window.SF_INJURY_INITIAL_DATA === 'object' && window.SF_INJURY_INITIAL_DATA) {
            injuryData = window.SF_INJURY_INITIAL_DATA;
            applyHeatmap(injuryData.bodyPartCounts);
            renderInjuryChart(injuryData.bodyPartCounts);
            renderInjuryList(injuryData.recentFlashes, null);
        }

        // SVG click handlers (both front and back) – main dashboard
        ['sf-heatmap-svg-front', 'sf-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.addEventListener('click', function (e) {
                var target = e.target.closest('[id^="bp-"]');

                if (!target) {
                    // Click on empty area – clear filter
                    if (activeBpFilter) {
                        activeBpFilter = null;
                        clearBpHighlight('sf-heatmap-svg');
                        renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                        updateActiveFilterBadge(null, 'sf-injury-active-filter');
                    }
                    return;
                }

                var partId = canonicalBpId(target.id);

                if (activeBpFilter === partId) {
                    // Toggle off
                    activeBpFilter = null;
                    clearBpHighlight('sf-heatmap-svg');
                    renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                    updateActiveFilterBadge(null, 'sf-injury-active-filter');
                } else {
                    activeBpFilter = partId;
                    highlightBp(partId, 'sf-heatmap-svg');
                    renderInjuryList(injuryData ? injuryData.recentFlashes : [], partId);
                    updateActiveFilterBadge(partId, 'sf-injury-active-filter');
                }
            });
        });

        // Click on the active-filter badge clears the filter
        var badge = document.getElementById('sf-injury-active-filter');
        if (badge) {
            badge.addEventListener('click', function () {
                activeBpFilter = null;
                clearBpHighlight('sf-heatmap-svg');
                renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                updateActiveFilterBadge(null, 'sf-injury-active-filter');
            });
        }

        // Worksite dropdown change
        var siteFilter = document.getElementById('sf-injury-site-filter');
        if (siteFilter) {
            siteFilter.addEventListener('change', function () {
                fetchInjuryData(Object.assign({}, currentTimeParams, { site: this.value }));
            });
        }

        // Show-all button opens modal
        var showAllBtn = document.getElementById('sf-injury-show-all-btn');
        if (showAllBtn) {
            showAllBtn.addEventListener('click', function () {
                openInjuryModal();
            });
        }

        initInjuryModal();
    }

    /** Open the injury modal */
    function openInjuryModal() {
        var modal = document.getElementById('sf-injury-modal');
        if (!modal) return;

        // Reset modal filter
        activeModalBpFilter = null;
        clearBpHighlight('sf-modal-heatmap-svg');
        updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');

        // Apply heatmap to modal SVGs
        if (injuryData) {
            applyHeatmap(injuryData.bodyPartCounts);
            renderModalList(injuryData.recentFlashes, null);
        }

        modal.style.display = 'flex';
        document.body.classList.add('sf-modal-open');

        // Brief delay so the modal is visible before focus moves (avoids layout-shift artefacts)
        var closeBtn = document.getElementById('sf-injury-modal-close');
        if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 50);
    }

    /** Close the injury modal */
    function closeInjuryModal() {
        var modal = document.getElementById('sf-injury-modal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.classList.remove('sf-modal-open');
        activeModalBpFilter = null;
    }

    /** Initialise modal interactions */
    function initInjuryModal() {
        var closeBtn  = document.getElementById('sf-injury-modal-close');
        var backdrop  = document.getElementById('sf-injury-modal-backdrop');
        var modalBadge = document.getElementById('sf-injury-modal-active-filter');

        if (closeBtn) {
            closeBtn.addEventListener('click', closeInjuryModal);
        }
        if (backdrop) {
            backdrop.addEventListener('click', closeInjuryModal);
        }

        // Escape key closes modal (use a named function so it can be checked)
        if (!document._sfInjuryModalEscBound) {
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeInjuryModal();
            });
            document._sfInjuryModalEscBound = true;
        }

        // Modal SVG click handlers
        ['sf-modal-heatmap-svg-front', 'sf-modal-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.addEventListener('click', function (e) {
                var target = e.target.closest('[id^="bp-"]');

                if (!target) {
                    if (activeModalBpFilter) {
                        activeModalBpFilter = null;
                        clearBpHighlight('sf-modal-heatmap-svg');
                        renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                        updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
                    }
                    return;
                }

                var partId = canonicalBpId(target.id);

                if (activeModalBpFilter === partId) {
                    activeModalBpFilter = null;
                    clearBpHighlight('sf-modal-heatmap-svg');
                    renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                    updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
                } else {
                    activeModalBpFilter = partId;
                    highlightBp(partId, 'sf-modal-heatmap-svg');
                    renderModalList(injuryData ? injuryData.recentFlashes : [], partId);
                    updateActiveFilterBadge(partId, 'sf-injury-modal-active-filter');
                }
            });
        });

        // Modal active-filter badge clears modal filter
        if (modalBadge) {
            modalBadge.addEventListener('click', function () {
                activeModalBpFilter = null;
                clearBpHighlight('sf-modal-heatmap-svg');
                renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
            });
        }
    }

    // -------------------------------------------------------
    // Format a date string as DD.MM.YYYY
    // -------------------------------------------------------
    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        var day   = String(d.getDate()).padStart(2, '0');
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var year  = d.getFullYear();
        return day + '.' + month + '.' + year;
    }

    // -------------------------------------------------------
    // Simple JS time-ago (fallback for dynamically rendered items)
    // -------------------------------------------------------
    function jsTimeAgo(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        var diffMs   = Date.now() - d.getTime();
        var diffDays = Math.floor(diffMs / 86400000);
        if (diffDays === 0) return I18N.today     || '';
        if (diffDays === 1) return I18N.yesterday || '';
        if (diffDays < 30) {
            var tpl = I18N.daysAgo || '{n}';
            return tpl.replace('{n}', diffDays);
        }
        var months = Math.floor(diffDays / 30);
        if (months < 12) return months + ' kk';
        return Math.floor(months / 12) + ' v';
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    function initDashboardFilterToggle() {
        const toggle = document.querySelector('.sf-dashboard-filter-toggle');
        const content = document.getElementById('sf-dashboard-filter-content');

        if (!toggle || !content) return;

        toggle.addEventListener('click', function () {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';

            toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            content.classList.toggle('sf-dashboard-filter-row--open', !isOpen);
        });
    }

    function updateDashboardSitePills() {
        const label = getSelectedDashboardSiteLabel();
        const pills = document.querySelectorAll('[data-dashboard-site-pill="1"]');

        pills.forEach(function (pill) {
            pill.textContent = label;
            pill.setAttribute('title', label);
        });
    }
	
    function initDashboardModuleCopyButtons() {
        const modules = document.querySelectorAll('[data-dashboard-copy-module="1"]');

        modules.forEach(function (module) {
            if (module.dataset.copyButtonReady === '1') return;

            module.dataset.copyButtonReady = '1';
            module.classList.add('sf-dashboard-copyable-module');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sf-dashboard-copy-btn';
            button.innerHTML = `
                <span class="sf-dashboard-copy-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M8 8.75C8 7.78 8.78 7 9.75 7h8.5C19.22 7 20 7.78 20 8.75v8.5c0 .97-.78 1.75-1.75 1.75h-8.5C8.78 19 8 18.22 8 17.25v-8.5Z"></path>
                        <path d="M4 5.75C4 4.78 4.78 4 5.75 4h8.5C15.22 4 16 4.78 16 5.75V6h-1.5v-.25a.25.25 0 0 0-.25-.25h-8.5a.25.25 0 0 0-.25.25v8.5c0 .14.11.25.25.25H6V16h-.25C4.78 16 4 15.22 4 14.25v-8.5Z"></path>
                    </svg>
                </span>
                <span>${escapeHtml(I18N.copyModule || 'Kopioi')}</span>
            `;

            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                copyDashboardModuleAsImage(module, button);
            });

            const header = module.querySelector('.sf-section-header');

            if (header) {
                header.appendChild(button);
            } else {
                module.appendChild(button);
            }
        });
    }

    function ensureHtml2CanvasLoaded() {
        if (typeof window.html2canvas === 'function') {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const existingScript = document.querySelector('script[data-html2canvas-loader="1"]');

            if (existingScript) {
                existingScript.addEventListener('load', resolve, { once: true });
                existingScript.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

            script.src = baseUrl + '/assets/js/vendor/html2canvas.min.js';
            script.async = true;
            script.dataset.html2canvasLoader = '1';
            script.onload = resolve;
            script.onerror = reject;

            document.head.appendChild(script);
        });
    }

function trackDashboardCopyAnalytics(module, result, errorMessage) {
    if (!window.SafetyFlashAnalytics || typeof window.SafetyFlashAnalytics.track !== 'function') {
        return;
    }

    const moduleKey = module.getAttribute('data-dashboard-module-key') || '';
    const titleElement = module.querySelector('.sf-section-title');
    const moduleTitle = titleElement ? (titleElement.textContent || '').trim().slice(0, 120) : '';

    window.SafetyFlashAnalytics.track({
        event_type: result === 'success' ? 'dashboard_module_copy_image' : 'dashboard_module_copy_image_failed',
        target_type: 'dashboard_module',
        metadata: {
            module_key: moduleKey,
            module_title: moduleTitle,
            result: result,
            error_message: errorMessage || ''
        }
    });
}

function copyDashboardModuleAsImage(module, button) {
    const originalButtonText = button.innerHTML;

        button.disabled = true;
        button.classList.add('sf-dashboard-copy-btn--loading');
        module.classList.add('sf-dashboard-copying');

        ensureHtml2CanvasLoaded()
            .then(function () {
                return new Promise(function (resolve) {
                    window.requestAnimationFrame(function () {
                        resolve();
                    });
                });
            })
            .then(function () {
                return window.html2canvas(module, {
                    backgroundColor: '#ffffff',
                    scale: Math.min(2, window.devicePixelRatio || 1),
                    useCORS: true,
                    allowTaint: true,
                    logging: false
                });
            })
            .then(function (canvas) {
                return new Promise(function (resolve, reject) {
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            reject(new Error('Canvas export failed.'));
                            return;
                        }

                        resolve(blob);
                    }, 'image/png');
                });
            })
            .then(function (blob) {
                if (!navigator.clipboard || typeof ClipboardItem === 'undefined') {
                    throw new Error('Clipboard image copy is not supported.');
                }

                return navigator.clipboard.write([
                    new ClipboardItem({
                        'image/png': blob
                    })
                ]);
            })
.then(function () {
    trackDashboardCopyAnalytics(module, 'success', '');
    showDashboardCopyToast(I18N.copySuccess || 'Moduulin kuva kopioitu leikepöydälle.');
})
.catch(function (error) {
    console.error('Dashboard module copy failed:', error);
    trackDashboardCopyAnalytics(module, 'failed', error && error.message ? error.message : '');
    showDashboardCopyToast(I18N.copyError || 'Kuvan kopiointi ei onnistunut.', true);
})
            .finally(function () {
                module.classList.remove('sf-dashboard-copying');
                button.disabled = false;
                button.classList.remove('sf-dashboard-copy-btn--loading');
                button.innerHTML = originalButtonText;
            });
    }

    function showDashboardCopyToast(message, isError) {
        let toast = document.querySelector('.sf-dashboard-copy-toast');

        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'sf-dashboard-copy-toast';
            document.body.appendChild(toast);
        }

        toast.classList.toggle('sf-dashboard-copy-toast--error', isError === true);
        toast.textContent = message;
        toast.classList.add('sf-dashboard-copy-toast--visible');

        window.clearTimeout(toast._sfHideTimer);
        toast._sfHideTimer = window.setTimeout(function () {
            toast.classList.remove('sf-dashboard-copy-toast--visible');
        }, 2800);
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initDashboardFilterToggle();
            initTimeFilter();
            initWorksiteToggle();
            initInjuryHeatmap();
            initDashboardModuleCopyButtons();
        });
    } else {
        initDashboardFilterToggle();
        initTimeFilter();
        initWorksiteToggle();
        initInjuryHeatmap();
        initDashboardModuleCopyButtons();
    }
})();