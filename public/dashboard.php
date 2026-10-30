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
        SUM(CASE WHEN x.status_order < ? AND x.sla_state = 'On track' AND x.due_at <= DATEADD(hour, 4, SYSDATETIME()) THEN 1 ELSE 0 END) AS due_soon,
        SUM(CASE WHEN x.status_order < ? AND x.assigned_to = ? THEN 1 ELSE 0 END) AS mine,
        SUM(CASE WHEN x.resolved_at >= CAST(SYSDATETIME() AS DATE) THEN 1 ELSE 0 END) AS resolved_today,
        SUM(CASE WHEN x.status_order < ? AND x.waiting = 1 THEN 1 ELSE 0 END) AS waiting,
        SUM(CASE WHEN x.past_due = 1 THEN 1 ELSE 0 END) AS past_due
        FROM (" . ticket_list_sql() . ") x");
    $stmt->execute([$resolvedOrder, $resolvedOrder, $resolvedOrder, $resolvedOrder, $resolvedOrder, $me, $resolvedOrder]);
    $kpi = array_map('intval', $stmt->fetch());

    $queue = $pdo->prepare("SELECT TOP 10 * FROM ($list) q WHERE q.status_order < ? AND q.assigned_to = ? ORDER BY q.due_at");
    $queue->execute([$resolvedOrder, $me]);
    $queue = $queue->fetchAll();

    $attention = $pdo->prepare("SELECT TOP 10 * FROM ($list) q WHERE q.status_order < ? AND (q.sla_state = 'Overdue' OR q.assigned_to IS NULL) ORDER BY q.due_at");
    $attention->execute([$resolvedOrder]);
    $attention = $attention->fetchAll();

    $activity = $pdo->query('SELECT TOP 8 h.ticket_id, h.action, h.old_value, h.new_value, h.created_at, u.name AS actor, t.title
        FROM ticket_history h JOIN users u ON u.id = h.changed_by JOIN tickets t ON t.id = h.ticket_id ORDER BY h.id DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total,
        SUM(CASE WHEN s.sort_order < ? THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN s.name = \'Resolved\' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN s.name = \'Closed\' THEN 1 ELSE 0 END) AS closed
        FROM tickets t JOIN statuses s ON s.id = t.status_id WHERE t.requester_id = ?');
    $stmt->execute([$resolvedOrder, $me]);
    $kpi = array_map('intval', $stmt->fetch());
    $recent = $pdo->prepare("SELECT TOP 10 * FROM ($list) q WHERE q.requester_id = ? ORDER BY q.updated_at DESC");
    $recent->execute([$me]);
    $recent = $recent->fetchAll();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
$firstName = explode(' ', current_user()['name'])[0];
?>
<div class="page-header">
    <div>
        <h1>Welcome back, <?= e($firstName) ?></h1>
        <p class="subtitle"><?= $staff ? 'Here is what needs attention across the service desk.' : 'Track your requests or tell us about a new problem.' ?></p>
    </div>
    <div class="page-actions"><a class="button" href="ticket-create.php"><?= icon('plus') ?>New Ticket</a></div>
</div>

<?php if ($staff) : ?>
<div class="grid">
    <?= kpi_tile('Open tickets', $kpi['unresolved'], 'inbox', 'primary', 'tickets.php?view=open', 'Not yet resolved') ?>
    <?= kpi_tile('Unassigned', $kpi['unassigned'], 'user', 'violet', 'tickets.php?view=unassigned', 'Waiting for a technician') ?>
    <?= kpi_tile('Overdue', $kpi['overdue'], 'alert', 'danger', 'tickets.php?view=overdue', 'Past their SLA target') ?>
    <?= kpi_tile('Due in 4 hours', $kpi['due_soon'], 'clock', 'warning', 'tickets.php?view=open&sort=due', 'SLA deadline approaching') ?>
    <?= kpi_tile('My queue', $kpi['mine'], 'tickets', 'primary', 'tickets.php?view=mine', 'Assigned to you') ?>
    <?= kpi_tile('Resolved today', $kpi['resolved_today'], 'check', 'success', 'tickets.php?view=resolved', 'Nice work') ?>
    <?= kpi_tile('Waiting on requester', $kpi['waiting'], 'clock', 'violet', 'tickets.php?view=waiting', 'IT replied last') ?>
    <?= kpi_tile('Past due date', $kpi['past_due'], 'alert', 'danger', 'tickets.php?view=pastdue', 'Staff-set due date passed') ?>
</div>

<section class="card flush">
    <div class="card-header"><h2>My queue</h2><a href="tickets.php?view=mine">View all</a></div>
    <?= ticket_table($queue, true, 'Nothing assigned to you right now.') ?>
</section>

<section class="card flush">
        <div class="card-header"><h2>Needs attention</h2><a href="tickets.php?view=overdue">Overdue list</a></div>
        <?= ticket_table($attention, true, 'No overdue or unassigned tickets. The queue is healthy.') ?>
</section>
<section class="card">
        <h2>Recent activity</h2>
        <?php if (!$activity) : ?><p class="meta">No ticket activity yet.</p><?php endif; ?>
        <ul class="timeline">
        <?php foreach ($activity as $item) : ?>
            <li><strong><?= e($item['actor']) ?></strong> <?= e($item['action']) ?><?= $item['new_value'] ? ' &rarr; ' . e($item['new_value']) : '' ?>
                on <a href="ticket.php?id=<?= (int) $item['ticket_id'] ?>">#<?= (int) $item['ticket_id'] ?></a>
                <div class="when"><?= relative_time($item['created_at']) ?> &middot; <?= e(mb_strimwidth($item['title'], 0, 40, '…')) ?></div></li>
        <?php endforeach; ?>
        </ul>
</section>
<?php else : ?>
<div class="grid">
    <?= kpi_tile('Open requests', $kpi['open_count'], 'inbox', 'primary', 'tickets.php?view=open', 'Being worked on') ?>
    <?= kpi_tile('Resolved', $kpi['resolved'], 'check', 'success', 'tickets.php?view=resolved', 'Fixed, waiting to close') ?>
    <?= kpi_tile('Closed', $kpi['closed'], 'tickets', 'violet', 'tickets.php?view=closed', 'Finished') ?>
    <?= kpi_tile('All my tickets', $kpi['total'], 'dashboard', 'primary', 'tickets.php', 'Everything you have submitted') ?>
</div>
<section class="card flush">
    <div class="card-header"><h2>My recent tickets</h2><a href="tickets.php">View all</a></div>
    <?= ticket_table($recent, false, 'You have not submitted any tickets yet.') ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
