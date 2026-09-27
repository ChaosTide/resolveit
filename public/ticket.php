<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$ticketId = valid_id($_GET['id'] ?? $_POST['id'] ?? null);
if (!$ticketId) { http_response_code(400); exit('Invalid ticket ID.'); }
$stmt = $pdo->prepare('SELECT q.*, t.description, t.resolution FROM (' . ticket_list_sql() . ') q JOIN tickets t ON t.id = q.id WHERE q.id = ?');
$stmt->execute([$ticketId]); $ticket = $stmt->fetch();
if (!$ticket) { http_response_code(404); exit('Ticket not found.'); }
if (!can_view_ticket($ticket)) { http_response_code(403); exit('403 Forbidden: employees may only view their own tickets.'); }
$staff = has_role('technician', 'administrator');
$isClosed = $ticket['status'] === 'Closed';
$partialView = !empty($_GET['partial']); // split-view pane: content only, no page layout

// Replies: the requester and staff can add to the conversation until the ticket is closed.
$errors = [];
$reply = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reply = clean_text($_POST['note'] ?? '');
    if ($isClosed) $errors[] = 'This ticket is closed. Create a new ticket if the problem is back.';
    elseif (mb_strlen($reply) < 2 || mb_strlen($reply) > 5000) $errors[] = 'A reply must be between 2 and 5,000 characters.';
    if (!$errors) {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ticket_notes (ticket_id, author_id, note) VALUES (?, ?, ?)')->execute([$ticketId, current_user()['id'], $reply]);
        $pdo->prepare('UPDATE tickets SET updated_at = SYSDATETIME() WHERE id = ?')->execute([$ticketId]);
        if ($staff) record_first_response($pdo, $ticketId);
        $pdo->commit();
        flash('success', 'Reply added.');
        // Replies sent from the split view return to the same list; only local ticket-list URLs are accepted.
        $back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
        if (preg_match('#^tickets\.php(\?[A-Za-z0-9_\-=&%\[\].]*)?$#', $back)) redirect($back);
        redirect('ticket.php?id=' . $ticketId . '#conversation');
    }
}

$notesStmt = $pdo->prepare('SELECT n.note, n.created_at, u.name AS author, r.name AS role FROM ticket_notes n
    JOIN users u ON u.id = n.author_id JOIN roles r ON r.id = u.role_id WHERE n.ticket_id = ? ORDER BY n.created_at, n.id');
$notesStmt->execute([$ticketId]); $notes = $notesStmt->fetchAll();
$historyStmt = $pdo->prepare('SELECT h.*, u.name AS changed_by_name FROM ticket_history h JOIN users u ON u.id = h.changed_by WHERE h.ticket_id = ? ORDER BY h.created_at, h.id');
$historyStmt->execute([$ticketId]); $history = $historyStmt->fetchAll();
$workflow = $pdo->query('SELECT name, sort_order FROM statuses ORDER BY sort_order')->fetchAll();
$pageTitle = 'Ticket #' . $ticketId; require __DIR__ . '/partials/header.php';
?>
<?php if ($partialView): ?><div class="pane-toolbar"><a class="button secondary small" href="ticket.php?id=<?= $ticketId ?>">Open full page</a><button type="button" class="icon-button" data-close-pane aria-label="Close preview">&times;</button></div><?php endif; ?>
<div class="page-header">
    <div>
        <p class="breadcrumb"><a href="tickets.php"><?= $staff ? 'Tickets' : 'My Tickets' ?></a> / #<?= $ticketId ?></p>
        <h1>Ticket #<?= $ticketId ?>: <?= e($ticket['title']) ?></h1>
        <div class="ticket-heading">
            <?= status_badge($ticket['status']) ?> <?= priority_badge($ticket['priority'], (int) $ticket['priority_order']) ?>
            <?php if ($staff): ?><?= sla_badge($ticket['sla_state'], $ticket['due_at'], (int) $ticket['sla_hours']) ?><?php endif; ?>
            <span class="meta">Opened <?= relative_time($ticket['created_at']) ?> by <?= e($ticket['requester']) ?></span>
        </div>
    </div>
    <?php if ($staff): ?><div class="page-actions"><a class="button" href="ticket-edit.php?id=<?= $ticketId ?>"><?= icon('settings') ?>Manage Ticket</a></div><?php endif; ?>
</div>

<div class="two-col">
    <div>
        <section class="card">
            <h2>Description</h2>
            <p class="description"><?= e($ticket['description']) ?></p>
            <?php if ($ticket['resolution']): ?>
                <div class="resolution-box"><h2><?= icon('check') ?> Resolution</h2><p class="description"><?= e($ticket['resolution']) ?></p></div>
            <?php endif; ?>
        </section>

        <section class="card" id="conversation">
            <h2>Conversation</h2>
            <?php foreach ($notes as $note): ?>
                <article class="comment"><?= avatar($note['author']) ?>
                    <div class="comment-body">
                        <div class="comment-meta"><strong><?= e($note['author']) ?></strong><?= $note['role'] !== 'employee' ? '<span class="staff-tag">IT Staff</span>' : '' ?> &middot; <?= relative_time($note['created_at']) ?></div>
                        <p><?= e($note['note']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$notes): ?><p class="meta">No replies yet.</p><?php endif; ?>

            <?php foreach ($errors as $error): ?><p class="errors" role="alert"><?= e($error) ?></p><?php endforeach; ?>
            <?php if (!$isClosed): ?>
                <form method="post" action="ticket.php?id=<?= $ticketId ?>#conversation">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $ticketId ?>">
                    <input type="hidden" name="back" value="" data-back-url>
                    <label for="note"><?= $staff ? 'Add a note' : 'Reply to IT' ?></label>
                    <textarea id="note" name="note" maxlength="5000" placeholder="<?= $staff ? 'Visible to the requester and IT staff.' : 'Add details, answer a question, or tell us if the problem changed.' ?>" required><?= e($reply) ?></textarea>
                    <button type="submit">Post Reply</button>
                </form>
            <?php else: ?>
                <p class="meta">This ticket is closed. <a href="ticket-create.php">Create a new ticket</a> if the problem comes back.</p>
            <?php endif; ?>
        </section>
    </div>

    <aside>
        <section class="card">
            <h2>Details</h2>
            <div class="workflow" aria-label="Workflow progress">
                <?php foreach ($workflow as $i => $step): $state = (int) $step['sort_order'] < (int) $ticket['status_order'] ? 'done' : ((int) $step['sort_order'] === (int) $ticket['status_order'] ? 'current' : ''); ?>
                    <?= $i ? '<span class="arrow">&rsaquo;</span>' : '' ?><span class="<?= $state ?>"><?= e($step['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <dl class="details">
                <div><dt>Status</dt><dd data-field="status"><?= status_badge($ticket['status']) ?></dd></div>
                <div><dt>Priority</dt><dd data-field="priority"><?= priority_badge($ticket['priority'], (int) $ticket['priority_order']) ?></dd></div>
                <div><dt>Category</dt><dd><?= e($ticket['category']) ?></dd></div>
                <div><dt>Requester</dt><dd><span class="person"><?= avatar($ticket['requester']) ?><span><?= e($ticket['requester']) ?><?php if ($ticket['requester_organization']): ?><span class="ticket-sub"><?= e($ticket['requester_organization']) ?></span><?php endif; ?></span></span></dd></div>
                <div><dt>Assigned to</dt><dd data-field="assignee"><span class="person"><?= avatar($ticket['assignee']) ?><?= e($ticket['assignee'] ?? 'Unassigned') ?></span></dd></div>
                <?php if ($staff): ?>
                <div><dt>SLA due</dt><dd data-field="sla"><?= e(format_time($ticket['due_at'])) ?><br><?= sla_badge($ticket['sla_state'], $ticket['due_at'], (int) $ticket['sla_hours']) ?> <span class="meta"><?= (int) $ticket['sla_hours'] ?>h target</span></dd></div>
                <?php endif; ?>
                <div><dt>Due date</dt><dd data-field="due-date"><?= $ticket['due_date'] ? e(date('M j, Y', strtotime((string) $ticket['due_date']))) . ($ticket['past_due'] ? ' <span class="sla sla-overdue">Past due</span>' : '') : '<span class="meta">None</span>' ?></dd></div>
                <?php if ($staff): ?>
                <div><dt>First response</dt><dd data-field="response"><?= $ticket['response_minutes'] !== null ? e(duration_text((int) $ticket['response_minutes'] * 60)) : '<span class="meta">Waiting for IT</span>' ?></dd></div>
                <?php endif; ?>
                <div><dt>Created</dt><dd><?= e(format_time($ticket['created_at'])) ?></dd></div>
                <div><dt>Updated</dt><dd><?= relative_time($ticket['updated_at']) ?></dd></div>
                <?php if ($ticket['resolved_at']): ?><div><dt>Resolved</dt><dd><?= e(format_time($ticket['resolved_at'])) ?></dd></div><?php endif; ?>
                <?php if ($ticket['closed_at']): ?><div><dt>Closed</dt><dd><?= e(format_time($ticket['closed_at'])) ?> <span class="meta">(<?= e(duration_text((int) $ticket['close_minutes'] * 60)) ?> to close)</span></dd></div><?php endif; ?>
            </dl>
        </section>
        <section class="card">
            <h2>History</h2>
            <ul class="timeline">
            <?php foreach ($history as $item): ?>
                <li><strong><?= e($item['changed_by_name']) ?></strong> <?= e($item['action']) ?><?= $item['old_value'] !== null && $item['old_value'] !== '' ? ': ' . e($item['old_value']) . ' &rarr; ' : ($item['new_value'] ? ' &rarr; ' : '') ?><?= e($item['new_value']) ?>
                    <div class="when"><?= e(format_time($item['created_at'])) ?></div></li>
            <?php endforeach; ?>
            </ul>
        </section>
    </aside>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
