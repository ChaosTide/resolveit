<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$baseSql = 'SELECT t.id, t.title, t.created_at, t.updated_at, t.requester_id,
                   s.name AS status, p.name AS priority, c.name AS category,
                   requester.name AS requester, assignee.name AS assignee
            FROM tickets t
            JOIN statuses s ON s.id = t.status_id
            JOIN priorities p ON p.id = t.priority_id
            JOIN categories c ON c.id = t.category_id
            JOIN users requester ON requester.id = t.requester_id
            LEFT JOIN users assignee ON assignee.id = t.assigned_to';
if (has_role('employee')) {
    $stmt = $pdo->prepare($baseSql . ' WHERE t.requester_id = ? ORDER BY t.updated_at DESC');
    $stmt->execute([current_user()['id']]);
} else {
    $stmt = $pdo->query($baseSql . ' ORDER BY s.sort_order, p.sort_order DESC, t.created_at');
}
$tickets = $stmt->fetchAll();
$pageTitle = 'Tickets';
require __DIR__ . '/partials/header.php';
?>
<h1><?= has_role('employee') ? 'My Tickets' : 'All Tickets' ?></h1>
<p>Signed in as <?= e(current_user()['name']) ?> (<?= e(current_user()['role']) ?>).</p>
<p><a class="button" href="ticket-create.php">Create Ticket</a></p>
<table>
    <thead><tr><th>ID</th><th>Title</th><th>Requester</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned To</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $ticket): ?>
        <tr>
            <td><a href="ticket.php?id=<?= (int) $ticket['id'] ?>">#<?= (int) $ticket['id'] ?></a></td>
            <td><?= e($ticket['title']) ?></td><td><?= e($ticket['requester']) ?></td><td><?= e($ticket['category']) ?></td>
            <td><?= e($ticket['priority']) ?></td><td><?= e($ticket['status']) ?></td><td><?= e($ticket['assignee'] ?? 'Unassigned') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$tickets): ?><tr><td colspan="7">No tickets found.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/partials/footer.php'; ?>

