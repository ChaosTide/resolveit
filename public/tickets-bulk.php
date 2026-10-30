<?php

// Bulk update from the ticket list: assign and/or change priority for several tickets at once.
// Status is deliberately not bulk-editable so every status change goes through the workflow check.
require_once __DIR__ . '/../app/bootstrap.php';
require_role('technician', 'administrator');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('tickets.php');
}
verify_csrf();

parse_str(is_string($_POST['return'] ?? null) ? $_POST['return'] : '', $returnQuery);
$back = 'tickets.php' . ($returnQuery ? '?' . http_build_query($returnQuery) : '');
$fail = function (string $message) use ($back): never {
    flash('error', $message);
    redirect($back);
};

$ids = array_values(array_unique(array_filter(array_map('valid_id', (array) ($_POST['ids'] ?? [])))));
$assignInput = is_string($_POST['assigned_to'] ?? null) ? $_POST['assigned_to'] : '';
$priorityId = valid_id($_POST['priority_id'] ?? null);
if (!$ids) {
    $fail('Select at least one ticket first.');
}
if (count($ids) > 100) {
    $fail('Bulk update is limited to 100 tickets at a time.');
}
if ($assignInput === '' && !$priorityId) {
    $fail('Choose an assignee or a priority to apply.');
}

$assignTo = null;
$assigneeName = null;
$changeAssignee = $assignInput !== '';
if ($changeAssignee && $assignInput !== 'none') {
    $assignTo = valid_id($assignInput);
    $check = $pdo->prepare("SELECT u.name FROM users u JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.active = 1 AND r.name IN ('technician', 'administrator')");
    $check->execute([$assignTo]);
    $assigneeName = $check->fetchColumn();
    if (!$assignTo || $assigneeName === false) {
        $fail('Choose an active technician.');
    }
}
$priorityName = null;
if ($priorityId) {
    $check = $pdo->prepare('SELECT name FROM priorities WHERE id = ?');
    $check->execute([$priorityId]);
    $priorityName = $check->fetchColumn();
    if ($priorityName === false) {
        $fail('Choose a valid priority.');
    }
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT t.id, t.assigned_to, t.priority_id, s.name AS status,
        a.name AS assignee, p.name AS priority
    FROM tickets t
    JOIN statuses s ON s.id = t.status_id
    JOIN priorities p ON p.id = t.priority_id
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE t.id IN ($placeholders)");
$stmt->execute($ids);
$tickets = $stmt->fetchAll();

$updated = 0;
$skipped = [];
$me = (int) current_user()['id'];
$update = $pdo->prepare('UPDATE tickets SET assigned_to = ?, priority_id = ?, updated_at = SYSDATETIME() WHERE id = ?');
$history = $pdo->prepare('INSERT INTO ticket_history (ticket_id, changed_by, action, old_value, new_value)
    VALUES (?, ?, ?, ?, ?)');
$pdo->beginTransaction();
foreach ($tickets as $t) {
    $id = (int) $t['id'];
    $oldAssignee = $t['assigned_to'] === null ? null : (int) $t['assigned_to'];
    $oldPriority = (int) $t['priority_id'];
    $newAssignee = $changeAssignee ? $assignTo : $oldAssignee;
    $newPriority = $priorityId ?: $oldPriority;
    // Same rule as Manage Ticket: only an Open ticket may be unassigned.
    if ($newAssignee === null && $t['status'] !== 'Open') {
        $skipped[] = '#' . $id;
        continue;
    }
    if ($newAssignee === $oldAssignee && $newPriority === $oldPriority) {
        continue;
    }
    $update->execute([$newAssignee, $newPriority, $id]);
    if ($newAssignee !== $oldAssignee) {
        $newName = $newAssignee === null ? 'Unassigned' : $assigneeName;
        $history->execute([$id, $me, 'assignment changed', $t['assignee'] ?? 'Unassigned', $newName]);
    }
    if ($newPriority !== $oldPriority) {
        $history->execute([$id, $me, 'priority changed', $t['priority'], $priorityName]);
    }
    record_first_response($pdo, $id);
    $updated++;
}
$pdo->commit();

$message = "Updated $updated ticket" . ($updated === 1 ? '' : 's') . '.';
if ($skipped) {
    $message .= ' Skipped ' . implode(', ', $skipped) . ': only Open tickets can be unassigned.';
}
flash($updated ? 'success' : 'error', $message);
redirect($back);
