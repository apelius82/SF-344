// assets/js/list-views.js
// View mode switching (Grid / List / Compact) for list page with FAB

(function () {
    'use strict';

    const STORAGE_KEY = 'sf_list_view';
    const SCROLL_POSITION_KEY = 'sf_list_scroll_position';
    const DEFAULT_VIEW = 'list';
    const VALID_VIEWS = ['grid', 'list', 'compact'];
    const MOBILE_BREAKPOINT = 768; // px - matches CSS breakpoint

    // View icon SVGs
    const VIEW_ICONS = {
        grid: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sf-view-fab-icon" data-view="grid"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>',
        list: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sf-view-fab-icon" data-view="list"><rect x="3" y="3" width="18" height="6" rx="1"></rect><rect x="3" y="11" width="18" height="6" rx="1"></rect></svg>',
        compact: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sf-view-fab-icon" data-view="compact"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>'
    };

    function safeGetItem(key) {
        try {
            return window.localStorage ? localStorage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function safeSetItem(key, value) {
        try {
            if (window.localStorage) localStorage.setItem(key, value);
        } catch (e) {
            // Ignore storage failures (private mode / blocked storage)
        }
    }

    function safeRemoveItem(key) {
        try {
            if (window.localStorage) localStorage.removeItem(key);
        } catch (e) {
            // Ignore storage failures
        }
    }

    // Get saved view or default
    function getSavedView() {
        const saved = safeGetItem(STORAGE_KEY);
        return VALID_VIEWS.includes(saved) ? saved : DEFAULT_VIEW;
    }

    // Save view preference
    function saveView(view) {
        if (VALID_VIEWS.includes(view)) {
            safeSetItem(STORAGE_KEY, view);
        }
    }

    // Update FAB icon and hide current view from options
    function updateFabIcon(view) {
        const fab = document.getElementById('sfViewFab');
        if (!fab) return;

        // Update main FAB icon
        if (VIEW_ICONS[view]) {
            fab.innerHTML = VIEW_ICONS[view];
        }

        // Hide current view from options
        const options = document.querySelectorAll('.sf-view-fab-option');
        options.forEach(option => {
            if (option.dataset.view === view) {
                option.classList.add('current-view');
            } else {
                option.classList.remove('current-view');
            }
        });
    }

    // Apply view to container
    function applyView(view) {
        const container = document.querySelector('.sf-list-container');
        if (!container) return;

        // Remove all view classes
        VALID_VIEWS.forEach(v => container.classList.remove(`view-${v}`));

        // Add current view class
        container.classList.add(`view-${view}`);

        // Update FAB
        updateFabIcon(view);

        // Update old toggle buttons (if they exist for backwards compatibility)
        const toggle = document.getElementById('sfViewToggle');
        if (toggle) {
            toggle.querySelectorAll('button').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.view === view);
            });
        }
    }

    // Close speed dial
    function closeSpeedDial() {
        const container = document.getElementById('sfViewFabContainer');
        const fab = document.getElementById('sfViewFab');
        if (container) {
            container.classList.remove('open');
        }
        if (fab) {
            fab.setAttribute('aria-expanded', 'false');
        }
    }

    // Open speed dial
    function openSpeedDial() {
        const container = document.getElementById('sfViewFabContainer');
        const fab = document.getElementById('sfViewFab');
        if (container) {
            container.classList.add('open');
        }
        if (fab) {
            fab.setAttribute('aria-expanded', 'true');
        }
    }

    // Toggle speed dial
    function toggleSpeedDial() {
        const container = document.getElementById('sfViewFabContainer');
        if (container && container.classList.contains('open')) {
            closeSpeedDial();
        } else {
            openSpeedDial();
        }
    }

    // Setup FAB interactions
    function setupFab() {
        const fab = document.getElementById('sfViewFab');
        const backdrop = document.getElementById('sfViewFabBackdrop');
        const options = document.querySelectorAll('.sf-view-fab-option');

        // Flag to prevent double-firing (touchend + click)
        let touchHandled = false;

        function handleFabActivation(e) {
            e.preventDefault();
            e.stopPropagation();

            if (e.type === 'touchend') {
                touchHandled = true;
                setTimeout(() => { touchHandled = false; }, 300);
                toggleSpeedDial();
            } else if (e.type === 'click' && !touchHandled) {
                toggleSpeedDial();
            }
        }

        // Main FAB click/touch - toggle speed dial
        if (fab) {
            fab.addEventListener('touchend', handleFabActivation, { passive: false });
            fab.addEventListener('click', handleFabActivation);
        }

        // Backdrop click/touch - close speed dial
        if (backdrop) {
            let backdropTouchHandled = false;

            function handleBackdrop(e) {
                e.preventDefault();
                e.stopPropagation();
                if (e.type === 'touchend') {
                    backdropTouchHandled = true;
                    setTimeout(() => { backdropTouchHandled = false; }, 300);
                    closeSpeedDial();
                } else if (e.type === 'click' && !backdropTouchHandled) {
                    closeSpeedDial();
                }
            }

            backdrop.addEventListener('touchend', handleBackdrop, { passive: false });
            backdrop.addEventListener('click', handleBackdrop);
        }

        // Option clicks/touches - change view
        options.forEach(option => {
            let optionTouchHandled = false;

            function handleOption(e) {
                e.preventDefault();
                e.stopPropagation();

                if (e.type === 'touchend') {
                    optionTouchHandled = true;
                    setTimeout(() => { optionTouchHandled = false; }, 300);
                } else if (e.type === 'click' && optionTouchHandled) {
                    return;
                }

                const view = option.dataset.view;
                if (view && VALID_VIEWS.includes(view)) {
                    saveView(view);
                    applyView(view);
                    closeSpeedDial();
                }
            }

            option.addEventListener('touchend', handleOption, { passive: false });
            option.addEventListener('click', handleOption);
        });

        // Close speed dial on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const container = document.getElementById('sfViewFabContainer');
                if (container && container.classList.contains('open')) {
                    closeSpeedDial();
                }
            }
        });
    }

    // Scroll restoration
    function saveScrollPosition() {
        const scrollY = window.scrollY || window.pageYOffset;
        safeSetItem(SCROLL_POSITION_KEY, scrollY.toString());
    }

    function restoreScrollPosition() {
        const savedPosition = safeGetItem(SCROLL_POSITION_KEY);
        if (savedPosition !== null) {
            const position = parseInt(savedPosition, 10);
            if (!isNaN(position)) {
                // Use requestAnimationFrame to ensure DOM is ready
                requestAnimationFrame(() => {
                    window.scrollTo({
                        top: position,
                        behavior: 'instant' // Instant scroll, no animation
                    });
                });
            }
            // Clear the saved position after restoration
            safeRemoveItem(SCROLL_POSITION_KEY);
        }
    }

    // Save scroll position when user clicks on a card
    function setupScrollSaving() {
        // Save scroll position when clicking links to view page or card elements
        document.addEventListener('click', (e) => {
            // Check for view page links or card clicks that navigate
            const link = e.target.closest('a[href*="page=view"]');
            const card = e.target.closest('.card');
            const openBtn = e.target.closest('.open-btn');

            if (link || openBtn || (card && !e.target.closest('.sf-copy-btn, input[type="checkbox"]'))) {
                saveScrollPosition();
            }
        });

        // Also save on any navigation away from the page
        window.addEventListener('beforeunload', saveScrollPosition);
    }

    // Initialize
    function init() {
        const currentView = getSavedView();
        applyView(currentView);

        // Setup FAB interactions (with retry for late-rendered elements)
        const fab = document.getElementById('sfViewFab');
        if (fab) {
            setupFab();
        } else {
            // FAB might not be in DOM yet, retry after short delay
            setTimeout(() => {
                setupFab();
            }, 100);
        }

        // Setup scroll restoration
        restoreScrollPosition();
        setupScrollSaving();

        // Add click handlers to old toggle buttons (backwards compatibility)
        const toggle = document.getElementById('sfViewToggle');
        if (toggle) {
            toggle.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-view]');
                if (!btn) return;

                const view = btn.dataset.view;
                saveView(view);
                applyView(view);
            });
        }

        // Re-apply view on window resize (to handle mobile breakpoint)
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                applyView(getSavedView());
            }, 250);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();