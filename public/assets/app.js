// CTS Service Desk – small progressive enhancements. Every page works without JavaScript.
(function () {
    'use strict';

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
    function updateSlaBadges() {
        var now = Date.now();
        document.querySelectorAll('.sla[data-due]').forEach(function (badge) {
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

    document.addEventListener('DOMContentLoaded', function () {
        // Mobile sidebar.
        var toggle = document.querySelector('.menu-toggle');
        var sidebar = document.getElementById('sidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                var open = sidebar.classList.toggle('open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function (event) {
                if (sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Whole table rows open their ticket; links, buttons, and form fields keep their own behaviour.
        document.querySelectorAll('tr[data-href]').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                window.location.href = row.getAttribute('data-href');
            });
        });

        // Bulk update: "select all" checkbox and a live count of selected tickets.
        var bulkForm = document.querySelector('[data-bulk-form]');
        if (bulkForm) {
            var boxes = document.querySelectorAll('input[name="ids[]"][form="bulk-form"]');
            var counter = bulkForm.querySelector('[data-bulk-count]');
            var syncCount = function () {
                var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                counter.textContent = n ? n + ' selected' : 'Bulk update';
                bulkForm.classList.toggle('has-selection', n > 0);
            };
            boxes.forEach(function (b) { b.addEventListener('change', syncCount); });
            var all = document.querySelector('[data-select-all]');
            if (all) all.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = all.checked; });
                syncCount();
            });
            bulkForm.addEventListener('submit', function (event) {
                var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                if (!n) { event.preventDefault(); window.alert('Select at least one ticket first.'); }
            });
        }

        // Filter dropdowns apply as soon as they change.
        document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
            field.addEventListener('change', function () { field.form.submit(); });
        });

        // Ask before actions such as deactivating a user.
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
            });
        });

        // Character counters for long text fields.
        document.querySelectorAll('textarea[maxlength]').forEach(function (area) {
            var label = area.id ? document.querySelector('label[for="' + area.id + '"]') : null;
            if (!label) return;
            var counter = document.createElement('span');
            counter.className = 'char-count';
            label.appendChild(counter);
            var update = function () { counter.textContent = area.value.length + ' / ' + area.getAttribute('maxlength'); };
            area.addEventListener('input', update);
            update();
        });

        // Manage Ticket: a resolution becomes required when Resolved or Closed is chosen.
        var status = document.getElementById('status_id');
        var resolution = document.getElementById('resolution');
        if (status && resolution) {
            var syncResolution = function () {
                var name = status.options[status.selectedIndex].getAttribute('data-name') || '';
                var needed = name === 'Resolved' || name === 'Closed';
                resolution.required = needed;
                resolution.minLength = needed ? 10 : 0;
                var hint = document.getElementById('resolution-hint');
                if (hint) hint.hidden = !needed;
            };
            status.addEventListener('change', syncResolution);
            syncResolution();
        }

        updateSlaBadges();
        setInterval(function () { updateSlaBadges(); updateRelativeTimes(); }, 30000);
    });
})();
