<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$ticketId = valid_id($_GET['id'] ?? null);
if (!$ticketId) { http_response_code(400); exit('Invalid ticket ID.'); }
$stmt = $pdo->prepare('SELECT t.*, s.name AS status, p.name AS priority, c.name AS category, requester.name AS requester, assignee.name AS assignee
    FROM tickets t JOIN statuses s ON s.id=t.status_id JOIN priorities p ON p.id=t.priority_id JOIN categories c ON c.id=t.category_id
    JOIN users requester ON requester.id=t.requester_id LEFT JOIN users assignee ON assignee.id=t.assigned_to WHERE t.id=?');
$stmt->execute([$ticketId]); $ticket = $stmt->fetch();
if (!$ticket) { http_response_code(404); exit('Ticket not found.'); }
if (!can_view_ticket($ticket)) { http_response_code(403); exit('403 Forbidden: employees may only view their own tickets.'); }
$notesStmt = $pdo->prepare('SELECT n.note, n.created_at, u.name AS author FROM ticket_notes n JOIN users u ON u.id=n.author_id WHERE n.ticket_id=? ORDER BY n.created_at');
$notesStmt->execute([$ticketId]); $notes = $notesStmt->fetchAll();
$historyStmt = $pdo->prepare('SELECT h.*, u.name AS changed_by_name FROM ticket_history h JOIN users u ON u.id=h.changed_by WHERE h.ticket_id=? ORDER BY h.created_at');
$historyStmt->execute([$ticketId]); $history = $historyStmt->fetchAll();
$pageTitle = 'Ticket #' . $ticketId; require __DIR__ . '/partials/header.php';
?>
<h1>Ticket #<?= $ticketId ?>: <?= e($ticket['title']) ?></h1>
<div class="grid"><div class="card"><strong>Requester</strong><br><?= e($ticket['requester']) ?></div><div class="card"><strong>Category</strong><br><?= e($ticket['category']) ?></div><div class="card"><strong>Priority</strong><br><?= e($ticket['priority']) ?></div><div class="card"><strong>Status</strong><br><?= e($ticket['status']) ?></div><div class="card"><strong>Assigned to</strong><br><?= e($ticket['assignee'] ?? 'Unassigned') ?></div></div>
<section class="card"><h2>Description</h2><p><?= nl2br(e($ticket['description'])) ?></p><?php if ($ticket['resolution']): ?><h2>Resolution</h2><p><?= nl2br(e($ticket['resolution'])) ?></p><?php endif; ?></section>
<?php if (has_role('technician', 'administrator')): ?><p><a class="button" href="ticket-edit.php?id=<?= $ticketId ?>">Manage Ticket</a></p><?php endif; ?>
<section class="card"><h2>Technical notes</h2><?php foreach ($notes as $note): ?><article><p><?= nl2br(e($note['note'])) ?></p><p class="meta">By <?= e($note['author']) ?> on <?= e($note['created_at']) ?></p></article><?php endforeach; ?><?php if (!$notes): ?><p>No notes yet.</p><?php endif; ?></section>
<section class="card"><h2>History</h2><ul><?php foreach ($history as $item): ?><li><?= e($item['created_at']) ?> - <?= e($item['changed_by_name']) ?>: <?= e($item['action']) ?><?= $item['new_value'] ? ' -> ' . e($item['new_value']) : '' ?></li><?php endforeach; ?></ul></section>
<?php require __DIR__ . '/partials/footer.php'; ?>

