// assets/js/push-notifications.js
(function () {
    'use strict';

    function createUnsupportedApi() {
        return {
            supported: false,
            getStatus: async function () {
                return {
                    ok: false,
                    error: 'Push notifications are not supported on this device.'
                };
            },
            requestPermissionAndSubscribe: async function () {
                return {
                    ok: false,
                    error: 'Push notifications are not supported on this device.'
                };
            },
            unsubscribe: async function () {
                return {
                    ok: false,
                    error: 'Push notifications are not supported on this device.'
                };
            }
        };
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        window.SafetyFlashPush = createUnsupportedApi();
        return;
    }

    const baseUrl = window.SF_BASE_URL || '';
    const csrfToken = window.SF_CSRF_TOKEN || '';

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, options || {});
        const data = await response.json().catch(function () {
            return {
                ok: false,
                error: 'Invalid JSON response'
            };
        });

        if (!response.ok) {
            return {
                ok: false,
                error: data.error || 'Request failed'
            };
        }

        return data;
    }

    async function getStatus() {
        return fetchJson(baseUrl + '/app/api/push_status.php', {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            cache: 'no-store'
        });
    }

    async function requestPermissionAndSubscribe() {
        let permission = Notification.permission;

        if (permission !== 'granted') {
            permission = await Notification.requestPermission();
        }

        if (permission !== 'granted') {
            return {
                ok: false,
                permission: permission,
                permissionDenied: permission === 'denied',
                error: permission === 'denied'
                    ? 'Notifications are blocked in browser settings.'
                    : 'Notification permission was not granted'
            };
        }

        const status = await getStatus();

        if (!status.ok || !status.public_key) {
            return {
                ok: false,
                error: status.error || 'Push public key missing'
            };
        }

        const registration = await navigator.serviceWorker.ready;

        const existingSubscription = await registration.pushManager.getSubscription();
        if (existingSubscription) {
            await existingSubscription.unsubscribe();
        }

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(status.public_key)
        });

        const result = await fetchJson(baseUrl + '/app/api/push_subscribe.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify(subscription)
        });

        if (result.ok) {
            window.dispatchEvent(new CustomEvent('sf:push-subscribed'));
        }

        return result;
    }

    async function unsubscribe() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        const endpoint = subscription ? subscription.endpoint : '';

        if (subscription) {
            await subscription.unsubscribe();
        }

        const result = await fetchJson(baseUrl + '/app/api/push_unsubscribe.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify({
                endpoint: endpoint
            })
        });

        if (result.ok) {
            window.dispatchEvent(new CustomEvent('sf:push-unsubscribed'));
        }

        return result;
    }

    async function syncExistingSubscription() {
        if (Notification.permission !== 'granted') {
            return {
                ok: true,
                synced: false
            };
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            return {
                ok: true,
                synced: false
            };
        }

        return fetchJson(baseUrl + '/app/api/push_subscribe.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify(subscription)
        });
    }

    window.SafetyFlashPush = {
        supported: true,
        getStatus: getStatus,
        requestPermissionAndSubscribe: requestPermissionAndSubscribe,
        unsubscribe: unsubscribe,
        syncExistingSubscription: syncExistingSubscription,
        getPermissionState: function () {
            return Notification.permission;
        },
        isPermissionDenied: function () {
            return Notification.permission === 'denied';
        },
        isStandaloneApp: function () {
            return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        }
    };

    window.addEventListener('load', function () {
        syncExistingSubscription().catch(function (error) {
            console.warn('[SafetyFlashPush] subscription sync failed:', error);
        });
    });
})();