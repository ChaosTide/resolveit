<?php

declare(strict_types=1);

// Shared by the ticket list (tickets.php) and advanced search (search.php):
// the base query, column sorting, and the change "stamp" used for automatic refresh.

/** ticket_list_sql() plus the raw ids and long text fields that filters need. Rows are aliased "q" by callers. */
function ticket_query_sql(): string
{
    return 'SELECT l.*, t.status_id, t.priority_id, t.description, t.resolution
        FROM (' . ticket_list_sql() . ') l JOIN tickets t ON t.id = l.id';
}

/** Sort keys: label for the dropdown (null = column header only) and default direction. */
function ticket_sort_options(bool $staff): array
{
    $sorts = [
        'due' => ['SLA due soonest', 'asc'],
        'updated' => ['Recently updated', 'desc'],
        'newest' => ['Newest first', 'desc'],
        'priority' => ['Highest priority', 'desc'],
        'duedate' => ['Due date', 'asc'],
        'id' => [null, 'desc'],
        'title' => [null, 'asc'],
        'requester' => [null, 'asc'],
        'status' => [null, 'asc'],
        'assignee' => [null, 'asc'],
    ];
    if (!$staff) {
        unset($sorts['due'], $sorts['requester']);
    }
    return $sorts;
}

/** ORDER BY for a sort key and direction. Keys and directions are whitelisted, never raw input. */
function ticket_order_sql(string $key, string $dir, int $resolvedOrder): string
{
    $d = $dir === 'asc' ? 'ASC' : 'DESC';
    $expr = [
        // Unresolved tickets by SLA due time, then resolved/closed ones.
        'due' => "CASE WHEN q.status_order < $resolvedOrder THEN 0 ELSE 1 END, q.due_at $d",
        'updated' => "q.updated_at $d",
        'newest' => "q.created_at $d",
        'priority' => "q.priority_order $d, q.created_at ASC",
        'duedate' => "CASE WHEN q.due_date IS NULL THEN 1 ELSE 0 END, q.due_date $d",
        'id' => "q.id $d",
        'title' => "q.title $d",
        'requester' => "q.requester $d",
        'status' => "q.status_order $d",
        'assignee' => "CASE WHEN q.assignee IS NULL THEN 1 ELSE 0 END, q.assignee $d",
    ][$key] ?? "q.updated_at DESC";
    return $expr . ', q.id DESC';
}

/** Reads ?sort=&dir= against the whitelist; returns [key, dir]. */
function ticket_sort_from_query(array $query, bool $staff, string $default): array
{
    $sorts = ticket_sort_options($staff);
    $key = is_string($query['sort'] ?? null) && isset($sorts[$query['sort']]) ? $query['sort'] : $default;
    $dir = in_array($query['dir'] ?? null, ['asc', 'desc'], true) ? $query['dir'] : $sorts[$key][1];
    return [$key, $dir];
}

/**
 * A fingerprint of every ticket matching the filters (not just the current page). The page polls
 * ?format=stamp and refreshes the list only when this changes.
 */
function ticket_stamp(PDO $pdo, string $whereSql, array $params): string
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, CONVERT(VARCHAR(30), MAX(q.updated_at), 126) AS m,
        CHECKSUM_AGG(CHECKSUM(q.id, q.status_id, q.priority_id, q.assigned_to, q.updated_at, q.due_date)) AS k
        FROM (" . ticket_query_sql() . ") q WHERE $whereSql");
    $stmt->execute($params);
    return md5(json_encode($stmt->fetch()));
}

function send_stamp(string $stamp): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['stamp' => $stamp, 'checked_at' => date(DATE_ATOM)]);
    exit;
}

/** The "Assigned to" filter from ?assignee=: 'me', 'none', a user id, or null. Employees never filter by it. */
function assignee_filter(array $query, bool $staff): int|string|null
{
    if (!$staff) {
        return null;
    }
    $value = $query['assignee'] ?? null;
    return in_array($value, ['me', 'none'], true) ? $value : valid_id($value);
}

/** CSV columns for ticket exports (the list and advanced search share them). */
function ticket_csv_columns(bool $staff): array
{
    $columns = [
        'id' => 'ID',
        'title' => 'Summary',
        'requester' => 'Requester',
        'requester_organization' => 'Organization',
        'assignee' => 'Assignee',
        'priority' => 'Priority',
        'category' => 'Category',
        'status' => 'Status',
        'waiting' => 'Waiting on requester',
        'created_at' => 'Created',
        'updated_at' => 'Updated',
        'due_date' => 'Due date',
        'response_time' => 'Response time',
        'close_time' => 'Close time',
    ];
    if ($staff) {
        $columns += ['sla_state' => 'SLA', 'due_at' => 'SLA due'];
    }
    return $columns;
}

function ticket_csv_row(array $t): array
{
    $t['response_time'] = $t['response_minutes'] !== null ? duration_text((int) $t['response_minutes'] * 60) : '';
    $t['close_time'] = $t['close_minutes'] !== null ? duration_text((int) $t['close_minutes'] * 60) : '';
    $t['due_date'] = $t['due_date'] ? substr((string) $t['due_date'], 0, 10) : '';
    $t['waiting'] = $t['waiting'] && $t['resolved_at'] === null ? 'Yes' : '';
    return $t;
}

/** Escapes a user search term for LIKE ... ESCAPE '\'. */
function like_pattern(string $term): string
{
    return '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '[' => '\\[']) . '%';
}

/** Parses a YYYY-MM-DD query value; null when missing or invalid. */
function query_date(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}
