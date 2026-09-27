<?php
declare(strict_types=1);

// Ticket status workflow: Open -> Assigned -> In Progress -> Resolved -> Closed.
// A ticket may stay where it is or move forward exactly one step (by sort_order).
// The only backward move is reopening: Resolved -> In Progress. Closed is final.

const WORKFLOW_REOPEN = ['Resolved' => 'In Progress'];

/**
 * @param array $statuses rows with id, name, sort_order (ordered by sort_order)
 * @return int[] status ids the ticket may move to, including its current status
 */
function allowed_status_ids(array $statuses, int $currentId): array
{
    $byId = [];
    foreach ($statuses as $status) {
        $byId[(int) $status['id']] = $status;
    }
    if (!isset($byId[$currentId])) {
        return [];
    }
    $current = $byId[$currentId];
    $allowed = [$currentId];
    foreach ($statuses as $status) {
        $isNextStep = (int) $status['sort_order'] === (int) $current['sort_order'] + 1;
        $isReopen = (WORKFLOW_REOPEN[$current['name']] ?? null) === $status['name'];
        if ($isNextStep || $isReopen) {
            $allowed[] = (int) $status['id'];
        }
    }
    return $allowed;
}

function workflow_error(array $statuses, int $currentId, int $targetId): ?string
{
    if (in_array($targetId, allowed_status_ids($statuses, $currentId), true)) {
        return null;
    }
    $names = array_column($statuses, 'name', 'id');
    $from = $names[$currentId] ?? 'Unknown';
    $to = $names[$targetId] ?? 'Unknown';
    $options = array_map(fn (int $id) => $names[$id], array_diff(allowed_status_ids($statuses, $currentId), [$currentId]));
    $next = $options ? implode(' or ', $options) : 'no other status (Closed is final)';
    return "Status cannot move from {$from} to {$to}. Tickets must follow Open -> Assigned -> In Progress -> Resolved -> Closed; from {$from} the next allowed status is {$next}.";
}
