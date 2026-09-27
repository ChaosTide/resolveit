<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('technician', 'administrator');
$ticketId = valid_id($_GET['id'] ?? $_POST['id'] ?? null);
if (!$ticketId) { http_response_code(400); exit('Invalid ticket ID.'); }
$stmt = $pdo->prepare('SELECT * FROM tickets WHERE id=?'); $stmt->execute([$ticketId]); $ticket = $stmt->fetch();
if (!$ticket) { http_response_code(404); exit('Ticket not found.'); }
$statuses = $pdo->query('SELECT id,name,sort_order FROM statuses ORDER BY sort_order')->fetchAll();
$priorities = $pdo->query('SELECT id,name FROM priorities ORDER BY sort_order')->fetchAll();
$technicians = $pdo->query("SELECT u.id,u.name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name IN ('technician','administrator') AND u.active=1 ORDER BY u.name")->fetchAll();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $statusId = valid_id($_POST['status_id'] ?? null); $priorityId = valid_id($_POST['priority_id'] ?? null);
    $assignedTo = ($_POST['assigned_to'] ?? '') === '' ? null : valid_id($_POST['assigned_to']);
    $note = clean_text($_POST['note'] ?? ''); $resolution = clean_text($_POST['resolution'] ?? '');
    $statusMap = array_column($statuses, 'name', 'id');
    $validPriorityIds = array_map('intval', array_column($priorities, 'id'));
    $validTechIds = array_map('intval', array_column($technicians, 'id'));
    if (!$statusId || !isset($statusMap[$statusId])) $errors[] = 'Choose a valid status.';
    if (!$priorityId || !in_array($priorityId, $validPriorityIds, true)) $errors[] = 'Choose a valid priority.';
    if ($assignedTo !== null && !in_array($assignedTo, $validTechIds, true)) $errors[] = 'Choose a valid active technician.';
    if ($assignedTo === null && $statusId && $statusMap[$statusId] !== 'Open') $errors[] = 'Only an Open ticket may remain unassigned.';
    if ($statusId && in_array($statusMap[$statusId], ['Resolved','Closed'], true) && mb_strlen($resolution) < 10) $errors[] = 'A resolution of at least 10 characters is required before resolving or closing.';
    if (mb_strlen($note) > 5000 || mb_strlen($resolution) > 5000) $errors[] = 'Notes and resolution must be 5,000 characters or fewer.';
    if (!$errors) {
        $pdo->beginTransaction();
        $resolvedAt = in_array($statusMap[$statusId], ['Resolved','Closed'], true) ? date('Y-m-d H:i:s') : null;
        $closedAt = $statusMap[$statusId] === 'Closed' ? date('Y-m-d H:i:s') : null;
        $update = $pdo->prepare('UPDATE tickets SET assigned_to=?, priority_id=?, status_id=?, resolution=?, resolved_at=?, closed_at=?, updated_at=SYSDATETIME() WHERE id=?');
        $update->execute([$assignedTo, $priorityId, $statusId, $resolution ?: null, $resolvedAt, $closedAt, $ticketId]);
        $history = $pdo->prepare('INSERT INTO ticket_history (ticket_id, changed_by, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)');
        if ((int)$ticket['status_id'] !== $statusId) $history->execute([$ticketId,current_user()['id'],'status changed',(string)$ticket['status_id'],$statusMap[$statusId]]);
        if ((int)$ticket['priority_id'] !== $priorityId) $history->execute([$ticketId,current_user()['id'],'priority changed',(string)$ticket['priority_id'],(string)$priorityId]);
        if (($ticket['assigned_to'] === null ? null : (int)$ticket['assigned_to']) !== $assignedTo) $history->execute([$ticketId,current_user()['id'],'assignment changed',(string)$ticket['assigned_to'],(string)$assignedTo]);
        if ($note !== '') { $addNote=$pdo->prepare('INSERT INTO ticket_notes (ticket_id,author_id,note) VALUES (?,?,?)'); $addNote->execute([$ticketId,current_user()['id'],$note]); }
        $pdo->commit(); flash('success','Ticket updated.'); redirect('ticket.php?id='.$ticketId);
    }
}
$pageTitle='Manage Ticket'; require __DIR__.'/partials/header.php';
?>
<section class="card"><h1>Manage Ticket #<?= $ticketId ?></h1><?php foreach($errors as $error): ?><p class="errors"><?= e($error) ?></p><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $ticketId ?>">
<label for="assigned_to">Assign to</label><select id="assigned_to" name="assigned_to"><option value="">Unassigned</option><?php foreach($technicians as $tech): ?><option value="<?= (int)$tech['id'] ?>" <?= (int)$ticket['assigned_to']===(int)$tech['id']?'selected':'' ?>><?= e($tech['name']) ?></option><?php endforeach; ?></select>
<label for="priority_id">Priority</label><select id="priority_id" name="priority_id"><?php foreach($priorities as $priority): ?><option value="<?= (int)$priority['id'] ?>" <?= (int)$ticket['priority_id']===(int)$priority['id']?'selected':'' ?>><?= e($priority['name']) ?></option><?php endforeach; ?></select>
<label for="status_id">Status</label><select id="status_id" name="status_id"><?php foreach($statuses as $status): ?><option value="<?= (int)$status['id'] ?>" <?= (int)$ticket['status_id']===(int)$status['id']?'selected':'' ?>><?= e($status['name']) ?></option><?php endforeach; ?></select>
<label for="note">New technical note</label><textarea id="note" name="note" maxlength="5000"></textarea>
<label for="resolution">Resolution (required for Resolved or Closed)</label><textarea id="resolution" name="resolution" maxlength="5000"><?= e($ticket['resolution']) ?></textarea>
<button type="submit">Save Changes</button></form></section>
<?php require __DIR__.'/partials/footer.php'; ?>
