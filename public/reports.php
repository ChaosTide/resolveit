<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/reports.php';
require_role('administrator');

[$fromDate, $toDate, $rangeNotice] = report_range($_GET);
$from = $fromDate->format('Y-m-d');
$to = $toDate->format('Y-m-d');
$toExclusive = $toDate->modify('+1 day')->format('Y-m-d');
$definitions = report_definitions();

// CSV export: ?export=<report key> or ?export=tickets for one row per ticket.
$export = $_GET['export'] ?? null;
if (is_string($export)) {
    if ($export === 'tickets') {
        $columns = ['id' => 'ID', 'title' => 'Title', 'requester' => 'Requester', 'category' => 'Category', 'priority' => 'Priority',
            'status' => 'Status', 'assignee' => 'Assigned to', 'created_at' => 'Created', 'resolved_at' => 'Resolved', 'closed_at' => 'Closed',
            'sla_hours' => 'SLA target (h)', 'due_at' => 'Due', 'sla_state' => 'SLA'];
        send_csv("cts4911-tickets-$from-to-$to.csv", $columns, ticket_export_rows($pdo, $from, $toExclusive));
    }
    if (!isset($definitions[$export])) { http_response_code(404); exit('Unknown report.'); }
    $report = run_report($pdo, $export, $from, $toExclusive);
    $suffix = $report['scope'] === 'range' ? "$from-to-$to" : 'current-' . date('Y-m-d');
    send_csv("cts4911-$export-$suffix.csv", $report['columns'], $report['rows']);
}

$summary = report_summary($pdo, $from, $toExclusive);
$reports = [];
foreach (array_keys($definitions) as $key) {
    $reports[$key] = run_report($pdo, $key, $from, $toExclusive);
}
$rangeQuery = http_build_query(['from' => $from, 'to' => $to]);
$presets = [
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last 90 days' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];

function render_report(array $report): void
{
    $max = 1;
    if ($report['bar']) {
        foreach ($report['rows'] as $row) { $max = max($max, (int) $row[$report['bar']]); }
    }
    echo '<table><thead><tr>';
    foreach ($report['columns'] as $key => $label) {
        echo '<th' . (in_array($key, $report['num'], true) ? ' class="num"' : '') . '>' . e($label) . '</th>';
    }
    if ($report['bar']) echo '<th><span class="visually-hidden">Chart</span></th>';
    echo '</tr></thead><tbody>';
    foreach ($report['rows'] as $row) {
        echo '<tr>';
        foreach ($report['columns'] as $key => $label) {
            $value = $row[$key] === null ? '' : (string) $row[$key];
            $class = in_array($key, $report['num'], true) ? ' class="num"' : '';
            if (($report['link'] ?? null) === $key) {
                $value = '<a href="ticket.php?id=' . (int) $value . '">#' . (int) $value . '</a>';
            } elseif ($key === 'sla_state') {
                $value = '<span class="sla sla-' . strtolower(str_replace(' ', '-', $value)) . '">' . e($value) . '</span>';
            } else {
                $value = $value === '' ? '&ndash;' : e($value);
            }
            echo "<td$class>$value</td>";
        }
        if ($report['bar']) {
            $width = round((int) $row[$report['bar']] / $max * 100);
            echo '<td class="bar-cell"><span class="bar" style="width:' . $width . '%"></span></td>';
        }
        echo '</tr>';
    }
    if (!$report['rows']) {
        echo '<tr><td colspan="' . (count($report['columns']) + ($report['bar'] ? 1 : 0)) . '">No data for this period.</td></tr>';
    }
    echo '</tbody></table>';
}
$pageTitle = 'Reports'; require __DIR__ . '/partials/header.php';
?>
<h1>Reports</h1>
<section class="card">
<form method="get" class="range-form">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" required></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" required></div>
    <button type="submit">Apply</button>
</form>
<p class="presets"><?php foreach ($presets as $label => [$presetFrom, $presetTo]): ?><a href="reports.php?<?= e(http_build_query(['from' => $presetFrom, 'to' => $presetTo])) ?>"><?= e($label) ?></a><?php endforeach; ?></p>
<?php if ($rangeNotice): ?><p class="errors"><?= e($rangeNotice) ?></p><?php endif; ?>
<p class="meta">Showing tickets created <?= e($fromDate->format('M j, Y')) ?> &ndash; <?= e($toDate->format('M j, Y')) ?>. Reports marked <em>current</em> ignore the dates and show the backlog right now.
<a class="button secondary" href="reports.php?<?= e($rangeQuery) ?>&amp;export=tickets">Export all tickets in range (CSV)</a></p>
</section>

<div class="grid">
    <div class="card stat"><span class="meta">Created in period</span><strong><?= $summary['created'] ?></strong></div>
    <div class="card stat"><span class="meta">Resolved (of those)</span><strong><?= $summary['resolved'] ?></strong></div>
    <div class="card stat"><span class="meta">Met SLA in period</span><strong><?= $summary['compliance'] === null ? '&ndash;' : $summary['compliance'] . '%' ?></strong></div>
    <div class="card stat"><span class="meta">Unresolved now</span><strong><?= $summary['unresolved'] ?></strong></div>
    <div class="card stat<?= $summary['overdue'] ? ' alert-stat' : '' ?>"><span class="meta">Overdue now</span><strong><?= $summary['overdue'] ?></strong></div>
    <div class="card stat"><span class="meta">Unresolved &amp; unassigned</span><strong><?= $summary['unassigned'] ?></strong></div>
</div>

<div class="report-grid">
<?php $number = 0; foreach ($reports as $key => $report): $number++; if ($key === 'sla') echo '</div>'; ?>
<section class="card" id="report-<?= $key ?>">
    <div class="report-head">
        <h2><?= $number ?>. <?= e($report['title']) ?><?= $report['scope'] === 'current' ? ' <span class="meta">(current)</span>' : '' ?></h2>
        <a href="reports.php?<?= e($rangeQuery) ?>&amp;export=<?= $key ?>">CSV</a>
    </div>
    <?php render_report($report); ?>
</section>
<?php endforeach; ?>
<p class="meta">SLA targets are set per priority in <a href="settings.php?tab=priorities">Settings &rarr; Priorities</a>. A ticket meets its SLA when it is resolved within the target number of hours after it was created. "Met SLA" counts tickets that were resolved on time out of all tickets that were resolved or are already overdue.</p>
<?php require __DIR__ . '/partials/footer.php'; ?>
