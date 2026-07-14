// assets/js/skeleton.js
// Unified skeleton loading management

(function () {
    'use strict';

    /**
     * Hide skeleton with smooth fade-out
     * @param {string} containerId - ID of the skeleton container
     * @param {number} delay - Delay in milliseconds before hiding
     */
    function hideSkeleton(containerId, delay) {
        delay = delay || 200;
        const skeleton = document.getElementById(containerId);
        if (skeleton) {
            setTimeout(function () {
                skeleton.classList.add('loaded');
            }, delay);
        }
    }

    /**
     * Auto-hide skeletons when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function () {
        // List page skeleton
        hideSkeleton('skeletonContainer', 300);

        // Settings/Users table skeleton
        hideSkeleton('skeletonTable', 400);

        // Dashboard skeleton
        hideSkeleton('dashboardSkeletonContainer', 450);
    });

    /**
     * Fallback: Force hide skeleton after 5 seconds if not already hidden
     * This ensures skeleton doesn't get stuck if JavaScript errors occur
     */
    setTimeout(function () {
        var skeleton = document.getElementById('skeletonContainer');
        if (skeleton && !skeleton.classList.contains('loaded')) {
            skeleton.classList.add('loaded');
            console.warn('Skeleton fallback triggered - skeleton was force-hidden after 5s');
        }

        var skeletonTable = document.getElementById('skeletonTable');
        if (skeletonTable && !skeletonTable.classList.contains('loaded')) {
            skeletonTable.classList.add('loaded');
            console.warn('Skeleton table fallback triggered - skeleton was force-hidden after 5s');
        }

        var dashboardSkeleton = document.getElementById('dashboardSkeletonContainer');
        if (dashboardSkeleton && !dashboardSkeleton.classList.contains('loaded')) {
            dashboardSkeleton.classList.add('loaded');
            console.warn('Dashboard skeleton fallback triggered - skeleton was force-hidden after 5s');
        }
    }, 5000);

    // Export for potential external use
    if (typeof window !== 'undefined') {
        window.SF_Skeleton = {
            hide: hideSkeleton
        };
    }
})();