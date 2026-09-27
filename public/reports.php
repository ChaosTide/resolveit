<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('administrator');

// "Unresolved" means any status before Resolved in the workflow.
$resolvedOrder = (int) $pdo->query("SELECT sort_order FROM statuses WHERE name = 'Resolved'")->fetchColumn();

$summary = $pdo->prepare('SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN s.sort_order < ? THEN 1 ELSE 0 END) AS unresolved,
    SUM(CASE WHEN s.sort_order < ? AND t.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned,
    SUM(CASE WHEN t.resolved_at >= DATEADD(day, -7, SYSDATETIME()) THEN 1 ELSE 0 END) AS resolved_week
    FROM tickets t JOIN statuses s ON s.id = t.status_id');
$summary->execute([$resolvedOrder, $resolvedOrder]);
$summary = $summary->fetch();

$byStatus = $pdo->query('SELECT s.name AS label, COUNT(t.id) AS total FROM statuses s LEFT JOIN tickets t ON t.status_id = s.id GROUP BY s.name, s.sort_order ORDER BY s.sort_order')->fetchAll();

$byPriority = $pdo->prepare('SELECT p.name AS label, COUNT(t.id) AS total FROM priorities p
    LEFT JOIN (tickets t JOIN statuses s ON s.id = t.status_id AND s.sort_order < ?) ON t.priority_id = p.id
    GROUP BY p.name, p.sort_order ORDER BY p.sort_order DESC');
$byPriority->execute([$resolvedOrder]); $byPriority = $byPriority->fetchAll();

$byCategory = $pdo->query('SELECT c.name AS label, c.active, COUNT(t.id) AS total FROM categories c LEFT JOIN tickets t ON t.category_id = c.id GROUP BY c.name, c.active ORDER BY total DESC, c.name')->fetchAll();

$workload = $pdo->prepare("SELECT u.name, u.active,
    SUM(CASE WHEN s.sort_order < ? THEN 1 ELSE 0 END) AS in_queue,
    SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved
    FROM users u JOIN roles r ON r.id = u.role_id
    LEFT JOIN tickets t ON t.assigned_to = u.id LEFT JOIN statuses s ON s.id = t.status_id
    WHERE r.name IN ('technician', 'administrator')
    GROUP BY u.name, u.active ORDER BY in_queue DESC, u.name");
$workload->execute([$resolvedOrder]); $workload = $workload->fetchAll();

$resolutionTime = $pdo->query('SELECT p.name AS label, COUNT(t.id) AS resolved,
    AVG(CAST(DATEDIFF(minute, t.created_at, t.resolved_at) AS FLOAT)) / 60.0 AS avg_hours
    FROM priorities p LEFT JOIN tickets t ON t.priority_id = p.id AND t.resolved_at IS NOT NULL
    GROUP BY p.name, p.sort_order ORDER BY p.sort_order DESC')->fetchAll();

$daily = $pdo->query('SELECT CAST(created_at AS DATE) AS label, COUNT(*) AS total FROM tickets
    WHERE created_at >= DATEADD(day, -29, CAST(SYSDATETIME() AS DATE))
    GROUP BY CAST(created_at AS DATE) ORDER BY label DESC')->fetchAll();

$oldest = $pdo->prepare('SELECT TOP 10 t.id, t.title, s.name AS status, p.name AS priority, a.name AS assignee,
    DATEDIFF(day, t.created_at, SYSDATETIME()) AS age_days
    FROM tickets t JOIN statuses s ON s.id = t.status_id JOIN priorities p ON p.id = t.priority_id
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE s.sort_order < ? ORDER BY t.created_at');
$oldest->execute([$resolvedOrder]); $oldest = $oldest->fetchAll();

function bar_rows(array $rows): void
{
    $max = max([1, ...array_map(fn ($row) => (int) $row['total'], $rows)]);
    foreach ($rows as $row) {
        $width = round((int) $row['total'] / $max * 100);
        echo '<tr><td>' . e((string) $row['label']) . (isset($row['active']) && !$row['active'] ? ' <span class="meta">(inactive)</span>' : '') . '</td>'
            . '<td class="num">' . (int) $row['total'] . '</td>'
            . '<td class="bar-cell"><span class="bar" style="width:' . $width . '%"></span></td></tr>';
    }
}
$pageTitle = 'Reports'; require __DIR__ . '/partials/header.php';
?>
<h1>Reports</h1>
<div class="grid">
    <div class="card stat"><span class="meta">Total tickets</span><strong><?= (int) $summary['total'] ?></strong></div>
    <div class="card stat"><span class="meta">Unresolved</span><strong><?= (int) $summary['unresolved'] ?></strong></div>
    <div class="card stat"><span class="meta">Unresolved &amp; unassigned</span><strong><?= (int) $summary['unassigned'] ?></strong></div>
    <div class="card stat"><span class="meta">Resolved in last 7 days</span><strong><?= (int) $summary['resolved_week'] ?></strong></div>
</div>

<div class="report-grid">
<section class="card"><h2>1. Tickets by status</h2>
<table><thead><tr><th>Status</th><th class="num">Tickets</th><th></th></tr></thead><tbody><?php bar_rows($byStatus); ?></tbody></table></section>

<section class="card"><h2>2. Unresolved tickets by priority</h2>
<table><thead><tr><th>Priority</th><th class="num">Tickets</th><th></th></tr></thead><tbody><?php bar_rows($byPriority); ?></tbody></table></section>

<section class="card"><h2>3. Tickets by category</h2>
<table><thead><tr><th>Category</th><th class="num">Tickets</th><th></th></tr></thead><tbody><?php bar_rows($byCategory); ?></tbody></table></section>

<section class="card"><h2>4. Technician workload</h2>
<table><thead><tr><th>Technician</th><th class="num">In queue</th><th class="num">Resolved</th></tr></thead><tbody>
<?php foreach ($workload as $row): ?><tr><td><?= e($row['name']) ?><?= $row['active'] ? '' : ' <span class="meta">(inactive)</span>' ?></td><td class="num"><?= (int) $row['in_queue'] ?></td><td class="num"><?= (int) $row['resolved'] ?></td></tr><?php endforeach; ?>
<?php if (!$workload): ?><tr><td colspan="3">No technicians yet.</td></tr><?php endif; ?>
</tbody></table></section>

<section class="card"><h2>5. Average time to resolve</h2>
<table><thead><tr><th>Priority</th><th class="num">Resolved</th><th class="num">Avg hours</th></tr></thead><tbody>
<?php foreach ($resolutionTime as $row): ?><tr><td><?= e($row['label']) ?></td><td class="num"><?= (int) $row['resolved'] ?></td><td class="num"><?= $row['avg_hours'] === null ? '&ndash;' : number_format((float) $row['avg_hours'], 1) ?></td></tr><?php endforeach; ?>
</tbody></table></section>

<section class="card"><h2>6. New tickets per day (last 30 days)</h2>
<table><thead><tr><th>Date</th><th class="num">Tickets</th><th></th></tr></thead><tbody><?php bar_rows($daily); ?>
<?php if (!$daily): ?><tr><td colspan="3">No tickets created in the last 30 days.</td></tr><?php endif; ?>
</tbody></table></section>
</div>

<section class="card"><h2>7. Oldest unresolved tickets</h2>
<table><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Priority</th><th>Assigned to</th><th class="num">Age (days)</th></tr></thead><tbody>
<?php foreach ($oldest as $row): ?><tr><td><a href="ticket.php?id=<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a></td><td><?= e($row['title']) ?></td><td><?= e($row['status']) ?></td><td><?= e($row['priority']) ?></td><td><?= e($row['assignee'] ?? 'Unassigned') ?></td><td class="num"><?= (int) $row['age_days'] ?></td></tr><?php endforeach; ?>
<?php if (!$oldest): ?><tr><td colspan="6">No unresolved tickets.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
