<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$me = (int) current_user()['id'];
$staff = !has_role('employee');
$resolvedOrder = resolved_sort_order($pdo);
$list = ticket_list_sql();

if ($staff) {
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN x.status_order < ? THEN 1 ELSE 0 END) AS unresolved,
        SUM(CASE WHEN x.status_order < ? AND x.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned,
        SUM(CASE WHEN x.status_order < ? AND x.sla_state = 'Overdue' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN x.status_order < ? AND x.sla_state = 'On track'
            AND x.due_at <= DATEADD(hour, 4, SYSDATETIME()) THEN 1 ELSE 0 END) AS due_soon,
        SUM(CASE WHEN x.status_order < ? AND x.assigned_to = ? THEN 1 ELSE 0 END) AS mine,
        SUM(CASE WHEN x.resolved_at >= CAST(SYSDATETIME() AS DATE) THEN 1 ELSE 0 END) AS resolved_today,
        SUM(CASE WHEN x.status_order < ? AND x.waiting = 1 THEN 1 ELSE 0 END) AS waiting,
        SUM(CASE WHEN x.past_due = 1 THEN 1 ELSE 0 END) AS past_due
        FROM ($list) x");
    $r = $resolvedOrder;
    $stmt->execute([$r, $r, $r, $r, $r, $me, $r]);
    $kpi = array_map('intval', $stmt->fetch());

    $queue = $pdo->prepare("SELECT TOP 10 * FROM ($list) q
        WHERE q.status_order < ? AND q.assigned_to = ? ORDER BY q.due_at");
    $queue->execute([$resolvedOrder, $me]);
    $queue = $queue->fetchAll();

    $attention = $pdo->prepare("SELECT TOP 10 * FROM ($list) q
        WHERE q.status_order < ? AND (q.sla_state = 'Overdue' OR q.assigned_to IS NULL) ORDER BY q.due_at");
    $attention->execute([$resolvedOrder]);
    $attention = $attention->fetchAll();

    $activity = $pdo->query('SELECT TOP 8 h.ticket_id, h.action, h.old_value, h.new_value, h.created_at,
            u.name AS actor, t.title
        FROM ticket_history h JOIN users u ON u.id = h.changed_by JOIN tickets t ON t.id = h.ticket_id
        ORDER BY h.id DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN s.sort_order < ? THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN s.name = 'Resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN s.name = 'Closed' THEN 1 ELSE 0 END) AS closed
        FROM tickets t JOIN statuses s ON s.id = t.status_id WHERE t.requester_id = ?");
    $stmt->execute([$resolvedOrder, $me]);
    $kpi = array_map('intval', $stmt->fetch());
    $recent = $pdo->prepare("SELECT TOP 10 * FROM ($list) q WHERE q.requester_id = ? ORDER BY q.updated_at DESC");
    $recent->execute([$me]);
    $recent = $recent->fetchAll();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
$firstName = explode(' ', current_user()['name'])[0];
$subtitle = $staff
    ? 'Here is what needs attention across the service desk.'
    : 'Track your requests or tell us about a new problem.';
?>
<div class="page-header">
    <div>
        <h1>Welcome back, <?= e($firstName) ?></h1>
        <p class="subtitle"><?= $subtitle ?></p>
    </div>
    <div class="page-actions"><a class="button" href="ticket-create.php"><?= icon('plus') ?>New Ticket</a></div>
</div>

<?php if ($staff) : ?>
<?php
// Staff dashboard = a menu on the left; clicking an item opens its panel on the right (app.js,
// [data-dash-menu]). Each entry: [menu label, icon, tone, count shown in the menu or null, panel HTML].
// Without JavaScript every panel simply shows, stacked, so nothing is lost.
ob_start(); ?>
<section class="card">
    <h2>Recent activity</h2>
    <?php if (!$activity) : ?><p class="meta">No ticket activity yet.</p><?php endif; ?>
    <ul class="timeline">
    <?php foreach ($activity as $item) : ?>
        <li>
            <strong><?= e($item['actor']) ?></strong> <?= e($item['action']) ?>
            <?= $item['new_value'] ? ' &rarr; ' . e($item['new_value']) : '' ?>
            on <a href="ticket.php?id=<?= (int) $item['ticket_id'] ?>">#<?= (int) $item['ticket_id'] ?></a>
            <div class="when">
                <?= relative_time($item['created_at']) ?> &middot; <?= e(mb_strimwidth($item['title'], 0, 40, '…')) ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php
$activityHtml = ob_get_clean();
$dashPanels = [
    'open' => ['Open tickets', 'inbox', 'primary', $kpi['unresolved'], kpi_tile(
        'Open tickets',
        $kpi['unresolved'],
        'inbox',
        'primary',
        'tickets.php?view=open',
        'Not yet resolved'
    )],
    'unassigned' => ['Unassigned', 'user', 'violet', $kpi['unassigned'], kpi_tile(
        'Unassigned',
        $kpi['unassigned'],
        'user',
        'violet',
        'tickets.php?view=unassigned',
        'Waiting for a technician'
    )],
    'risk' => ['At risk', 'alert', 'danger', $kpi['overdue'], stat_group('At risk', 'alert', [
        ['label' => 'Overdue', 'value' => $kpi['overdue'], 'tone' => 'danger', 'href' => 'tickets.php?view=overdue'],
        [
            'label' => 'Due in 4h', 'value' => $kpi['due_soon'], 'tone' => 'warning',
            'href' => 'tickets.php?view=open&sort=due',
        ],
        [
            'label' => 'Past due date', 'value' => $kpi['past_due'], 'tone' => 'danger',
            'href' => 'tickets.php?view=pastdue',
        ],
    ])],
    'day' => ['Your day', 'clock', 'success', $kpi['resolved_today'], stat_group('Your day', 'user', [
        ['label' => 'My queue', 'value' => $kpi['mine'], 'tone' => 'primary', 'href' => 'tickets.php?view=mine'],
        [
            'label' => 'Resolved today', 'value' => $kpi['resolved_today'], 'tone' => 'success',
            'href' => 'tickets.php?view=resolved',
        ],
        [
            'label' => 'Waiting on requester', 'value' => $kpi['waiting'], 'tone' => 'violet',
            'href' => 'tickets.php?view=waiting',
        ],
    ])],
    'queue' => ['My queue', 'tickets', 'primary', $kpi['mine'], '<section class="card flush">'
        . '<div class="card-header"><h2>My queue</h2><a href="tickets.php?view=mine">View all</a></div>'
        . ticket_table($queue, true, 'Nothing assigned to you right now.') . '</section>'],
    'attention' => ['Needs attention', 'alert', 'warning', count($attention), '<section class="card flush">'
        . '<div class="card-header"><h2>Needs attention</h2><a href="tickets.php?view=overdue">Overdue list</a></div>'
        . ticket_table($attention, true, 'No overdue or unassigned tickets. The queue is healthy.') . '</section>'],
    'activity' => ['Recent activity', 'chart', 'primary', null, $activityHtml],
];
// Opens on your own queue when you have one, otherwise on what needs attention.
$dashDefault = $kpi['mine'] > 0 ? 'queue' : 'attention';
?>
<div class="dash-layout" data-dash-menu data-default="<?= $dashDefault ?>">
    <div class="dash-menu" role="tablist" aria-orientation="vertical" aria-label="Dashboard sections">
    <?php foreach ($dashPanels as $key => [$label, $iconName, $tone, $count]) : ?>
        <button type="button" class="dash-menu-item tone-<?= $tone ?>" role="tab" id="dash-tab-<?= $key ?>"
            aria-controls="dash-<?= $key ?>" aria-selected="false" data-dash-key="<?= $key ?>">
            <?= icon($iconName) ?><span class="dash-menu-label"><?= e($label) ?></span>
            <?php if ($count !== null) : ?>
                <span class="dash-menu-count<?= $count > 0 ? ' has-value' : '' ?>"><?= (int) $count ?></span>
            <?php endif; ?>
            <span class="dash-menu-chevron"><?= icon('arrow-right') ?></span>
        </button>
    <?php endforeach; ?>
    </div>
    <div class="dash-panels">
    <?php foreach ($dashPanels as $key => $panel) : ?>
        <div class="dash-panel" role="tabpanel" id="dash-<?= $key ?>" aria-labelledby="dash-tab-<?= $key ?>">
            <?= $panel[4] ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php else : ?>
<div class="row">
    <div class="col-12 col-sm-6 col-xl-3">
    <?= kpi_tile('Open requests', $kpi['open_count'], 'inbox', 'primary', 'tickets.php?view=open', 'Being worked on') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
    <?= kpi_tile(
        'Resolved',
        $kpi['resolved'],
        'check',
        'success',
        'tickets.php?view=resolved',
        'Fixed, waiting to close'
    ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
    <?= kpi_tile('Closed', $kpi['closed'], 'tickets', 'violet', 'tickets.php?view=closed', 'Finished') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
    <?= kpi_tile(
        'All my tickets',
        $kpi['total'],
        'dashboard',
        'primary',
        'tickets.php',
        'Everything you have submitted'
    ) ?>
    </div>
</div>
<section class="card flush">
    <div class="card-header"><h2>My recent tickets</h2><a href="tickets.php">View all</a></div>
    <?= ticket_table($recent, false, 'You have not submitted any tickets yet.') ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
