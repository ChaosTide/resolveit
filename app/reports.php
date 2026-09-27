<?php
declare(strict_types=1);

// Report definitions shared by the Reports page and its CSV export.
// SLA: a ticket is due priorities.sla_hours after it was created. It is "On time" or "Late"
// once resolved, otherwise "On track" or "Overdue" depending on the current time.

const SLA_STATE_SQL = "CASE WHEN t.resolved_at IS NOT NULL
        THEN CASE WHEN t.resolved_at <= DATEADD(hour, p.sla_hours, t.created_at) THEN 'On time' ELSE 'Late' END
    WHEN SYSDATETIME() > DATEADD(hour, p.sla_hours, t.created_at) THEN 'Overdue' ELSE 'On track' END";

/** Ticket rows joined with priority/status names, SLA due time, and SLA state. */
function ticket_sla_base(): string
{
    return 'SELECT t.id, t.title, t.requester_id, t.assigned_to, t.category_id, t.created_at, t.resolved_at, t.closed_at,
        p.name AS priority, p.sort_order AS priority_order, p.sla_hours, s.name AS status, s.sort_order AS status_order,
        DATEADD(hour, p.sla_hours, t.created_at) AS due_at, ' . SLA_STATE_SQL . ' AS sla_state
        FROM tickets t JOIN priorities p ON p.id = t.priority_id JOIN statuses s ON s.id = t.status_id';
}

/**
 * Reads ?from=YYYY-MM-DD&to=YYYY-MM-DD (inclusive). Defaults to the last 30 days.
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: ?string}
 */
function report_range(array $query): array
{
    $today = new DateTimeImmutable('today');
    $defaultFrom = $today->modify('-29 days');
    $parse = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ? (DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null) : null;
    $from = isset($query['from']) && $query['from'] !== '' ? $parse($query['from']) : $defaultFrom;
    $to = isset($query['to']) && $query['to'] !== '' ? $parse($query['to']) : $today;
    if (!$from || !$to) {
        return [$defaultFrom, $today, 'Dates must look like 2026-09-01. Showing the last 30 days instead.'];
    }
    if ($from > $to) {
        return [$to, $from, 'The start date was after the end date, so they were swapped.'];
    }
    return [$from, $to, null];
}

function resolved_sort_order(PDO $pdo): int
{
    return (int) $pdo->query("SELECT sort_order FROM statuses WHERE name = 'Resolved'")->fetchColumn();
}

/** Headline numbers: range-based counts plus the current backlog. */
function report_summary(PDO $pdo, string $from, string $toExclusive): array
{
    $resolved = resolved_sort_order($pdo);
    $stmt = $pdo->prepare('SELECT
        SUM(CASE WHEN x.created_at >= ? AND x.created_at < ? THEN 1 ELSE 0 END) AS created,
        SUM(CASE WHEN x.created_at >= ? AND x.created_at < ? AND x.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN x.created_at >= ? AND x.created_at < ? AND x.sla_state = \'On time\' THEN 1 ELSE 0 END) AS on_time,
        SUM(CASE WHEN x.created_at >= ? AND x.created_at < ? AND x.sla_state IN (\'Late\', \'Overdue\') THEN 1 ELSE 0 END) AS missed,
        SUM(CASE WHEN x.status_order < ? THEN 1 ELSE 0 END) AS unresolved,
        SUM(CASE WHEN x.status_order < ? AND x.sla_state = \'Overdue\' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN x.status_order < ? AND x.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
        FROM (' . ticket_sla_base() . ') x');
    $stmt->execute([$from, $toExclusive, $from, $toExclusive, $from, $toExclusive, $from, $toExclusive, $resolved, $resolved, $resolved]);
    $row = array_map('intval', $stmt->fetch());
    $decided = $row['on_time'] + $row['missed'];
    $row['compliance'] = $decided ? round($row['on_time'] / $decided * 100) : null;
    return $row;
}

/**
 * Every report the page shows. Each returns title, scope ('range' or 'current'), columns,
 * rows, and display hints: bar (column drawn as a bar), num (right-aligned), link (ticket id).
 */
function report_definitions(): array
{
    return [
        'status' => ['title' => 'Tickets by status', 'scope' => 'range'],
        'priority' => ['title' => 'Unresolved tickets by priority', 'scope' => 'current'],
        'category' => ['title' => 'Tickets by category', 'scope' => 'range'],
        'workload' => ['title' => 'Technician workload', 'scope' => 'current'],
        'resolution' => ['title' => 'Time to resolve by priority', 'scope' => 'range'],
        'daily' => ['title' => 'New tickets per day', 'scope' => 'range'],
        'sla' => ['title' => 'SLA compliance by priority', 'scope' => 'range'],
        'due' => ['title' => 'Unresolved tickets by due time', 'scope' => 'current'],
    ];
}

function run_report(PDO $pdo, string $key, string $from, string $toExclusive): array
{
    $resolved = resolved_sort_order($pdo);
    $base = ticket_sla_base();
    $meta = report_definitions()[$key];
    switch ($key) {
        case 'status':
            $stmt = $pdo->prepare('SELECT s.name AS label, COUNT(t.id) AS total FROM statuses s
                LEFT JOIN tickets t ON t.status_id = s.id AND t.created_at >= ? AND t.created_at < ?
                GROUP BY s.name, s.sort_order ORDER BY s.sort_order');
            $stmt->execute([$from, $toExclusive]);
            return $meta + ['columns' => ['label' => 'Status', 'total' => 'Tickets'], 'rows' => $stmt->fetchAll(), 'bar' => 'total', 'num' => ['total']];
        case 'priority':
            $stmt = $pdo->prepare('SELECT p.name AS label, COUNT(t.id) AS total FROM priorities p
                LEFT JOIN (tickets t JOIN statuses s ON s.id = t.status_id AND s.sort_order < ?) ON t.priority_id = p.id
                GROUP BY p.name, p.sort_order ORDER BY p.sort_order DESC');
            $stmt->execute([$resolved]);
            return $meta + ['columns' => ['label' => 'Priority', 'total' => 'Tickets'], 'rows' => $stmt->fetchAll(), 'bar' => 'total', 'num' => ['total']];
        case 'category':
            $stmt = $pdo->prepare('SELECT c.name + CASE WHEN c.active = 0 THEN N\' (inactive)\' ELSE N\'\' END AS label, COUNT(t.id) AS total
                FROM categories c LEFT JOIN tickets t ON t.category_id = c.id AND t.created_at >= ? AND t.created_at < ?
                GROUP BY c.name, c.active ORDER BY total DESC, c.name');
            $stmt->execute([$from, $toExclusive]);
            return $meta + ['columns' => ['label' => 'Category', 'total' => 'Tickets'], 'rows' => $stmt->fetchAll(), 'bar' => 'total', 'num' => ['total']];
        case 'workload':
            $stmt = $pdo->prepare("SELECT u.name + CASE WHEN u.active = 0 THEN N' (inactive)' ELSE N'' END AS label,
                SUM(CASE WHEN x.status_order < ? THEN 1 ELSE 0 END) AS in_queue,
                SUM(CASE WHEN x.status_order < ? AND x.sla_state = 'Overdue' THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN x.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved
                FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN ($base) x ON x.assigned_to = u.id
                WHERE r.name IN ('technician', 'administrator')
                GROUP BY u.name, u.active ORDER BY in_queue DESC, u.name");
            $stmt->execute([$resolved, $resolved]);
            return $meta + ['columns' => ['label' => 'Technician', 'in_queue' => 'In queue', 'overdue' => 'Overdue', 'resolved' => 'Resolved (all time)'], 'rows' => $stmt->fetchAll(), 'bar' => null, 'num' => ['in_queue', 'overdue', 'resolved']];
        case 'resolution':
            $stmt = $pdo->prepare("SELECT p.name AS label, p.sla_hours AS target_hours, COUNT(x.id) AS resolved,
                CAST(AVG(CAST(DATEDIFF(minute, x.created_at, x.resolved_at) AS FLOAT)) / 60.0 AS DECIMAL(10,1)) AS avg_hours,
                CAST(MAX(DATEDIFF(minute, x.created_at, x.resolved_at)) / 60.0 AS DECIMAL(10,1)) AS max_hours
                FROM priorities p LEFT JOIN ($base) x ON x.priority_order = p.sort_order AND x.resolved_at IS NOT NULL AND x.created_at >= ? AND x.created_at < ?
                GROUP BY p.name, p.sort_order, p.sla_hours ORDER BY p.sort_order DESC");
            $stmt->execute([$from, $toExclusive]);
            return $meta + ['columns' => ['label' => 'Priority', 'target_hours' => 'Target (h)', 'resolved' => 'Resolved', 'avg_hours' => 'Avg hours', 'max_hours' => 'Longest (h)'], 'rows' => $stmt->fetchAll(), 'bar' => null, 'num' => ['target_hours', 'resolved', 'avg_hours', 'max_hours']];
        case 'daily':
            $stmt = $pdo->prepare('SELECT CONVERT(CHAR(10), CAST(created_at AS DATE), 23) AS label, COUNT(*) AS total FROM tickets
                WHERE created_at >= ? AND created_at < ? GROUP BY CAST(created_at AS DATE) ORDER BY label DESC');
            $stmt->execute([$from, $toExclusive]);
            return $meta + ['columns' => ['label' => 'Date', 'total' => 'Tickets'], 'rows' => $stmt->fetchAll(), 'bar' => 'total', 'num' => ['total']];
        case 'sla':
            $stmt = $pdo->prepare("SELECT p.name AS label, p.sla_hours AS target_hours, COUNT(x.id) AS tickets,
                SUM(CASE WHEN x.sla_state = 'On time' THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN x.sla_state = 'Late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN x.sla_state = 'Overdue' THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN x.sla_state = 'On track' THEN 1 ELSE 0 END) AS on_track
                FROM priorities p LEFT JOIN ($base) x ON x.priority_order = p.sort_order AND x.created_at >= ? AND x.created_at < ?
                GROUP BY p.name, p.sort_order, p.sla_hours ORDER BY p.sort_order DESC");
            $stmt->execute([$from, $toExclusive]);
            $rows = array_map(function (array $row) {
                $decided = (int) $row['on_time'] + (int) $row['late'] + (int) $row['overdue'];
                $row['compliance'] = $decided ? round((int) $row['on_time'] / $decided * 100) . '%' : '';
                return $row;
            }, $stmt->fetchAll());
            return $meta + ['columns' => ['label' => 'Priority', 'target_hours' => 'Target (h)', 'tickets' => 'Tickets', 'on_time' => 'On time', 'late' => 'Late', 'overdue' => 'Overdue', 'on_track' => 'On track', 'compliance' => 'Met SLA'], 'rows' => $rows, 'bar' => null, 'num' => ['target_hours', 'tickets', 'on_time', 'late', 'overdue', 'on_track', 'compliance']];
        case 'due':
            $stmt = $pdo->prepare("SELECT TOP 25 x.id, x.title, x.status, x.priority, a.name AS assignee,
                CONVERT(CHAR(16), x.due_at, 120) AS due_at,
                CAST(DATEDIFF(minute, SYSDATETIME(), x.due_at) / 60.0 AS DECIMAL(10,1)) AS hours_left, x.sla_state
                FROM ($base) x LEFT JOIN users a ON a.id = x.assigned_to
                WHERE x.status_order < ? ORDER BY x.due_at");
            $stmt->execute([$resolved]);
            $rows = array_map(fn ($row) => ['assignee' => $row['assignee'] ?? 'Unassigned'] + $row, $stmt->fetchAll());
            return $meta + ['columns' => ['id' => 'ID', 'title' => 'Title', 'status' => 'Status', 'priority' => 'Priority', 'assignee' => 'Assigned to', 'due_at' => 'Due', 'hours_left' => 'Hours left', 'sla_state' => 'SLA'], 'rows' => $rows, 'bar' => null, 'num' => ['hours_left'], 'link' => 'id'];
    }
    throw new InvalidArgumentException("Unknown report: $key");
}

/** Raw ticket export for the selected date range (one row per ticket). */
function ticket_export_rows(PDO $pdo, string $from, string $toExclusive): array
{
    $stmt = $pdo->prepare('SELECT x.id, x.title, r.name AS requester, c.name AS category, x.priority, x.status,
        a.name AS assignee, x.created_at, x.resolved_at, x.closed_at, x.sla_hours, x.due_at, x.sla_state
        FROM (' . ticket_sla_base() . ') x JOIN users r ON r.id = x.requester_id JOIN categories c ON c.id = x.category_id
        LEFT JOIN users a ON a.id = x.assigned_to WHERE x.created_at >= ? AND x.created_at < ? ORDER BY x.id');
    $stmt->execute([$from, $toExclusive]);
    return $stmt->fetchAll();
}

/** Sends rows as a UTF-8 CSV download that opens cleanly in Excel. */
function send_csv(string $filename, array $columns, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($columns), ',', '"', '');
    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $value = (string) ($row[$key] ?? '');
            // Stop spreadsheet formula injection from ticket titles and names.
            if ($value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $value = "'" . $value;
            }
            $line[] = $value;
        }
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}
