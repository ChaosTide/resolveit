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
        $columns = [
            'id' => 'ID',
            'title' => 'Title',
            'requester' => 'Requester',
            'category' => 'Category',
            'priority' => 'Priority',
            'status' => 'Status',
            'assignee' => 'Assigned to',
            'created_at' => 'Created',
            'resolved_at' => 'Resolved',
            'closed_at' => 'Closed',
            'sla_hours' => 'SLA target (h)',
            'due_at' => 'Due',
            'sla_state' => 'SLA',
        ];
        send_csv("resolveit-tickets-$from-to-$to.csv", $columns, ticket_export_rows($pdo, $from, $toExclusive));
    }
    if (!isset($definitions[$export])) {
        http_response_code(404);
        exit('Unknown report.');
    }
    $report = run_report($pdo, $export, $from, $toExclusive);
    $suffix = $report['scope'] === 'range' ? "$from-to-$to" : 'current-' . date('Y-m-d');
    send_csv("resolveit-$export-$suffix.csv", $report['columns'], $report['rows']);
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
$compliance = $summary['compliance'] === null ? '&ndash;' : $summary['compliance'] . '%';

$pageTitle = 'Reports';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1>Reports</h1>
        <p class="subtitle">Ticket volume, workload, and SLA performance. Every table exports to CSV.</p>
    </div>
</div>
<section class="card">
<form method="get" class="range-form">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" required></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" required></div>
    <button type="submit">Apply</button>
</form>
<p class="presets">
    <?php foreach ($presets as $label => [$presetFrom, $presetTo]) : ?>
        <?php $presetQuery = http_build_query(['from' => $presetFrom, 'to' => $presetTo]); ?>
        <a href="reports.php?<?= e($presetQuery) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</p>
<?php if ($rangeNotice) : ?><p class="errors"><?= e($rangeNotice) ?></p><?php endif; ?>
<p class="meta">
    Showing tickets created <?= e($fromDate->format('M j, Y')) ?> &ndash; <?= e($toDate->format('M j, Y')) ?>.
    Reports marked <em>current</em> ignore the dates and show the backlog right now.
    <a class="button secondary" href="reports.php?<?= e($rangeQuery) ?>&amp;export=tickets">
        Export all tickets in range (CSV)
    </a>
</p>
</section>

<div class="grid dashboard-grid">
    <div class="card stat tone-primary">
        <span class="meta">Met SLA in period</span><strong><?= $compliance ?></strong>
        <span class="stat-note">Resolved on time, of tickets resolved or overdue</span>
    </div>
    <?= stat_group('This period', 'chart', [
        [
            'label' => 'Created', 'value' => $summary['created'], 'tone' => 'primary',
            'href' => '#report-status',
        ],
        [
            'label' => 'Resolved', 'value' => $summary['resolved'], 'tone' => 'success',
            'href' => '#report-status',
        ],
    ]) ?>
    <?= stat_group('Current backlog', 'inbox', [
        [
            'label' => 'Unresolved', 'value' => $summary['unresolved'], 'tone' => 'primary',
            'href' => 'tickets.php?view=open',
        ],
        [
            'label' => 'Overdue', 'value' => $summary['overdue'], 'tone' => 'danger',
            'href' => 'tickets.php?view=overdue',
        ],
        [
            'label' => 'Unassigned', 'value' => $summary['unassigned'], 'tone' => 'violet',
            'href' => 'tickets.php?view=unassigned',
        ],
    ]) ?>
</div>

<div class="report-grid">
<?php $number = 0; ?>
<?php foreach ($reports as $key => $report) : ?>
    <?php
    $number++;
    // The SLA and due-time tables are wide, so they sit below the two-column grid.
    if ($key === 'sla') {
        echo '</div>';
    }
    ?>
<section class="card" id="report-<?= $key ?>">
    <div class="report-head">
        <h2><?= $number ?>. <?= e($report['title']) ?>
            <?php if ($report['scope'] === 'current') : ?><span class="meta">(current)</span><?php endif; ?>
        </h2>
        <a href="reports.php?<?= e($rangeQuery) ?>&amp;export=<?= $key ?>">CSV</a>
    </div>
    <?php render_report($report); ?>
</section>
<?php endforeach; ?>
<p class="meta">
    SLA targets are set per priority in <a href="settings.php?tab=priorities">Settings &rarr; Priorities</a>.
    A ticket meets its SLA when it is resolved within the target number of hours after it was created.
    "Met SLA" counts tickets that were resolved on time out of all tickets that were resolved or are already overdue.
</p>
<?php require __DIR__ . '/partials/footer.php'; ?>
