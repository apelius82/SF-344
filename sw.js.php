<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/javascript');
header('Cache-Control: no-cache');

$base = rtrim($config['base_url'], '/');
?>
const APP_VERSION = '2.6.2';
const CACHE_NAME = `safetyflash-v${APP_VERSION}`;
const BASE_URL = '<?= $base ?>';

const STATIC_ASSETS = [
    '<?= $base ?>/offline.html',
    '<?= $base ?>/assets/css/global.css',
    '<?= $base ?>/assets/css/nav.css',
    '<?= $base ?>/assets/css/list.css',
    '<?= $base ?>/assets/css/modals.css',
    '<?= $base ?>/assets/js/mobile.js',
    '<?= $base ?>/assets/js/vendor/html2canvas.min.js',
    '<?= $base ?>/assets/fonts/OpenSans-Light.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-Regular.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-SemiBold.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-Bold.woff2',
    '<?= $base ?>/assets/img/icons/pwa-icon-192.png',
    '<?= $base ?>/assets/img/icons/pwa-icon-512.png',
    '<?= $base ?>/assets/img/icons/list_icon.png',
    '<?= $base ?>/assets/img/icons/add_new_icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return Promise.all(
                    STATIC_ASSETS.map(url => {
                        return cache.add(url).catch(err => {
                            console.warn('Failed to cache:', url, err);
                        });
                    })
                );
            })
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key.startsWith('safetyflash-') && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => {
            return self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'SW_UPDATED', version: APP_VERSION });
                });
            });
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes(BASE_URL + '/app/api/')) return;

    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.ok) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request)
                    .then(cachedResponse => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }

                        if (event.request.mode === 'navigate') {
                            return caches.match('<?= $base ?>/offline.html');
                        }

                        return new Response('Offline - no cached version available', {
                            status: 503,
                            statusText: 'Service Unavailable',
                            headers: new Headers({
                                'Content-Type': 'text/plain'
                            })
                        });
                    });
            })
    );
});

self.addEventListener('push', event => {
    let data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (error) {
            data = {
                title: 'SafetyFlash',
                body: event.data.text()
            };
        }
    }

    const targetUrl = data.url || BASE_URL + '/index.php?page=list';

    const options = {
        body: data.body || '',
        icon: data.icon || BASE_URL + '/assets/img/icons/pwa-icon-192.png',
        badge: data.badge || BASE_URL + '/assets/img/icons/pwa-icon-192.png',
        data: {
            url: targetUrl,
            notification_id: data.notification_id || '',
            user_id: data.user_id || null,
            flash_id: data.flash_id || null,
            title: data.title || 'SafetyFlash',
            body: data.body || ''
        },
        requireInteraction: false
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'SafetyFlash', options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    event.waitUntil((async () => {
        const notificationData = event.notification.data || {};

        let targetUrl = notificationData.url
            ? notificationData.url
            : BASE_URL + '/index.php?page=list';

        try {
            const url = new URL(targetUrl, BASE_URL);

            if (!url.searchParams.has('source')) {
                url.searchParams.set('source', 'push');
            }

            if (notificationData.notification_id && !url.searchParams.has('push_id')) {
                url.searchParams.set('push_id', notificationData.notification_id);
            }

            targetUrl = url.toString();
        } catch (error) {}

        const clickPayload = {
            notification_id: notificationData.notification_id || '',
            user_id: notificationData.user_id || null,
            flash_id: notificationData.flash_id || null,
            url: targetUrl,
            title: notificationData.title || 'SafetyFlash',
            body: notificationData.body || ''
        };

        try {
            await fetch(BASE_URL + '/app/api/analytics_push_click.php', {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(clickPayload)
            });
        } catch (error) {}

        const clientList = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        });

        for (const client of clientList) {
            if ('focus' in client) {
                await client.navigate(targetUrl);
                return client.focus();
            }
        }

        if (self.clients.openWindow) {
            return self.clients.openWindow(targetUrl);
        }

        return null;
    })());
});

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({ version: APP_VERSION });
    }
});