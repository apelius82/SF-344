// PWA Update Handler
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) return;

    let updateBanner = null;
    let networkStatusBanner = null;

    // Listen for Service Worker messages
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data && event.data.type === 'SW_UPDATED') {
            showUpdateBanner();
        }
    });

    // Check for updates when page loads
    navigator.serviceWorker.ready.then((registration) => {
        // Check if new SW is waiting
        if (registration.waiting) {
            showUpdateBanner();
        }

        // Listen for new SW updates
        registration.addEventListener('updatefound', () => {
            const newWorker = registration.installing;
            newWorker.addEventListener('statechange', () => {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                    showUpdateBanner();
                }
            });
        });
    });

    // Check for updates periodically (1 hour intervals)
    // Only set up one interval per page load
    if (!window.__sfUpdateCheckInterval) {
        window.__sfUpdateCheckInterval = setInterval(() => {
            navigator.serviceWorker.ready.then(reg => reg.update());
        }, 60 * 60 * 1000);
    }

    // Network status monitoring
    function initNetworkMonitoring() {
        // Initial status
        if (!navigator.onLine) {
            showNetworkStatusBanner(false);
        }

        // Listen for online/offline events
        window.addEventListener('online', () => {
            showNetworkStatusBanner(true);
            // Hide after 3 seconds when back online
            setTimeout(() => hideNetworkStatusBanner(), 3000);
        });

        window.addEventListener('offline', () => {
            showNetworkStatusBanner(false);
        });
    }

    function showNetworkStatusBanner(isOnline) {
        // Remove existing banner if any
        if (networkStatusBanner) {
            hideNetworkStatusBanner();
        }

        // Get translations
        const lang = document.documentElement.lang || 'fi';
        const i18n = getNetworkI18n(lang);

        // Create banner
        networkStatusBanner = document.createElement('div');
        networkStatusBanner.className = `sf-network-status-banner ${isOnline ? 'sf-network-online' : 'sf-network-offline'}`;
        networkStatusBanner.innerHTML = `
            <div class="sf-network-status-content">
                <span class="sf-network-status-icon">${isOnline ? '✓' : '📡'}</span>
                <span class="sf-network-status-text">${isOnline ? i18n.backOnline : i18n.offline}</span>
            </div>
        `;

        document.body.appendChild(networkStatusBanner);

        // Slide-in animation
        requestAnimationFrame(() => {
            networkStatusBanner.classList.add('sf-network-status-visible');
        });
    }

    function hideNetworkStatusBanner() {
        if (networkStatusBanner) {
            networkStatusBanner.classList.remove('sf-network-status-visible');
            setTimeout(() => {
                if (networkStatusBanner && networkStatusBanner.parentNode) {
                    networkStatusBanner.remove();
                }
                networkStatusBanner = null;
            }, 300);
        }
    }

    function showUpdateBanner() {
        if (updateBanner) return; // Already visible

        // Get translations
        const lang = document.documentElement.lang || 'fi';
        const i18n = getUpdateI18n(lang);

        // Create banner
        updateBanner = document.createElement('div');
        updateBanner.className = 'sf-update-banner';
        updateBanner.innerHTML = `
            <div class="sf-update-content">
                <span class="sf-update-icon">🔄</span>
                <span class="sf-update-text">${i18n.message}</span>
            </div>
            <div class="sf-update-actions">
                <button class="sf-update-btn sf-update-btn--primary" id="sfUpdateNow">
                    ${i18n.updateNow}
                </button>
                <button class="sf-update-btn sf-update-btn--secondary" id="sfUpdateLater">
                    ${i18n.later}
                </button>
            </div>
        `;

        document.body.appendChild(updateBanner);

        // Slide-in animation
        requestAnimationFrame(() => {
            updateBanner.classList.add('sf-update-banner--visible');
        });

        // Event listeners
        document.getElementById('sfUpdateNow').addEventListener('click', doUpdate);
        document.getElementById('sfUpdateLater').addEventListener('click', hideBanner);
    }

    function doUpdate() {
        // Update application
        navigator.serviceWorker.ready.then((registration) => {
            if (registration.waiting) {
                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
        });

        // Reload page
        window.location.reload();
    }

    function hideBanner() {
        if (updateBanner) {
            updateBanner.classList.remove('sf-update-banner--visible');
            setTimeout(() => {
                updateBanner.remove();
                updateBanner = null;
            }, 300);
        }
    }

    function getUpdateI18n(lang) {
        const translations = {
            fi: {
                message: 'Uusi versio saatavilla',
                updateNow: 'Päivitä nyt',
                later: 'Myöhemmin'
            },
            sv: {
                message: 'Ny version tillgänglig',
                updateNow: 'Uppdatera nu',
                later: 'Senare'
            },
            en: {
                message: 'New version available',
                updateNow: 'Update now',
                later: 'Later'
            },
            it: {
                message: 'Nuova versione disponibile',
                updateNow: 'Aggiorna ora',
                later: 'Più tardi'
            },
            el: {
                message: 'Νέα έκδοση διαθέσιμη',
                updateNow: 'Ενημέρωση τώρα',
                later: 'Αργότερα'
            }
        };
        return translations[lang] || translations.fi;
    }

    function getNetworkI18n(lang) {
        const translations = {
            fi: {
                offline: 'Ei verkkoyhteyttä',
                backOnline: 'Yhteys palautunut'
            },
            sv: {
                offline: 'Ingen nätverksanslutning',
                backOnline: 'Anslutning återställd'
            },
            en: {
                offline: 'No network connection',
                backOnline: 'Connection restored'
            },
            it: {
                offline: 'Nessuna connessione di rete',
                backOnline: 'Connessione ripristinata'
            },
            el: {
                offline: 'Χωρίς σύνδεση δικτύου',
                backOnline: 'Η σύνδεση αποκαταστάθηκε'
            }
        };
        return translations[lang] || translations.fi;
    }

    // Initialize network monitoring
    initNetworkMonitoring();
})();