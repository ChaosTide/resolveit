// ResolveIT – progressive enhancements. Every page still works without JavaScript:
// links and forms fall back to normal page loads.
(function () {
    'use strict';

    var EYE_PATH = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
    var EYE_OFF_PATH = '<path d="M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a22 22 0 0 1-3.22 4.3M6.6 6.6A22 22 0 0'
        + ' 0 1 12s4 7 11 7a10.6 10.6 0 0 0 4.24-.87M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/>';

    function svgIcon(path) {
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
    }

    function durationText(seconds) {
        seconds = Math.abs(seconds);
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        if (days > 0) return days + 'd ' + hours + 'h';
        if (hours > 0) return hours + 'h ' + minutes + 'm';
        return Math.max(1, minutes) + 'm';
    }

    // Live SLA countdowns: <span class="sla" data-due="ISO-8601" data-sla-hours="24">.
    // Mirrors sla_badge() in app/ui.php: under a quarter of the target left is "due soon".
    function updateSlaBadges(root) {
        var now = Date.now();
        (root || document).querySelectorAll('.sla[data-due]').forEach(function (badge) {
            var due = Date.parse(badge.getAttribute('data-due'));
            if (isNaN(due)) return;
            var remaining = Math.round((due - now) / 1000);
            var targetSeconds = Number(badge.getAttribute('data-sla-hours')) * 3600;
            badge.classList.remove('sla-overdue', 'sla-due-soon', 'sla-on-track');
            if (remaining < 0) {
                badge.classList.add('sla-overdue');
                badge.textContent = 'Overdue ' + durationText(remaining);
            } else {
                badge.classList.add(remaining < targetSeconds / 4 ? 'sla-due-soon' : 'sla-on-track');
                badge.textContent = durationText(remaining) + ' left';
            }
        });
    }

    function updateRelativeTimes() {
        var now = Date.now();
        document.querySelectorAll('time[datetime]').forEach(function (el) {
            var at = Date.parse(el.getAttribute('datetime'));
            if (isNaN(at) || !/ago$|just now/.test(el.textContent)) return;
            var seconds = Math.round((now - at) / 1000);
            el.textContent = seconds < 60 ? 'just now' : durationText(seconds) + ' ago';
        });
    }

    // Per-element enhancements. Runs on page load and again on content inserted later
    // (auto-refreshed lists and the split-view pane).
    function enhance(root) {
        root.querySelectorAll('textarea[maxlength]').forEach(function (area) {
            if (area.dataset.counted) return;
            area.dataset.counted = '1';
            var label = area.id ? root.querySelector('label[for="' + area.id + '"]') : null;
            if (!label) return;
            var counter = document.createElement('span');
            counter.className = 'char-count';
            label.appendChild(counter);
            var update = function () { counter.textContent = area.value.length + ' / ' + area.getAttribute('maxlength'); };
            area.addEventListener('input', update);
            update();
        });

        // Manage Ticket: a resolution becomes required when Resolved or Closed is chosen.
        var status = root.querySelector('#status_id');
        var resolution = root.querySelector('#resolution');
        if (status && resolution && !status.dataset.synced) {
            status.dataset.synced = '1';
            var syncResolution = function () {
                var name = status.options[status.selectedIndex].getAttribute('data-name') || '';
                var needed = name === 'Resolved' || name === 'Closed';
                resolution.required = needed;
                resolution.minLength = needed ? 10 : 0;
                var hint = root.querySelector('#resolution-hint');
                if (hint) hint.hidden = !needed;
            };
            status.addEventListener('change', syncResolution);
            syncResolution();
        }

        // Replies posted from the split-view pane come back to the same list.
        root.querySelectorAll('input[data-back-url]').forEach(function (input) {
            if (document.querySelector('[data-split-pane]')) input.value = 'tickets.php' + location.search;
        });

        // Password fields get a show/hide toggle so people can check what they typed.
        root.querySelectorAll('input[type="password"]').forEach(function (input) {
            if (input.dataset.toggled) return;
            input.dataset.toggled = '1';
            var wrap = document.createElement('div');
            wrap.className = 'password-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'icon-button password-toggle';
            toggle.setAttribute('aria-label', 'Show password');
            toggle.innerHTML = svgIcon(EYE_PATH);
            wrap.appendChild(toggle);
            toggle.addEventListener('click', function () {
                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                toggle.innerHTML = svgIcon(reveal ? EYE_OFF_PATH : EYE_PATH);
                toggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            });
        });

        updateSlaBadges(root);
    }

    // ----- Live list: refresh button, automatic refresh, split view -----
    var live = {
        results: null, timer: null, pane: null, selectedId: null,
        storageKey: 'cts.autoRefresh',

        init: function () {
            this.results = document.getElementById('live-results');
            this.pane = document.querySelector('[data-split-pane]');
            if (!this.results) return;
            var self = this;
            var enabled = true;
            try { enabled = localStorage.getItem(this.storageKey) !== 'off'; } catch (e) { /* storage blocked */ }
            this.syncToggles(enabled);
            this.schedule();
            document.addEventListener('visibilitychange', function () { if (!document.hidden) self.check(); });
            if (this.pane) {
                var selected = new URLSearchParams(location.search).get('selected');
                if (selected && /^\d+$/.test(selected)) this.openTicket(selected);
            }
        },

        autoEnabled: function () {
            var box = document.querySelector('[data-auto-refresh]');
            return !box || box.checked;
        },

        syncToggles: function (enabled) {
            document.querySelectorAll('[data-auto-refresh]').forEach(function (box) { box.checked = enabled; });
        },

        schedule: function () {
            var self = this;
            clearInterval(this.timer);
            this.timer = setInterval(function () { if (self.autoEnabled() && !document.hidden) self.check(); }, 30000);
        },

        stampUrl: function () {
            var params = new URLSearchParams(location.search);
            params.delete('selected');
            params.set('format', 'stamp');
            return location.pathname + '?' + params.toString();
        },

        // Is the user in the middle of something that a refresh would disturb?
        busy: function () {
            if (document.querySelector('input[name="ids[]"]:checked')) return true;
            var active = document.activeElement;
            return !!(active && this.results.contains(active) && /INPUT|SELECT|TEXTAREA/.test(active.tagName));
        },

        check: function () {
            var self = this;
            if (!this.results || !this.autoEnabled()) return;
            fetch(this.stampUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.stamp) return;
                    if (data.stamp === self.results.getAttribute('data-stamp')) { self.setStatus('Up to date'); return; }
                    if (self.busy()) { self.showBanner(true); return; }
                    self.reload();
                })
                .catch(function () { self.setStatus('Offline, retrying'); });
        },

        // Fetch the current page and swap in the live regions (result list and view counts).
        reload: function () {
            var self = this;
            var url = location.pathname + location.search;
            return fetch(url, { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    document.querySelectorAll('[data-live-region][id]').forEach(function (region) {
                        var fresh = doc.getElementById(region.id);
                        if (fresh) region.replaceWith(fresh);
                    });
                    self.results = document.getElementById('live-results');
                    var enabled = self.autoEnabled();
                    self.syncToggles(enabled);
                    enhance(self.results);
                    self.markSelected();
                    self.showBanner(false);
                    self.setStatus('Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
                })
                .catch(function () { window.location.reload(); });
        },

        setStatus: function (text) {
            var el = document.querySelector('[data-live-status]');
            if (el) el.textContent = text;
        },

        showBanner: function (show) {
            var banner = document.querySelector('[data-update-banner]');
            if (banner) banner.hidden = !show;
        },

        // Split view: load a ticket into the side pane without leaving the list.
        openTicket: function (id) {
            var self = this;
            if (!this.pane) return false;
            this.selectedId = String(id);
            this.markSelected();
            this.pane.setAttribute('aria-busy', 'true');
            fetch('ticket.php?id=' + encodeURIComponent(id) + '&partial=1', { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
                .then(function (html) {
                    self.pane.innerHTML = html;
                    self.pane.scrollTop = 0;
                    enhance(self.pane);
                    var params = new URLSearchParams(location.search);
                    params.set('selected', id);
                    history.replaceState(null, '', location.pathname + '?' + params.toString());
                })
                .catch(function () { window.location.href = 'ticket.php?id=' + encodeURIComponent(id); })
                .finally(function () { self.pane.removeAttribute('aria-busy'); });
            return true;
        },

        closePane: function () {
            if (!this.pane) return;
            this.selectedId = null;
            this.pane.innerHTML = '<div class="empty-state"><p>Select a ticket to see it here.</p></div>';
            this.markSelected();
            var params = new URLSearchParams(location.search);
            params.delete('selected');
            history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params.toString() : ''));
        },

        markSelected: function () {
            var id = this.selectedId;
            document.querySelectorAll('tr[data-href]').forEach(function (row) {
                row.classList.toggle('is-selected', !!id && row.getAttribute('data-href') === 'ticket.php?id=' + id);
            });
        }
    };

    function bulkSync() {
        var form = document.querySelector('[data-bulk-form]');
        if (!form) return;
        var n = document.querySelectorAll('input[name="ids[]"][form="bulk-form"]:checked').length;
        var counter = form.querySelector('[data-bulk-count]');
        if (counter) counter.textContent = n ? n + ' selected' : 'Bulk update';
        form.classList.toggle('has-selection', n > 0);
    }

    var wideScreen = window.matchMedia ? window.matchMedia('(min-width: 1100px)') : { matches: true };

    document.addEventListener('click', function (event) {
        var target = event.target;

        // Refresh button: reload the list in place instead of the whole page.
        var refresh = target.closest('[data-refresh], [data-refresh-now]');
        if (refresh && live.results) { event.preventDefault(); live.reload(); return; }

        if (target.closest('[data-close-pane]')) { live.closePane(); return; }

        // Ticket rows and their links: open in the split pane when it is showing, otherwise navigate.
        var row = target.closest('tr[data-href]');
        if (!row) return;
        var link = target.closest('a');
        var isTicketLink = link && /^ticket\.php\?id=\d+$/.test(link.getAttribute('href') || '');
        if (target.closest('button, input, select, textarea, label') || (link && !isTicketLink)) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
        var id = row.getAttribute('data-href').replace(/\D/g, '');
        if (live.pane && wideScreen.matches) {
            event.preventDefault();
            live.openTicket(id);
            return;
        }
        if (!link) window.location.href = row.getAttribute('data-href');
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.matches('[data-select-all]')) {
            document.querySelectorAll('input[name="ids[]"][form="bulk-form"]').forEach(function (b) { b.checked = target.checked; });
            bulkSync();
        } else if (target.matches('input[name="ids[]"]')) {
            bulkSync();
        } else if (target.matches('[data-auto-refresh]')) {
            try { localStorage.setItem(live.storageKey, target.checked ? 'on' : 'off'); } catch (e) { /* storage blocked */ }
            live.syncToggles(target.checked);
            if (target.checked) live.check();
            else live.setStatus('Auto-refresh off');
        } else if (target.matches('[data-autosubmit]')) {
            // Choosing a new sort from the dropdown resets to that sort's natural direction.
            if (target.matches('[data-reset-dir]')) {
                var dir = target.form.querySelector('input[name="dir"]');
                if (dir) dir.remove();
            }
            target.form.submit();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.matches('form[data-confirm]') && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        } else if (form.matches('[data-bulk-form]') && !document.querySelector('input[name="ids[]"][form="bulk-form"]:checked')) {
            event.preventDefault();
            window.alert('Select at least one ticket first.');
        }
        if (!event.defaultPrevented) {
            var btn = form.querySelector('button:not([type="button"])');
            if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Sidebar: slides in/out as an overlay on narrow screens (closed by default --
        // there's no room to spare); collapses to reclaim width on wide ones instead, and that
        // choice is saved across page loads, since every nav click here is a full page reload.
        var toggle = document.querySelector('.menu-toggle');
        var sidebar = document.getElementById('sidebar');
        var narrow = window.matchMedia('(max-width: 820px)');
        var collapseKey = 'cts.sidebarCollapsed';
        if (toggle && sidebar) {
            var syncToggle = function () {
                var expanded = narrow.matches
                    ? sidebar.classList.contains('open')
                    : !document.body.classList.contains('sidebar-collapsed');
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };
            try {
                if (!narrow.matches && localStorage.getItem(collapseKey) === '1') {
                    document.body.classList.add('sidebar-collapsed');
                }
            } catch (e) { /* storage blocked */ }
            syncToggle();
            toggle.addEventListener('click', function () {
                if (narrow.matches) {
                    sidebar.classList.toggle('open');
                } else {
                    // The transition is added only for the moment of this click and removed
                    // right after -- see the .sidebar-animating rule in style.css for why.
                    document.body.classList.add('sidebar-animating');
                    clearTimeout(toggle._animTimer);
                    toggle._animTimer = setTimeout(function () {
                        document.body.classList.remove('sidebar-animating');
                    }, 260);
                    var collapsed = document.body.classList.toggle('sidebar-collapsed');
                    try { localStorage.setItem(collapseKey, collapsed ? '1' : '0'); } catch (e) { /* storage blocked */ }
                }
                syncToggle();
            });
            document.addEventListener('click', function (event) {
                if (sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                    syncToggle();
                }
            });
        }

        enhance(document);
        live.init();
        setInterval(function () { updateSlaBadges(document); updateRelativeTimes(); }, 30000);
    });
})();
