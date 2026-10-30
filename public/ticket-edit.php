<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('technician', 'administrator');
$ticketId = valid_id($_GET['id'] ?? $_POST['id'] ?? null);
if (!$ticketId) {
    http_response_code(400);
    exit('Invalid ticket ID.');
}
$stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();
if (!$ticket) {
    http_response_code(404);
    exit('Ticket not found.');
}
$statuses = $pdo->query('SELECT id, name, sort_order FROM statuses ORDER BY sort_order')->fetchAll();
$priorities = $pdo->query('SELECT id, name FROM priorities ORDER BY sort_order')->fetchAll();
$technicians = $pdo->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('technician', 'administrator') AND u.active = 1 ORDER BY u.name")->fetchAll();
$currentStatusId = (int) $ticket['status_id'];
$currentSortOrder = (int) (array_column($statuses, 'sort_order', 'id')[$currentStatusId] ?? 0);
$allowedStatusIds = allowed_status_ids($statuses, $currentStatusId);
$currentDue = $ticket['due_date'] ? substr((string) $ticket['due_date'], 0, 10) : null;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $statusId = valid_id($_POST['status_id'] ?? null);
    $priorityId = valid_id($_POST['priority_id'] ?? null);
    $assignedTo = ($_POST['assigned_to'] ?? '') === '' ? null : valid_id($_POST['assigned_to']);
    $note = clean_text($_POST['note'] ?? '');
    $resolution = clean_text($_POST['resolution'] ?? '');
    $dueInput = clean_text($_POST['due_date'] ?? '');
    $dueDate = $dueInput === '' ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', $dueInput) ?: false);
    $statusMap = array_column($statuses, 'name', 'id');
    $validPriorityIds = array_map('intval', array_column($priorities, 'id'));
    $validTechIds = array_map('intval', array_column($technicians, 'id'));
    $newStatus = $statusId ? ($statusMap[$statusId] ?? null) : null;
    $finishing = in_array($newStatus, ['Resolved', 'Closed'], true);

    if ($newStatus === null) {
        $errors[] = 'Choose a valid status.';
    } else {
        $workflowError = workflow_error($statuses, $currentStatusId, $statusId);
        if ($workflowError) {
            $errors[] = $workflowError;
        }
    }
    if (!$priorityId || !in_array($priorityId, $validPriorityIds, true)) {
        $errors[] = 'Choose a valid priority.';
    }
    if ($assignedTo !== null && !in_array($assignedTo, $validTechIds, true)) {
        $errors[] = 'Choose a valid active technician.';
    }
    if ($assignedTo === null && $newStatus !== null && $newStatus !== 'Open') {
        $errors[] = 'Only an Open ticket may remain unassigned.';
    }
    if ($finishing && mb_strlen($resolution) < 10) {
        $errors[] = 'A resolution of at least 10 characters is required before resolving or closing.';
    }
    if (mb_strlen($note) > 5000 || mb_strlen($resolution) > 5000) {
        $errors[] = 'Notes and resolution must be 5,000 characters or fewer.';
    }
    if ($dueDate === false || ($dueDate && $dueDate->format('Y-m-d') !== $dueInput)) {
        $errors[] = 'Enter the due date as a valid date, or leave it blank.';
    }
    $dueValue = $dueDate ? $dueDate->format('Y-m-d') : null;

    if (!$errors) {
        $me = current_user()['id'];
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        // Keep the original resolved/closed times when a ticket moves Resolved -> Closed; clear them on reopen.
        $resolvedAt = $finishing ? ($ticket['resolved_at'] ?? $now) : null;
        $closedAt = $newStatus === 'Closed' ? ($ticket['closed_at'] ?? $now) : null;
        // The status_id check stops two people from both advancing the same ticket from a stale page.
        $update = $pdo->prepare('UPDATE tickets SET assigned_to = ?, priority_id = ?, status_id = ?, resolution = ?,
                resolved_at = ?, closed_at = ?, due_date = ?, updated_at = SYSDATETIME()
            WHERE id = ? AND status_id = ?');
        $update->execute([
            $assignedTo,
            $priorityId,
            $statusId,
            $resolution ?: null,
            $resolvedAt,
            $closedAt,
            $dueValue,
            $ticketId,
            $currentStatusId,
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            $errors[] = "Someone else changed this ticket's status while you were editing. "
                . 'Reload the page and try again.';
        } else {
            $priorityMap = array_column($priorities, 'name', 'id');
            $userNames = array_column($pdo->query('SELECT id, name FROM users')->fetchAll(), 'name', 'id');
            $oldAssignee = $ticket['assigned_to'] === null ? null : (int) $ticket['assigned_to'];
            $oldPriorityId = (int) $ticket['priority_id'];
            $history = $pdo->prepare('INSERT INTO ticket_history (ticket_id, changed_by, action, old_value, new_value)
                VALUES (?, ?, ?, ?, ?)');
            $log = fn (string $action, ?string $old, ?string $new) => $history->execute(
                [$ticketId, $me, $action, $old, $new]
            );
            if ($currentStatusId !== $statusId) {
                $log('status changed', $statusMap[$currentStatusId], $newStatus);
            }
            if ($oldPriorityId !== $priorityId) {
                $log('priority changed', $priorityMap[$oldPriorityId] ?? null, $priorityMap[$priorityId]);
            }
            if ($oldAssignee !== $assignedTo) {
                $log(
                    'assignment changed',
                    $oldAssignee === null ? 'Unassigned' : ($userNames[$oldAssignee] ?? null),
                    $assignedTo === null ? 'Unassigned' : $userNames[$assignedTo]
                );
            }
            if ($currentDue !== $dueValue) {
                $log('due date changed', $currentDue ?? 'None', $dueValue ?? 'None');
            }
            record_first_response($pdo, $ticketId);
            if ($note !== '') {
                $pdo->prepare('INSERT INTO ticket_notes (ticket_id, author_id, note) VALUES (?, ?, ?)')
                    ->execute([$ticketId, $me, $note]);
            }
            $pdo->commit();
            flash('success', 'Ticket updated.');
            redirect('ticket.php?id=' . $ticketId);
        }
    }
}
$pageTitle = 'Manage Ticket';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <p class="breadcrumb">
            <a href="tickets.php">Tickets</a> / <a href="ticket.php?id=<?= $ticketId ?>">#<?= $ticketId ?></a> / Manage
        </p>
        <h1>Manage Ticket #<?= $ticketId ?></h1>
        <p class="subtitle"><?= e($ticket['title']) ?></p>
    </div>
    <div class="page-actions"><a class="button secondary" href="ticket.php?id=<?= $ticketId ?>">Back to ticket</a></div>
</div>
<section class="card" style="max-width: 820px">
<?php foreach ($errors as $error) : ?>
    <p class="errors" role="alert"><?= e($error) ?></p>
<?php endforeach; ?>
<div class="workflow" aria-label="Workflow">
<?php foreach ($statuses as $i => $step) : ?>
    <?php
    if ((int) $step['sort_order'] < $currentSortOrder) {
        $state = 'done';
    } else {
        $state = (int) $step['id'] === $currentStatusId ? 'current' : '';
    }
    ?>
    <?= $i ? '<span class="arrow">&rsaquo;</span>' : '' ?><span class="<?= $state ?>"><?= e($step['name']) ?></span>
<?php endforeach; ?>
</div>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $ticketId ?>">
    <div class="form-grid">
        <div>
            <label for="status_id">Status</label>
            <select id="status_id" name="status_id">
            <?php foreach ($statuses as $status) : ?>
                <?php
                $optionId = (int) $status['id'];
                if (!in_array($optionId, $allowedStatusIds, true)) {
                    continue;
                }
                $isCurrent = $optionId === $currentStatusId;
                $label = e($status['name']) . ($isCurrent ? ' (current)' : '');
                $selected = $isCurrent ? ' selected' : '';
                ?>
                <option value="<?= $optionId ?>" data-name="<?= e($status['name']) ?>"<?= $selected ?>
                ><?= $label ?></option>
            <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Tickets move one step at a time. A Resolved ticket can be reopened to In Progress; Closed is final.
            </p>
        </div>
        <div>
            <label for="assigned_to">Assign to</label>
            <select id="assigned_to" name="assigned_to">
                <option value="">Unassigned</option>
                <?= select_options(array_column($technicians, 'name', 'id'), $ticket['assigned_to']) ?>
            </select>
            <p class="field-hint">Every status after Open needs a technician.</p>
        </div>
        <div>
            <label for="priority_id">Priority</label>
            <select id="priority_id" name="priority_id">
                <?= select_options(array_column($priorities, 'name', 'id'), $ticket['priority_id']) ?>
            </select>
            <p class="field-hint">Changing priority changes the SLA target.</p>
        </div>
        <div>
            <label for="due_date">Due date</label>
            <input id="due_date" name="due_date" type="date" value="<?= e($currentDue ?? '') ?>">
            <p class="field-hint">Optional. Tickets past this date show as Past Due.</p>
        </div>
    </div>
    <label for="note">New technical note</label>
    <textarea id="note" name="note" maxlength="5000" placeholder="Visible to the requester and IT staff."></textarea>
    <label for="resolution">Resolution</label>
    <textarea id="resolution" name="resolution" maxlength="5000"
        placeholder="What fixed the problem?"><?= e($ticket['resolution']) ?></textarea>
    <p class="field-hint" id="resolution-hint">Required (10+ characters) to set Resolved or Closed.</p>
    <div class="form-actions">
        <button type="submit">Save Changes</button>
        <a class="button secondary" href="ticket.php?id=<?= $ticketId ?>" style="margin-top:1rem">Cancel</a>
    </div>
</form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
