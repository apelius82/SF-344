// assets/js/mobile.js
(function () {
    'use strict';

    function checkOrientation() {
        const warning = document.getElementById('sfRotateWarning');
        if (!warning) {
            return;
        }

        const isLandscape = window.innerWidth > window.innerHeight;
        const isMobile = window.innerWidth <= 900 || window.innerHeight <= 500;

        warning.style.display = (isLandscape && isMobile) ? 'flex' : 'none';
    }

    function setVH() {
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', vh + 'px');
    }

    function setMobileLayoutVars() {
        const root = document.documentElement;
        const isMobileLayout = window.innerWidth <= 1100;

        if (!isMobileLayout) {
            root.style.setProperty('--sf-mobile-header-height', '0px');
            root.style.setProperty('--sf-mobile-bottom-nav-height', '0px');
            root.style.setProperty('--sf-mobile-top-offset', '0px');
            root.style.setProperty('--sf-mobile-bottom-offset', '0px');
            return;
        }

        const nav = document.querySelector('.sf-nav');
        const bottomNav = document.querySelector('.sf-bottom-nav');

        const headerHeight = nav ? Math.ceil(nav.getBoundingClientRect().height) : 64;
        const bottomNavVisible = !!(bottomNav && window.getComputedStyle(bottomNav).display !== 'none');
        const bottomNavHeight = bottomNavVisible ? Math.ceil(bottomNav.getBoundingClientRect().height) : 0;

        root.style.setProperty('--sf-mobile-header-height', headerHeight + 'px');
        root.style.setProperty('--sf-mobile-bottom-nav-height', bottomNavHeight + 'px');
        root.style.setProperty('--sf-mobile-top-offset', 'calc(var(--sf-mobile-header-height) + var(--sf-mobile-safe-top))');
        root.style.setProperty('--sf-mobile-bottom-offset', 'calc(var(--sf-mobile-bottom-nav-height) + var(--sf-mobile-safe-bottom) + 20px)');
    }

    function updateMobileUI() {
        setVH();
        setMobileLayoutVars();
        checkOrientation();
    }

    window.addEventListener('resize', function () {
        updateMobileUI();
    });

    window.addEventListener('orientationchange', function () {
        window.setTimeout(function () {
            updateMobileUI();
        }, 100);
    });

    window.addEventListener('load', function () {
        updateMobileUI();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            updateMobileUI();
        });
    } else {
        updateMobileUI();
    }
})();