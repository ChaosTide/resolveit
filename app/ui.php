<?php

declare(strict_types=1);

// Display helpers shared by every page: badges, SLA countdowns, dates, icons, tables, pagination.
// Every value placed in HTML passes through e() unless it is already safe HTML built here.

function css_token(string $value): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
}

function status_badge(string $status): string
{
    return '<span class="badge status-' . css_token($status) . '">' . e($status) . '</span>';
}

/** Priority colour follows its level (1 = Low ... 4 = Critical), so renamed priorities keep their colour. */
function priority_badge(string $priority, int $level): string
{
    $level = max(1, min(4, $level));
    return '<span class="badge prio prio-' . $level . '">'
        . '<span class="dot" aria-hidden="true"></span>' . e($priority) . '</span>';
}

/** "2d 3h", "3h 12m", or "12m" for a number of seconds (sign ignored). */
function duration_text(int $seconds): string
{
    $seconds = abs($seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return max(1, $minutes) . 'm';
}

function to_datetime(?string $value): ?DateTimeImmutable
{
    return $value ? new DateTimeImmutable($value) : null;
}

function iso_time(?string $value): string
{
    $dt = to_datetime($value);
    return $dt ? $dt->format(DATE_ATOM) : '';
}

function format_time(?string $value): string
{
    $dt = to_datetime($value);
    if (!$dt) {
        return '';
    }
    return $dt->format($dt->format('Y') === date('Y') ? 'M j, g:i A' : 'M j, Y g:i A');
}

function time_ago(?string $value): string
{
    $dt = to_datetime($value);
    if (!$dt) {
        return '';
    }
    $seconds = time() - $dt->getTimestamp();
    if ($seconds < 60) {
        return 'just now';
    }
    return duration_text($seconds) . ' ago';
}

/** "5m ago" with the exact time as a tooltip; assets/app.js keeps it current. */
function relative_time(?string $value): string
{
    return '<time datetime="' . e(iso_time($value)) . '" title="' . e(format_time($value)) . '">'
        . e(time_ago($value)) . '</time>';
}

/**
 * SLA pill. Unresolved tickets count down live (assets/app.js reads data-due); "due soon" means
 * less than a quarter of the target remains. Resolved tickets show whether the target was met.
 */
function sla_badge(string $state, ?string $dueAt, int $slaHours): string
{
    if ($state === 'On time') {
        return '<span class="sla sla-met" title="Resolved within ' . $slaHours . 'h target">Met</span>';
    }
    if ($state === 'Late') {
        return '<span class="sla sla-missed" title="Resolved after the ' . $slaHours . 'h target">Missed</span>';
    }
    $due = to_datetime($dueAt);
    if (!$due) {
        return '';
    }
    $remaining = $due->getTimestamp() - time();
    if ($remaining < 0) {
        $class = 'sla-overdue';
        $text = 'Overdue ' . duration_text($remaining);
    } else {
        $class = $remaining < $slaHours * 900 ? 'sla-due-soon' : 'sla-on-track';
        $text = duration_text($remaining) . ' left';
    }
    return '<span class="sla ' . $class . '" data-due="' . e($due->format(DATE_ATOM)) . '"'
        . ' data-sla-hours="' . $slaHours . '" title="Due ' . e(format_time($dueAt)) . '">' . e($text) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '?', 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Round initials badge; the colour is derived from the name so each person keeps theirs. */
function avatar(?string $name): string
{
    if ($name === null) {
        return '<span class="avatar avatar-empty" aria-hidden="true">–</span>';
    }
    $hue = crc32($name) % 360;
    return '<span class="avatar" aria-hidden="true" style="--hue:' . $hue . '">' . e(initials($name)) . '</span>';
}

/** Inline SVG icon (24x24 line icons drawn for this project; no icon library or external files). */
function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/>'
            . '<rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'tickets' => '<path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5'
            . 'a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M13 5v2M13 11v2M13 17v2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/>'
            . '<path d="M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8'
            . 'l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5'
            . ' 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3'
            . 'a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1'
            . 'a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0'
            . ' 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21'
            . 'a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
            . '<path d="M12 9v4M12 17h.01"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16'
            . 'a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/>'
            . '<path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** The brand mark (public/assets/logo-mark.png), cache-busted the same way as style.css/app.js. */
function logo_mark(): string
{
    $mtime = filemtime(__DIR__ . '/../public/assets/logo-mark.png');
    return '<img class="icon" src="assets/logo-mark.png?v=' . $mtime . '" alt="" width="24" height="24">';
}

/**
 * Ticket table used by the dashboard, ticket list, and search. Rows come from ticket_list_sql().
 * Staff see requester and SLA columns; employees see their own tickets without SLA details.
 * With $bulk, each row gets a checkbox tied to the form with id "bulk-form" (tickets.php).
 * With $sort (['key' => , 'dir' => , 'query' => current filters]), column headers become sort links.
 */
function ticket_table(array $tickets, bool $staff, string $emptyText, bool $bulk = false, ?array $sort = null): string
{
    $html = '<div class="table-wrap"><table class="ticket-table"><thead>'
        . ticket_table_header($staff, $bulk, $sort) . '</thead><tbody>';
    foreach ($tickets as $ticket) {
        $html .= ticket_table_row($ticket, $staff, $bulk);
    }
    if (!$tickets) {
        $columns = ($staff ? 8 : 6) + ($bulk ? 1 : 0);
        $html .= '<tr><td colspan="' . $columns . '"><div class="empty-state">' . icon('inbox')
            . '<p>' . e($emptyText) . '</p></div></td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function ticket_table_header(bool $staff, bool $bulk, ?array $sort): string
{
    $html = '<tr>';
    if ($bulk) {
        $html .= '<th class="check-col">'
            . '<input type="checkbox" data-select-all aria-label="Select all tickets on this page"></th>';
    }
    $html .= sortable_header('ID', 'id', '', $staff, $sort) . sortable_header('Subject', 'title', '', $staff, $sort);
    if ($staff) {
        $html .= sortable_header('Requester', 'requester', 'hide-sm', $staff, $sort);
    }
    $html .= sortable_header('Priority', 'priority', '', $staff, $sort)
        . sortable_header('Status', 'status', '', $staff, $sort)
        . sortable_header('Assigned to', 'assignee', 'hide-sm', $staff, $sort);
    if ($staff) {
        $html .= sortable_header('SLA', 'due', '', $staff, $sort);
    }
    return $html . sortable_header('Updated', 'updated', 'hide-sm', $staff, $sort) . '</tr>';
}

/** A column header; a link that toggles the sort direction when sorting is enabled for that column. */
function sortable_header(string $label, string $key, string $class, bool $staff, ?array $sort): string
{
    $attr = $class !== '' ? ' class="' . $class . '"' : '';
    $options = ticket_sort_options($staff);
    if (!$sort || !isset($options[$key])) {
        return "<th$attr>" . e($label) . '</th>';
    }
    $active = $sort['key'] === $key;
    $ascending = $sort['dir'] === 'asc';
    $nextDir = $active ? ($ascending ? 'desc' : 'asc') : $options[$key][1];
    $href = '?' . http_build_query(['sort' => $key, 'dir' => $nextDir] + $sort['query']);
    $ariaSort = $active ? ' aria-sort="' . ($ascending ? 'ascending' : 'descending') . '"' : '';
    $arrow = $active ? ($ascending ? ' &uarr;' : ' &darr;') : '';
    return "<th$attr$ariaSort><a class=\"sort-link" . ($active ? ' active' : '') . '"'
        . ' href="' . e($href) . '" data-sort-key="' . $key . '">' . e($label) . $arrow . '</a></th>';
}

function ticket_table_row(array $t, bool $staff, bool $bulk): string
{
    $id = (int) $t['id'];
    $link = 'ticket.php?id=' . $id;

    $due = '';
    if ($t['due_date']) {
        $dueDay = e(date('M j', strtotime((string) $t['due_date'])));
        $due = $t['past_due']
            ? ' &middot; <span class="due-past">Past due ' . $dueDay . '</span>'
            : ' &middot; <span class="due">Due ' . $dueDay . '</span>';
    }
    $waiting = $t['resolved_at'] === null && $t['waiting']
        ? ' <span class="badge waiting-tag" title="IT replied last; waiting on the requester">Waiting</span>'
        : '';

    $html = '<tr data-href="' . $link . '">';
    if ($bulk) {
        $html .= '<td class="check-col"><input type="checkbox" name="ids[]" value="' . $id . '"'
            . ' form="bulk-form" aria-label="Select ticket #' . $id . '"></td>';
    }
    $html .= '<td class="ticket-id"><a href="' . $link . '">#' . $id . '</a></td>'
        . '<td class="subject-col"><a class="ticket-title" href="' . $link . '">' . e($t['title']) . '</a>'
        . '<span class="ticket-sub">' . e($t['category']) . $due . '</span></td>';
    if ($staff) {
        $organization = $t['requester_organization']
            ? '<span class="ticket-sub">' . e($t['requester_organization']) . '</span>'
            : '';
        $html .= '<td class="hide-sm requester-col"><span class="person">' . avatar($t['requester'])
            . '<span>' . e($t['requester']) . $organization . '</span></span></td>';
    }
    $html .= '<td>' . priority_badge($t['priority'], (int) $t['priority_order']) . '</td>'
        . '<td>' . status_badge($t['status']) . $waiting . '</td>'
        . '<td class="hide-sm"><span class="person">' . avatar($t['assignee'])
        . e($t['assignee'] ?? 'Unassigned') . '</span></td>';
    if ($staff) {
        $html .= '<td>' . sla_badge($t['sla_state'], $t['due_at'], (int) $t['sla_hours']) . '</td>';
    }
    return $html . '<td class="hide-sm">' . relative_time($t['updated_at']) . '</td></tr>';
}

/**
 * <option> tags for a <select>: $options maps each value to its label (use array_column($rows, 'name', 'id')).
 * The option whose value equals $selected is pre-selected; null selects nothing.
 */
function select_options(array $options, int|string|null $selected): string
{
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = $selected !== null && (string) $selected === (string) $value;
        $html .= '<option value="' . e((string) $value) . '"' . ($isSelected ? ' selected' : '') . '>'
            . e((string) $label) . '</option>';
    }
    return $html;
}

/** Hidden inputs every admin action form posts: the CSRF token, the action name, and optionally a record id. */
function action_fields(string $action, ?int $id = null): string
{
    $html = '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">';
    if ($id !== null) {
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
    }
    return $html;
}

/** Organizations for a dropdown: active ones plus $keepId (a user's current, possibly inactive, organization). */
function organization_options(PDO $pdo, ?int $keepId = null): array
{
    $stmt = $pdo->prepare('SELECT id, name, active FROM organizations WHERE active = 1 OR id = ? ORDER BY name');
    $stmt->execute([$keepId ?? 0]);
    return $stmt->fetchAll();
}

/** Previous / next links that keep the current filters. */
function pagination(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = function (int $target, string $label, bool $disabled) use ($query) {
        if ($disabled) {
            return '<span class="page-link disabled">' . $label . '</span>';
        }
        $href = '?' . http_build_query(['page' => $target] + $query);
        return '<a class="page-link" href="' . e($href) . '">' . $label . '</a>';
    };
    return '<nav class="pagination" aria-label="Pages">' . $link($page - 1, '&larr; Previous', $page <= 1)
        . '<span class="page-info">Page ' . $page . ' of ' . $pages . '</span>'
        . $link($page + 1, 'Next &rarr;', $page >= $pages) . '</nav>';
}

/** Dashboard number tile linking to the matching ticket list. */
function kpi_tile(string $label, int $value, string $iconName, string $tone, string $href, string $note): string
{
    return '<a class="card stat tone-' . $tone . '" href="' . e($href) . '">'
        . '<span class="stat-icon">' . icon($iconName) . '</span>'
        . '<span class="meta">' . e($label) . '</span><strong>' . $value . '</strong>'
        . '<span class="stat-note">' . e($note) . '</span></a>';
}

/** One report table from run_report() (app/reports.php), with an optional bar-chart column. */
function render_report(array $report): void
{
    $max = 1;
    if ($report['bar']) {
        foreach ($report['rows'] as $row) {
            $max = max($max, (int) $row[$report['bar']]);
        }
    }
    echo '<table><thead><tr>';
    foreach ($report['columns'] as $key => $label) {
        echo '<th' . (in_array($key, $report['num'], true) ? ' class="num"' : '') . '>' . e($label) . '</th>';
    }
    if ($report['bar']) {
        echo '<th><span class="visually-hidden">Chart</span></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($report['rows'] as $row) {
        echo '<tr>';
        foreach (array_keys($report['columns']) as $key) {
            echo report_cell($report, $key, $row[$key]);
        }
        if ($report['bar']) {
            $width = round((int) $row[$report['bar']] / $max * 100);
            echo '<td class="bar-cell"><span class="bar" style="width:' . $width . '%"></span></td>';
        }
        echo '</tr>';
    }
    if (!$report['rows']) {
        $columns = count($report['columns']) + ($report['bar'] ? 1 : 0);
        echo '<tr><td colspan="' . $columns . '">No data for this period.</td></tr>';
    }
    echo '</tbody></table>';
}

/** One report cell: ticket links, SLA pills, numbers right-aligned, a dash for empty values. */
function report_cell(array $report, string $key, mixed $raw): string
{
    $value = $raw === null ? '' : (string) $raw;
    $class = in_array($key, $report['num'], true) ? ' class="num"' : '';
    if (($report['link'] ?? null) === $key) {
        $html = '<a href="ticket.php?id=' . (int) $value . '">#' . (int) $value . '</a>';
    } elseif ($key === 'sla_state') {
        $html = '<span class="sla sla-' . strtolower(str_replace(' ', '-', $value)) . '">' . e($value) . '</span>';
    } else {
        $html = $value === '' ? '&ndash;' : e($value);
    }
    return "<td$class>$html</td>";
}
