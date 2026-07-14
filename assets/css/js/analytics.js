// assets/js/analytics.js
(function () {
    'use strict';

    var baseUrl = window.SF_BASE_URL || '';
    var csrfToken = window.SF_CSRF_TOKEN || '';
    var bodyPage = document.body ? (document.body.getAttribute('data-page') || '') : '';
    var analyticsContext = window.SF_ANALYTICS_CONTEXT || {};
    var page = analyticsContext.page || bodyPage;

    if (!baseUrl || !csrfToken || !page) {
        return;
    }

    function getSessionId() {
        var key = 'sf_analytics_session_id';
        var existing = sessionStorage.getItem(key);

        if (existing) {
            return existing;
        }

        var value = 'sf_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2);
        sessionStorage.setItem(key, value);
        return value;
    }

    function isPwaMode() {
        return getPwaDisplayMode() === 'standalone' || window.navigator.standalone === true;
    }

    function getPwaDisplayMode() {
        if (window.matchMedia('(display-mode: standalone)').matches) {
            return 'standalone';
        }

        if (window.matchMedia('(display-mode: fullscreen)').matches) {
            return 'fullscreen';
        }

        if (window.matchMedia('(display-mode: minimal-ui)').matches) {
            return 'minimal-ui';
        }

        if (window.navigator.standalone === true) {
            return 'standalone';
        }

        return 'browser';
    }

    function getOperatingSystem() {
        var ua = navigator.userAgent || '';
        var platform = navigator.platform || '';

        if (/android/i.test(ua)) {
            return 'Android';
        }

        if (/iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
            return 'iOS';
        }

        if (/Win/i.test(platform)) {
            return 'Windows';
        }

        if (/Mac/i.test(platform)) {
            return 'macOS';
        }

        if (/Linux/i.test(platform)) {
            return 'Linux';
        }

        return 'Other';
    }

    function getNotificationPermissionState() {
        if (!('Notification' in window)) {
            return 'unsupported';
        }

        return Notification.permission || 'default';
    }

    function isPushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    }

    function isInstallPromptSupported() {
        return 'BeforeInstallPromptEvent' in window || getOperatingSystem() === 'Android';
    }

    function getDeviceType() {
        var width = window.innerWidth || document.documentElement.clientWidth || 0;

        if (width <= 767) {
            return 'mobile';
        }

        if (width <= 1024) {
            return 'tablet';
        }

        return 'desktop';
    }

    function getBrowser() {
        var ua = navigator.userAgent || '';

        if (ua.indexOf('Edg/') !== -1) return 'Edge';
        if (ua.indexOf('Chrome/') !== -1 && ua.indexOf('Chromium') === -1) return 'Chrome';
        if (ua.indexOf('Safari/') !== -1 && ua.indexOf('Chrome/') === -1) return 'Safari';
        if (ua.indexOf('Firefox/') !== -1) return 'Firefox';

        return 'Other';
    }

    function getPlatform() {
        if (navigator.userAgentData && navigator.userAgentData.platform) {
            return navigator.userAgentData.platform;
        }

        return navigator.platform || 'unknown';
    }

    function mergeMetadata(existingMetadata, extraMetadata) {
        var merged = {};
        var key;

        existingMetadata = existingMetadata || {};
        extraMetadata = extraMetadata || {};

        for (key in existingMetadata) {
            if (Object.prototype.hasOwnProperty.call(existingMetadata, key)) {
                merged[key] = existingMetadata[key];
            }
        }

        for (key in extraMetadata) {
            if (Object.prototype.hasOwnProperty.call(extraMetadata, key)) {
                merged[key] = extraMetadata[key];
            }
        }

        return merged;
    }

    function buildPayload(payload) {
        payload = payload || {};

        var metadata = mergeMetadata(payload.metadata || {}, {
            flash_id: analyticsContext.flash_id || null,
            flash_group_id: analyticsContext.flash_group_id || null,
            flash_type: analyticsContext.flash_type || null,
            flash_state: analyticsContext.flash_state || null,
            flash_lang: analyticsContext.flash_lang || null,
            site: analyticsContext.site || null
        });

        return {
            session_id: getSessionId(),
            event_type: payload.event_type || '',
            page: payload.page || page,
            target_type: payload.target_type || analyticsContext.target_type || null,
            target_id: payload.target_id || analyticsContext.target_id || null,
            worksite_id: payload.worksite_id || analyticsContext.worksite_id || null,
            device_type: getDeviceType(),
            platform: getPlatform(),
            browser: getBrowser(),
            is_pwa: isPwaMode() ? 1 : 0,
            metadata: mergeMetadata(metadata, {
                pwa_display_mode: getPwaDisplayMode(),
                operating_system: getOperatingSystem(),
                notification_permission: getNotificationPermissionState(),
                push_supported: isPushSupported() ? 1 : 0
            })
        };
    }

    function sendEvent(payload) {
        var finalPayload = buildPayload(payload);

        if (!finalPayload.event_type) {
            return;
        }

        fetch(baseUrl + '/app/api/analytics_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(finalPayload)
        }).catch(function () {});
    }

    function extractFlashIdFromHref(href) {
        if (!href) {
            return null;
        }

        try {
            var url = new URL(href, window.location.origin);
            var id = url.searchParams.get('id');

            if (id && /^\d+$/.test(id)) {
                return parseInt(id, 10);
            }
        } catch (e) {}

        return null;
    }

    function getNavigationSource() {
        var params = new URLSearchParams(window.location.search);
        var source = params.get('source') || params.get('utm_source') || '';

        if (source) {
            return source;
        }

        if (document.referrer) {
            try {
                var ref = new URL(document.referrer);

                if (ref.origin === window.location.origin) {
                    var refPage = new URLSearchParams(ref.search).get('page');

                    if (refPage) {
                        return refPage;
                    }
                }

                return 'external_referrer';
            } catch (e) {
                return 'referrer';
            }
        }

        return 'direct';
    }

    function isDirectFlashOpen() {
        return page === 'view' &&
            analyticsContext.target_type === 'flash' &&
            analyticsContext.target_id &&
            getNavigationSource() === 'direct';
    }

    window.SafetyFlashAnalytics = {
        track: sendEvent
    };

    document.addEventListener('DOMContentLoaded', function () {
        sendEvent({
            event_type: 'page_view',
            metadata: {
                path: window.location.pathname,
                search: window.location.search,
                referrer: document.referrer || '',
                navigation_source: getNavigationSource()
            }
        });

        if (getNavigationSource() === 'push' && analyticsContext.target_type === 'flash' && analyticsContext.target_id) {
            var pushId = new URLSearchParams(window.location.search).get('push_id') || '';
            var pushClickStorageKey = pushId ? 'sf_push_clicked_' + pushId : '';

            if (pushId && !sessionStorage.getItem(pushClickStorageKey)) {
                sessionStorage.setItem(pushClickStorageKey, '1');

                sendEvent({
                    event_type: 'push_clicked',
                    target_type: 'flash',
                    target_id: analyticsContext.target_id,
                    metadata: {
                        navigation_source: 'push',
                        push_id: pushId,
                        source: 'landing_page_fallback'
                    }
                });
            }

            sendEvent({
                event_type: 'push_flash_open',
                target_type: 'flash',
                target_id: analyticsContext.target_id,
                metadata: {
                    navigation_source: 'push',
                    push_id: pushId
                }
            });
        }

        if (isDirectFlashOpen()) {
            sendEvent({
                event_type: 'direct_flash_open',
                target_type: 'flash',
                target_id: analyticsContext.target_id,
                metadata: {
                    navigation_source: 'direct'
                }
            });
        }

        if (analyticsContext.target_type === 'flash' && analyticsContext.target_id) {
            sendEvent({
                event_type: 'flash_view',
                metadata: {
                    navigation_source: getNavigationSource()
                }
            });

            initFlashReadTracking();
        }

        sendPwaCapabilityEvent();

        if (isPwaMode()) {
            sendEvent({
                event_type: 'pwa_standalone_open',
                metadata: {
                    source: getNavigationSource(),
                    display_mode: getPwaDisplayMode()
                }
            });
        } else {
            sendEvent({
                event_type: 'pwa_browser_open',
                metadata: {
                    source: getNavigationSource(),
                    display_mode: getPwaDisplayMode()
                }
            });
        }
    });

    function sendPwaCapabilityEvent() {
        var key = 'sf_pwa_capability_sent_' + page;
        var alreadySent = sessionStorage.getItem(key);

        if (alreadySent) {
            return;
        }

        sessionStorage.setItem(key, '1');

        sendEvent({
            event_type: 'pwa_capability_detected',
            metadata: {
                display_mode: getPwaDisplayMode(),
                operating_system: getOperatingSystem(),
                push_supported: isPushSupported() ? 1 : 0,
                install_prompt_supported: isInstallPromptSupported() ? 1 : 0,
                notification_permission: getNotificationPermissionState(),
                user_agent_platform: getPlatform()
            }
        });
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        sendEvent({
            event_type: 'pwa_install_prompt_seen',
            metadata: {
                display_mode: getPwaDisplayMode(),
                operating_system: getOperatingSystem()
            }
        });

        event.userChoice.then(function (choiceResult) {
            sendEvent({
                event_type: choiceResult.outcome === 'accepted'
                    ? 'pwa_install_prompt_accepted'
                    : 'pwa_install_prompt_dismissed',
                metadata: {
                    outcome: choiceResult.outcome || '',
                    platform: choiceResult.platform || '',
                    display_mode: getPwaDisplayMode(),
                    operating_system: getOperatingSystem()
                }
            });
        }).catch(function () {});
    });

    window.addEventListener('appinstalled', function () {
        sendEvent({
            event_type: 'pwa_installed',
            metadata: {
                display_mode: getPwaDisplayMode(),
                operating_system: getOperatingSystem(),
                notification_permission: getNotificationPermissionState()
            }
        });
    });

    document.addEventListener('click', function (event) {
        var analyticsElement = event.target.closest('[data-sf-analytics-click]');
        var link = event.target.closest('a');
        var button = event.target.closest('button');

        if (analyticsElement) {
            var eventType = analyticsElement.getAttribute('data-sf-analytics-click') || '';
            var source = analyticsElement.getAttribute('data-sf-analytics-source') || '';
            var targetType = analyticsElement.getAttribute('data-sf-analytics-target-type') || null;
            var targetIdRaw = analyticsElement.getAttribute('data-sf-analytics-target-id') || '';
            var targetId = targetIdRaw && /^\d+$/.test(targetIdRaw) ? parseInt(targetIdRaw, 10) : null;

            if (!targetId && analyticsElement.href) {
                targetId = extractFlashIdFromHref(analyticsElement.href);
            }

            sendEvent({
                event_type: eventType,
                page: page,
                target_type: targetType,
                target_id: targetId,
                metadata: {
                    source: source,
                    href: analyticsElement.href || '',
                    text: (analyticsElement.textContent || '').trim().slice(0, 160)
                }
            });
        }

        if (link && link.hasAttribute('download')) {
            sendEvent({
                event_type: 'file_download',
                target_type: 'download',
                metadata: {
                    href: link.getAttribute('href') || '',
                    text: (link.textContent || '').trim().slice(0, 120)
                }
            });
        }

        if (link && isPdfUrl(link.getAttribute('href') || '')) {
            sendEvent({
                event_type: 'flash_pdf_download',
                target_type: analyticsContext.target_type || 'flash',
                target_id: analyticsContext.target_id || null,
                metadata: {
                    href: link.getAttribute('href') || '',
                    text: (link.textContent || '').trim().slice(0, 120)
                }
            });
        }

        if (button && isPdfButton(button)) {
            sendEvent({
                event_type: 'flash_pdf_download',
                target_type: analyticsContext.target_type || 'flash',
                target_id: analyticsContext.target_id || null,
                metadata: {
                    button_text: (button.textContent || '').trim().slice(0, 120),
                    button_id: button.id || '',
                    button_class: button.className || ''
                }
            });
        }
    }, true);

    function isPdfUrl(href) {
        if (!href) {
            return false;
        }

        return href.indexOf('generate_report.php') !== -1 ||
            href.indexOf('.pdf') !== -1 ||
            href.indexOf('pdf') !== -1;
    }

    function isPdfButton(button) {
        var text = (button.textContent || '').toLowerCase();
        var id = (button.id || '').toLowerCase();
        var className = (button.className || '').toLowerCase();

        return text.indexOf('pdf') !== -1 ||
            text.indexOf('raport') !== -1 ||
            text.indexOf('report') !== -1 ||
            id.indexOf('pdf') !== -1 ||
            id.indexOf('report') !== -1 ||
            className.indexOf('pdf') !== -1 ||
            className.indexOf('report') !== -1;
    }

    function initFlashReadTracking() {
        var thresholds = [25, 50, 75, 100];
        var sentThresholds = {};
        var startTime = Date.now();
        var lastDurationSentAt = 0;
        var durationSent = false;

        function getScrollPercent() {
            var doc = document.documentElement;
            var body = document.body;

            var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop || 0;
            var viewportHeight = window.innerHeight || doc.clientHeight || 0;
            var scrollHeight = Math.max(
                body.scrollHeight || 0,
                doc.scrollHeight || 0,
                body.offsetHeight || 0,
                doc.offsetHeight || 0
            );

            if (scrollHeight <= viewportHeight) {
                return 100;
            }

            return Math.min(100, Math.round(((scrollTop + viewportHeight) / scrollHeight) * 100));
        }

        function checkReadProgress() {
            var percent = getScrollPercent();

            thresholds.forEach(function (threshold) {
                if (percent >= threshold && !sentThresholds[threshold]) {
                    sentThresholds[threshold] = true;

                    sendEvent({
                        event_type: 'flash_read_' + threshold,
                        metadata: {
                            scroll_percent: percent
                        }
                    });
                }
            });
        }

        function sendDurationEvent(force) {
            var now = Date.now();
            var seconds = Math.max(1, Math.round((now - startTime) / 1000));

            if (!force && now - lastDurationSentAt < 15000) {
                return;
            }

            if (durationSent && force) {
                return;
            }

            lastDurationSentAt = now;

            if (force) {
                durationSent = true;
            }

            sendEvent({
                event_type: 'flash_view_duration',
                metadata: {
                    duration_seconds: seconds,
                    max_scroll_percent: getScrollPercent(),
                    final: force ? 1 : 0
                }
            });
        }

        var scrollTimer = null;

        window.addEventListener('scroll', function () {
            if (scrollTimer) {
                window.clearTimeout(scrollTimer);
            }

            scrollTimer = window.setTimeout(checkReadProgress, 250);
        }, { passive: true });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendDurationEvent(true);
            }
        });

        window.addEventListener('pagehide', function () {
            sendDurationEvent(true);
        });

        window.setInterval(function () {
            sendDurationEvent(false);
        }, 30000);

        checkReadProgress();
    }
})();