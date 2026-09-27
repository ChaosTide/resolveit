<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$me = (int) current_user()['id'];
$staff = !has_role('employee');
$resolvedOrder = resolved_sort_order($pdo);
$perPage = 20;

$statuses = $pdo->query('SELECT id, name FROM statuses ORDER BY sort_order')->fetchAll();
$priorities = $pdo->query('SELECT id, name FROM priorities ORDER BY sort_order DESC')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$technicians = $staff ? $pdo->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN ('technician','administrator') ORDER BY u.name")->fetchAll() : [];

// Quick views (chips). Each maps to a WHERE fragment on the ticket_list_sql() row alias "q".
$views = ['all' => 'All', 'open' => 'Open', 'waiting' => $staff ? 'Waiting on requester' : 'Awaiting my reply'];
if ($staff) $views += ['mine' => 'My queue', 'unassigned' => 'Unassigned', 'overdue' => 'SLA overdue', 'pastdue' => 'Past due'];
$views += ['resolved' => 'Resolved', 'closed' => 'Closed'];
$viewSql = [
    'all' => ['1 = 1', []],
    'open' => ['q.status_order < ?', [$resolvedOrder]],
    'mine' => ['q.status_order < ? AND q.assigned_to = ?', [$resolvedOrder, $me]],
    'unassigned' => ['q.status_order < ? AND q.assigned_to IS NULL', [$resolvedOrder]],
    'overdue' => ["q.status_order < ? AND q.sla_state = 'Overdue'", [$resolvedOrder]],
    'waiting' => ['q.status_order < ? AND q.waiting = 1', [$resolvedOrder]],
    'pastdue' => ['q.past_due = 1', []],
    'resolved' => ["q.status = 'Resolved'", []],
    'closed' => ["q.status = 'Closed'", []],
];

$filters = [
    'view' => array_key_exists($_GET['view'] ?? '', $views) ? $_GET['view'] : 'all',
    'q' => mb_substr(clean_text($_GET['q'] ?? ''), 0, 100),
    'status' => valid_id($_GET['status'] ?? null),
    'priority' => valid_id($_GET['priority'] ?? null),
    'category' => valid_id($_GET['category'] ?? null),
    'assignee' => $staff ? (in_array($_GET['assignee'] ?? '', ['me', 'none'], true) ? $_GET['assignee'] : valid_id($_GET['assignee'] ?? null)) : null,
];
$sorts = ['updated' => 'Recently updated', 'newest' => 'Newest first', 'priority' => 'Highest priority'];
if ($staff) $sorts = ['due' => 'SLA due soonest'] + $sorts;
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : ($staff ? 'due' : 'updated');
$orderBy = [
    // Unresolved tickets by due time first, then resolved/closed ones by most recent.
    'due' => "CASE WHEN q.status_order < $resolvedOrder THEN 0 ELSE 1 END, CASE WHEN q.status_order < $resolvedOrder THEN q.due_at END, q.updated_at DESC",
    'updated' => 'q.updated_at DESC',
    'newest' => 'q.created_at DESC',
    'priority' => 'q.priority_order DESC, q.created_at',
][$sort];

// Base scope: employees only ever see their own tickets.
$scopeWhere = $staff ? ['1 = 1'] : ['q.requester_id = ?'];
$scopeParams = $staff ? [] : [$me];

$where = $scopeWhere; $params = $scopeParams;
[$viewWhere, $viewParams] = $viewSql[$filters['view']];
$where[] = $viewWhere; $params = array_merge($params, $viewParams);
if ($filters['q'] !== '') {
    $like = '%' . strtr($filters['q'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '[' => '\\[']) . '%';
    $idSearch = ltrim($filters['q'], '#');
    if (ctype_digit($idSearch) && strlen($idSearch) < 10) {
        $where[] = "(q.id = ? OR q.title LIKE ? ESCAPE '\\' OR q.description LIKE ? ESCAPE '\\')";
        array_push($params, (int) $idSearch, $like, $like);
    } else {
        $where[] = "(q.title LIKE ? ESCAPE '\\' OR q.description LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like);
    }
}
foreach (['status' => 'q.status_id', 'priority' => 'q.priority_id', 'category' => 'q.category_id'] as $key => $column) {
    if ($filters[$key]) { $where[] = "$column = ?"; $params[] = $filters[$key]; }
}
if ($filters['assignee'] === 'me') { $where[] = 'q.assigned_to = ?'; $params[] = $me; }
elseif ($filters['assignee'] === 'none') { $where[] = 'q.assigned_to IS NULL'; }
elseif ($filters['assignee']) { $where[] = 'q.assigned_to = ?'; $params[] = $filters['assignee']; }

// The list query adds the raw ids and description that ticket_list_sql() does not expose.
$list = 'SELECT l.*, t.status_id, t.priority_id, t.description FROM (' . ticket_list_sql() . ') l JOIN tickets t ON t.id = l.id';
$whereSql = implode(' AND ', $where);
$count = $pdo->prepare("SELECT COUNT(*) FROM ($list) q WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM ($list) q WHERE $whereSql ORDER BY $orderBy OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// CSV export of everything matching the current filters (not just this page).
if (($_GET['export'] ?? '') === 'csv') {
    $all = $pdo->prepare("SELECT * FROM ($list) q WHERE $whereSql ORDER BY $orderBy");
    $all->execute($params);
    $rows = array_map(function (array $t) {
        $t['response_time'] = $t['response_minutes'] !== null ? duration_text((int) $t['response_minutes'] * 60) : '';
        $t['close_time'] = $t['close_minutes'] !== null ? duration_text((int) $t['close_minutes'] * 60) : '';
        $t['due_date'] = $t['due_date'] ? substr((string) $t['due_date'], 0, 10) : '';
        $t['waiting'] = $t['waiting'] && $t['resolved_at'] === null ? 'Yes' : '';
        return $t;
    }, $all->fetchAll());
    $columns = ['id' => 'ID', 'title' => 'Summary', 'requester' => 'Requester', 'requester_department' => 'Department',
        'assignee' => 'Assignee', 'priority' => 'Priority', 'category' => 'Category', 'status' => 'Status', 'waiting' => 'Waiting on requester',
        'created_at' => 'Created', 'updated_at' => 'Updated', 'due_date' => 'Due date', 'response_time' => 'Response time', 'close_time' => 'Close time'];
    if ($staff) $columns += ['sla_state' => 'SLA', 'due_at' => 'SLA due'];
    send_csv('cts4911-tickets-' . date('Y-m-d') . '.csv', $columns, $rows);
}

// Counts for the quick-view chips (within the user's scope, ignoring other filters).
$chipCounts = [];
$countSql = [];
$countParams = [];
foreach ($viewSql as $key => [$sql, $p]) {
    if (!isset($views[$key])) continue;
    $countSql[] = "SUM(CASE WHEN $sql THEN 1 ELSE 0 END) AS [$key]";
    $countParams = array_merge($countParams, $p);
}
$chips = $pdo->prepare('SELECT ' . implode(', ', $countSql) . " FROM ($list) q WHERE " . implode(' AND ', $scopeWhere));
$chips->execute(array_merge($countParams, $scopeParams));
$chipCounts = array_map('intval', $chips->fetch() ?: []);

$activeQuery = array_filter(['view' => $filters['view'] === 'all' ? null : $filters['view'], 'q' => $filters['q'] ?: null,
    'status' => $filters['status'], 'priority' => $filters['priority'], 'category' => $filters['category'],
    'assignee' => $filters['assignee'], 'sort' => $sort], fn ($v) => $v !== null && $v !== '');
$hasFilters = $filters['q'] !== '' || $filters['status'] || $filters['priority'] || $filters['category'] || $filters['assignee'];
$pageTitle = $staff ? 'Tickets' : 'My Tickets'; require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $staff ? 'All Tickets' : 'My Tickets' ?></h1>
        <p class="subtitle"><?= $staff ? 'Every request in the service desk, with live SLA status.' : 'Requests you have submitted to the IT help desk.' ?></p>
    </div>
    <div class="page-actions"><a class="button" href="ticket-create.php"><?= icon('plus') ?>Create Ticket</a></div>
</div>

<nav class="quick-filters" aria-label="Quick views">
<?php foreach ($views as $key => $label): ?>
    <a class="chip" href="tickets.php?<?= e(http_build_query(array_filter(['view' => $key === 'all' ? null : $key, 'sort' => $sort]))) ?>"<?= $filters['view'] === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span class="count"><?= $chipCounts[$key] ?? 0 ?></span></a>
<?php endforeach; ?>
</nav>

<form class="card filters" method="get" action="tickets.php">
    <?php if ($filters['view'] !== 'all'): ?><input type="hidden" name="view" value="<?= e($filters['view']) ?>"><?php endif; ?>
    <div class="filter wide"><label for="f-q">Search</label><input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Ticket #, title, or description"></div>
    <div class="filter"><label for="f-status">Status</label><select id="f-status" name="status" data-autosubmit><option value="">Any status</option>
        <?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $filters['status'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="filter"><label for="f-priority">Priority</label><select id="f-priority" name="priority" data-autosubmit><option value="">Any priority</option>
        <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $filters['priority'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="filter"><label for="f-category">Category</label><select id="f-category" name="category" data-autosubmit><option value="">Any category</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <?php if ($staff): ?>
    <div class="filter"><label for="f-assignee">Assigned to</label><select id="f-assignee" name="assignee" data-autosubmit><option value="">Anyone</option>
        <option value="me"<?= $filters['assignee'] === 'me' ? ' selected' : '' ?>>Me</option>
        <option value="none"<?= $filters['assignee'] === 'none' ? ' selected' : '' ?>>Unassigned</option>
        <?php foreach ($technicians as $tech): ?><option value="<?= (int) $tech['id'] ?>"<?= $filters['assignee'] === (int) $tech['id'] ? ' selected' : '' ?>><?= e($tech['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="filter"><label for="f-sort">Sort</label><select id="f-sort" name="sort" data-autosubmit>
        <?php foreach ($sorts as $key => $label): ?><option value="<?= $key ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <button type="submit"><?= icon('search') ?>Apply</button>
    <?php if ($hasFilters): ?><a class="button secondary" href="tickets.php<?= $filters['view'] !== 'all' ? '?view=' . e($filters['view']) : '' ?>">Clear</a><?php endif; ?>
</form>

<div class="list-toolbar">
    <p class="result-count"><?= $total ?> ticket<?= $total === 1 ? '' : 's' ?><?= $hasFilters ? ' match your filters' : '' ?></p>
    <a class="button secondary small" href="tickets.php?<?= e(http_build_query($activeQuery + ['export' => 'csv'])) ?>"><?= icon('download') ?>Export CSV</a>
</div>
<?php if ($staff): ?>
<form id="bulk-form" class="card bulk-bar" method="post" action="tickets-bulk.php" data-bulk-form>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="return" value="<?= e(http_build_query($activeQuery)) ?>">
    <strong class="bulk-count" data-bulk-count>Bulk update</strong>
    <select name="assigned_to" aria-label="Assign selected tickets to"><option value="">Assign to… (no change)</option><option value="none">Unassigned</option>
        <?php foreach ($technicians as $tech): ?><option value="<?= (int) $tech['id'] ?>"><?= e($tech['name']) ?></option><?php endforeach; ?></select>
    <select name="priority_id" aria-label="Set priority of selected tickets"><option value="">Priority… (no change)</option>
        <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <button type="submit" class="small">Apply to selected</button>
    <span class="meta">Select tickets with the checkboxes. Status changes stay one ticket at a time so the workflow is enforced.</span>
</form>
<?php endif; ?>
<?= ticket_table($tickets, $staff, $hasFilters || $filters['view'] !== 'all' ? 'No tickets match these filters.' : 'No tickets found.', $staff) ?>
<?= pagination($page, $pages, $activeQuery) ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
