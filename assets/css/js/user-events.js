(function () {
    'use strict';

    var root = document.getElementById('sfUserEvents');
    var btn = document.getElementById('sfUserEventsBtn');
    var panel = document.getElementById('sfUserEventsPanel');
    var list = document.getElementById('sfUserEventsList');
    var badge = document.getElementById('sfUserEventsBadge');
    var markAllBtn = document.getElementById('sfUserEventsMarkAll');

    if (!root || !btn || !panel || !list || !badge) {
        return;
    }

    var baseUrl = root.getAttribute('data-base-url') || '';
    var csrfToken = root.getAttribute('data-csrf-token') || '';
    var emptyText = 'Ei uusia tapahtumia';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function render(payload) {
        var count = Number(payload.count || 0);

        if (payload.empty_text) {
            emptyText = payload.empty_text;
        }

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.remove('hidden');
            if (markAllBtn) {
                markAllBtn.classList.remove('hidden');
            }
        } else {
            badge.textContent = '0';
            badge.classList.add('hidden');
            if (markAllBtn) {
                markAllBtn.classList.add('hidden');
            }
        }

        var events = Array.isArray(payload.events) ? payload.events : [];

        if (!events.length) {
            list.innerHTML = '<div class="sf-user-events-empty">' + escapeHtml(emptyText) + '</div>';
            return;
        }

        var html = '';

        events.forEach(function (event) {
            var groupClass = event.event_group === 'action_required'
                ? 'sf-user-event--action'
                : 'sf-user-event--info';

            var displayTime = event.created_at_display || event.created_at || '';

            html += ''
                + '<a class="sf-user-event ' + groupClass + '" href="' + escapeHtml(baseUrl + '/' + event.url) + '" data-event-id="' + Number(event.id || 0) + '">'
                + '  <span class="sf-user-event-dot" aria-hidden="true"></span>'
                + '  <span class="sf-user-event-body">'
                + '    <span class="sf-user-event-label">' + escapeHtml(event.label) + '</span>'
                + '    <span class="sf-user-event-title">' + escapeHtml(event.title) + '</span>'
                + '    <span class="sf-user-event-time">' + escapeHtml(displayTime) + '</span>'
                + '  </span>'
                + '</a>';
        });

        list.innerHTML = html;
    }

    function fetchEvents() {
        fetch(baseUrl + '/app/api/get_user_events.php', {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (payload) {
                if (payload && payload.ok) {
                    render(payload);
                }
            })
            .catch(function () {});
    }

    function markRead(eventId) {
        if (!eventId) {
            return;
        }

        var formData = new FormData();
        formData.append('event_id', String(eventId));
        formData.append('csrf_token', csrfToken);

        fetch(baseUrl + '/app/api/mark_user_event_read.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: formData
        }).catch(function () {});
    }

    function markAllRead() {
        if (!markAllBtn) {
            return;
        }

        markAllBtn.disabled = true;

        var formData = new FormData();
        formData.append('action', 'mark_all');
        formData.append('csrf_token', csrfToken);

        fetch(baseUrl + '/app/api/mark_user_event_read.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: formData
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (payload) {
                if (payload && payload.ok) {
                    render({
                        ok: true,
                        count: 0,
                        events: [],
                        empty_text: emptyText
                    });
                }
            })
            .finally(function () {
                markAllBtn.disabled = false;
            });
    }

    btn.addEventListener('click', function () {
        var isOpen = !panel.classList.contains('hidden');

        panel.classList.toggle('hidden', isOpen);
        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

        if (!isOpen) {
            fetchEvents();
        }
    });

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            markAllRead();
        });
    }

    list.addEventListener('click', function (event) {
        var link = event.target.closest('.sf-user-event');
        if (!link) {
            return;
        }

        markRead(Number(link.getAttribute('data-event-id') || 0));
    });

    document.addEventListener('click', function (event) {
        if (!root.contains(event.target)) {
            panel.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            panel.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    fetchEvents();
    window.setInterval(fetchEvents, 45000);
})();