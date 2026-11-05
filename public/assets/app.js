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
        // Top nav: a horizontal row in normal document flow now, not a side panel, so it
        // collapses in place (max-height/opacity) rather than sliding off as an overlay.
        // Defaults collapsed on narrow screens (less room to spare) and expanded on wide
        // ones; after that, whichever way it's toggled is saved across page loads, since
        // every nav click here is a full page reload, not a single-page app.
        var toggle = document.querySelector('.menu-toggle');
        var topNav = document.getElementById('top-nav');
        var narrow = window.matchMedia('(max-width: 820px)');
        var collapseKey = 'cts.navCollapsed';
        if (toggle && topNav) {
            var syncToggle = function () {
                var expanded = !document.body.classList.contains('nav-collapsed');
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };
            try {
                var stored = localStorage.getItem(collapseKey);
                if (stored === null ? narrow.matches : stored === '1') {
                    document.body.classList.add('nav-collapsed');
                }
            } catch (e) {
                if (narrow.matches) document.body.classList.add('nav-collapsed');
            }
            syncToggle();
            toggle.addEventListener('click', function () {
                // The transition is added only for the moment of this click and removed
                // right after -- see the .nav-animating rule in style.css for why (same
                // reasoning as the collapsible sidebar before it: a transition left on
                // permanently broke the sticky topbar's repaint during scroll).
                document.body.classList.add('nav-animating');
                clearTimeout(toggle._animTimer);
                toggle._animTimer = setTimeout(function () {
                    document.body.classList.remove('nav-animating');
                }, 260);
                var collapsed = document.body.classList.toggle('nav-collapsed');
                try { localStorage.setItem(collapseKey, collapsed ? '1' : '0'); } catch (e) { /* storage blocked */ }
                syncToggle();
            });
        }

        // A link to #report-sla etc. (e.g. the reports KPI tiles, linking to a report further
        // down the same page) should land on an already-open report, not a collapsed <details>
        // summary. Fragment-only navigation doesn't re-fire DOMContentLoaded, so this also has
        // to run on hashchange for a click while already on reports.php.
        var openHashTarget = function () {
            if (!location.hash) return;
            var hashTarget = document.getElementById(location.hash.slice(1));
            if (hashTarget && hashTarget.tagName === 'DETAILS') {
                hashTarget.open = true;
            }
        };
        openHashTarget();
        window.addEventListener('hashchange', openHashTarget);

        // Staff dashboard menu (dashboard.php): one panel open at a time, chosen from the menu on
        // the left. The last choice is remembered across page loads; arrow keys move through the menu.
        var dash = document.querySelector('[data-dash-menu]');
        if (dash) {
            var dashKey = 'cts.dashPanel';
            var tabs = Array.prototype.slice.call(dash.querySelectorAll('[data-dash-key]'));
            var showPanel = function (key, focus) {
                tabs.forEach(function (tab) {
                    var on = tab.getAttribute('data-dash-key') === key;
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                    tab.tabIndex = on ? 0 : -1;
                    if (on && focus) tab.focus();
                    var panel = document.getElementById(tab.getAttribute('aria-controls'));
                    if (panel) panel.classList.toggle('is-active', on);
                });
                try { localStorage.setItem(dashKey, key); } catch (e) { /* storage blocked */ }
            };
            var start = dash.getAttribute('data-default');
            try {
                var saved = localStorage.getItem(dashKey);
                if (saved && dash.querySelector('[data-dash-key="' + saved + '"]')) start = saved;
            } catch (e) { /* storage blocked */ }
            showPanel(start, false);
            dash.classList.add('is-ready');
            tabs.forEach(function (tab, i) {
                tab.addEventListener('click', function () { showPanel(tab.getAttribute('data-dash-key'), false); });
                tab.addEventListener('keydown', function (event) {
                    var next = { ArrowDown: i + 1, ArrowRight: i + 1, ArrowUp: i - 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[event.key];
                    if (next === undefined) return;
                    event.preventDefault();
                    next = (next + tabs.length) % tabs.length;
                    showPanel(tabs[next].getAttribute('data-dash-key'), true);
                });
            });
        }

        enhance(document);
        live.init();
        setInterval(function () { updateSlaBadges(document); updateRelativeTimes(); }, 30000);
    });
})();
