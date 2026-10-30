<?php
// Advanced search: every ticket field, date ranges, and text in descriptions, notes, and resolutions.
// Employees search only their own tickets; the URL is shareable, so a search can be bookmarked.
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$me = (int) current_user()['id'];
$staff = !has_role('employee');
$resolvedOrder = resolved_sort_order($pdo);
$perPage = 25;

$statuses = $pdo->query('SELECT id, name FROM statuses ORDER BY sort_order')->fetchAll();
$priorities = $pdo->query('SELECT id, name FROM priorities ORDER BY sort_order DESC')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$people = [];
$organizations = [];
if ($staff) {
    $people = $pdo->query('SELECT u.id, u.name, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id
        ORDER BY u.name')->fetchAll();
    $organizations = $pdo->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll();
}
$technicians = array_values(array_filter($people, fn ($p) => $p['role'] !== 'employee'));
$slaOptions = [
    'On track' => 'On track (open)',
    'Overdue' => 'Overdue (open)',
    'On time' => 'Met (resolved)',
    'Late' => 'Missed (resolved)',
];

// Criteria, each validated: unknown values are dropped rather than passed to SQL.
$ids = fn (string $key) => array_values(array_unique(array_filter(array_map('valid_id', (array) ($_GET[$key] ?? [])))));
$c = [
    'q' => mb_substr(clean_text($_GET['q'] ?? ''), 0, 100),
    'in_notes' => isset($_GET['in_notes']),
    'statuses' => $ids('status'),
    'priorities' => $ids('priority'),
    'category' => valid_id($_GET['category'] ?? null),
    'requester' => $staff ? valid_id($_GET['requester'] ?? null) : null,
    'assignee' => assignee_filter($_GET, $staff),
    'organization' => $staff ? valid_id($_GET['organization'] ?? null) : null,
    'sla' => $staff && in_array($_GET['sla'] ?? '', array_keys($slaOptions), true) ? $_GET['sla'] : null,
    'waiting' => isset($_GET['waiting']),
    'has_due' => isset($_GET['has_due']),
];
$dateFields = [
    'created' => ['q.created_at', 'Created'],
    'updated' => ['q.updated_at', 'Last updated'],
    'resolved' => ['q.resolved_at', 'Resolved'],
    'due' => ['q.due_date', 'Due date'],
];
$dates = [];
foreach ($dateFields as $key => [$column]) {
    $dates[$key] = [query_date($_GET[$key . '_from'] ?? null), query_date($_GET[$key . '_to'] ?? null)];
}

// Build the WHERE clause; every value goes in $params, never into the SQL text.
$where = $staff ? ['1 = 1'] : ['q.requester_id = ?'];
$params = $staff ? [] : [$me];
if ($c['q'] !== '') {
    $like = like_pattern($c['q']);
    $idSearch = ltrim($c['q'], '#');
    $parts = ["q.title LIKE ? ESCAPE '\\'", "q.description LIKE ? ESCAPE '\\'", "q.resolution LIKE ? ESCAPE '\\'"];
    array_push($params, $like, $like, $like);
    if ($c['in_notes']) {
        $parts[] = "EXISTS (SELECT 1 FROM ticket_notes n WHERE n.ticket_id = q.id AND n.note LIKE ? ESCAPE '\\')";
        $params[] = $like;
    }
    if (ctype_digit($idSearch) && strlen($idSearch) < 10) {
        $parts[] = 'q.id = ?';
        $params[] = (int) $idSearch;
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}
foreach (['statuses' => 'q.status_id', 'priorities' => 'q.priority_id'] as $key => $column) {
    if ($c[$key]) {
        $where[] = "$column IN (" . implode(',', array_fill(0, count($c[$key]), '?')) . ')';
        $params = array_merge($params, $c[$key]);
    }
}
$equalsFilters = [
    'category' => 'q.category_id',
    'requester' => 'q.requester_id',
    'organization' => 'q.requester_organization_id',
    'sla' => 'q.sla_state',
];
foreach ($equalsFilters as $key => $column) {
    if ($c[$key]) {
        $where[] = "$column = ?";
        $params[] = $c[$key];
    }
}
if ($c['assignee'] === 'me') {
    $where[] = 'q.assigned_to = ?';
    $params[] = $me;
} elseif ($c['assignee'] === 'none') {
    $where[] = 'q.assigned_to IS NULL';
} elseif ($c['assignee']) {
    $where[] = 'q.assigned_to = ?';
    $params[] = $c['assignee'];
}
if ($c['waiting']) {
    $where[] = 'q.status_order < ? AND q.waiting = 1';
    $params[] = $resolvedOrder;
}
if ($c['has_due']) {
    $where[] = 'q.due_date IS NOT NULL';
}
foreach ($dateFields as $key => [$column]) {
    [$from, $to] = $dates[$key];
    if ($from) {
        $where[] = "$column >= ?";
        $params[] = $from->format('Y-m-d');
    }
    if ($to) {
        $where[] = "$column < ?";
        $params[] = $to->modify('+1 day')->format('Y-m-d');
    }
}
$whereSql = implode(' AND ', $where);
$list = ticket_query_sql();
[$sort, $dir] = ticket_sort_from_query($_GET, $staff, 'updated');
$orderBy = ticket_order_sql($sort, $dir, $resolvedOrder);

// The query string without sort/page, rebuilt from validated values only.
$query = array_filter([
    'q' => $c['q'] ?: null,
    'in_notes' => $c['in_notes'] ? 1 : null,
    'status' => $c['statuses'] ?: null,
    'priority' => $c['priorities'] ?: null,
    'category' => $c['category'],
    'requester' => $c['requester'],
    'assignee' => $c['assignee'],
    'organization' => $c['organization'],
    'sla' => $c['sla'],
    'waiting' => $c['waiting'] ? 1 : null,
    'has_due' => $c['has_due'] ? 1 : null,
], fn ($v) => $v !== null && $v !== '');
foreach ($dates as $key => [$from, $to]) {
    if ($from) {
        $query[$key . '_from'] = $from->format('Y-m-d');
    }
    if ($to) {
        $query[$key . '_to'] = $to->format('Y-m-d');
    }
}
$searched = $query !== [];

if (($_GET['format'] ?? '') === 'stamp') {
    send_stamp(ticket_stamp($pdo, $whereSql, $params));
}
if (($_GET['export'] ?? '') === 'csv') {
    $all = $pdo->prepare("SELECT * FROM ($list) q WHERE $whereSql ORDER BY $orderBy");
    $all->execute($params);
    $rows = array_map('ticket_csv_row', $all->fetchAll());
    send_csv('resolveit-search-' . date('Y-m-d') . '.csv', ticket_csv_columns($staff), $rows);
}

$count = $pdo->prepare("SELECT COUNT(*) FROM ($list) q WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM ($list) q WHERE $whereSql
    ORDER BY $orderBy OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY");
$stmt->execute($params);
$tickets = $stmt->fetchAll();
$activeQuery = $query + ['sort' => $sort, 'dir' => $dir];
$resultText = $searched ? 'match this search' : 'in total. Add criteria above to narrow them down';

$pageTitle = 'Advanced Search';
require __DIR__ . '/partials/header.php';
$checked = fn (array $list, int $id) => in_array($id, $list, true) ? ' checked' : '';
?>
<div class="page-header">
    <div>
        <p class="breadcrumb">
            <a href="tickets.php"><?= $staff ? 'Tickets' : 'My Tickets' ?></a> / Advanced search
        </p>
        <h1>Advanced search</h1>
        <p class="subtitle">Combine any fields. Bookmark the page to save a search.</p>
    </div>
</div>
<form class="card search-form" method="get" action="search.php">
    <div class="search-grid">
        <div class="span-2">
            <label for="s-q">Keywords</label>
            <input id="s-q" type="search" name="q" value="<?= e($c['q']) ?>"
                placeholder="Words in the title, description, or resolution, or a ticket #">
            <label class="checkbox-label">
                <input type="checkbox" name="in_notes" value="1"<?= $c['in_notes'] ? ' checked' : '' ?>>
                Also search replies and notes
            </label>
        </div>
        <div>
            <label for="s-category">Category</label>
            <select id="s-category" name="category">
                <option value="">Any</option>
                <?= select_options(array_column($categories, 'name', 'id'), $c['category']) ?>
            </select>
        </div>
        <?php if ($staff) : ?>
        <div>
            <label for="s-requester">Requester</label>
            <select id="s-requester" name="requester">
                <option value="">Anyone</option>
                <?= select_options(array_column($people, 'name', 'id'), $c['requester']) ?>
            </select>
        </div>
        <div>
            <label for="s-organization">Organization</label>
            <select id="s-organization" name="organization">
                <option value="">Any</option>
                <?= select_options(array_column($organizations, 'name', 'id'), $c['organization']) ?>
            </select>
        </div>
        <div>
            <label for="s-assignee">Assigned to</label>
            <select id="s-assignee" name="assignee">
                <option value="">Anyone</option>
                <?= select_options(
                    ['me' => 'Me', 'none' => 'Unassigned'] + array_column($technicians, 'name', 'id'),
                    $c['assignee']
                ) ?>
            </select>
        </div>
        <div>
            <label for="s-sla">SLA</label>
            <select id="s-sla" name="sla">
                <option value="">Any</option>
                <?= select_options($slaOptions, $c['sla']) ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <fieldset class="check-group"><legend>Status</legend>
        <?php foreach ($statuses as $s) : ?>
            <label class="checkbox-label">
                <input type="checkbox" name="status[]" value="<?= (int) $s['id'] ?>"
                    <?= $checked($c['statuses'], (int) $s['id']) ?>> <?= e($s['name']) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <fieldset class="check-group"><legend>Priority</legend>
        <?php foreach ($priorities as $p) : ?>
            <label class="checkbox-label">
                <input type="checkbox" name="priority[]" value="<?= (int) $p['id'] ?>"
                    <?= $checked($c['priorities'], (int) $p['id']) ?>> <?= e($p['name']) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <div class="search-grid dates">
        <?php foreach ($dateFields as $key => [, $label]) : ?>
            <?php [$from, $to] = $dates[$key]; ?>
        <fieldset class="date-range"><legend><?= e($label) ?></legend>
            <input type="date" name="<?= $key ?>_from" value="<?= e($from?->format('Y-m-d') ?? '') ?>"
                aria-label="<?= e($label) ?> from">
            <span class="meta">to</span>
            <input type="date" name="<?= $key ?>_to" value="<?= e($to?->format('Y-m-d') ?? '') ?>"
                aria-label="<?= e($label) ?> to">
        </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <label class="checkbox-label">
            <input type="checkbox" name="waiting" value="1"<?= $c['waiting'] ? ' checked' : '' ?>>
            <?= $staff ? 'Waiting on requester only' : 'Awaiting my reply only' ?>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="has_due" value="1"<?= $c['has_due'] ? ' checked' : '' ?>> Has a due date
        </label>
    </div>
    <div class="form-actions">
        <button type="submit"><?= icon('search') ?>Search</button>
        <a class="button secondary" href="search.php" style="margin-top:1rem">Reset</a>
    </div>
</form>

<div id="live-results" data-live-region data-stamp="<?= e(ticket_stamp($pdo, $whereSql, $params)) ?>">
    <div class="list-toolbar">
        <p class="result-count"><?= $total ?> ticket<?= $total === 1 ? '' : 's' ?> <?= $resultText ?></p>
        <div class="toolbar-actions">
            <span class="meta live-status" data-live-status>Updated <?= e(date('g:i A')) ?></span>
            <label class="checkbox-label auto-refresh">
                <input type="checkbox" data-auto-refresh checked> Auto-refresh
            </label>
            <a class="button secondary small"
                href="search.php?<?= e(http_build_query($activeQuery + ['export' => 'csv'])) ?>">
                <?= icon('download') ?>Export CSV
            </a>
        </div>
    </div>
    <?= ticket_table(
        $tickets,
        $staff,
        'No tickets match this search.',
        false,
        ['key' => $sort, 'dir' => $dir, 'query' => $query]
    ) ?>
    <?= pagination($page, $pages, $activeQuery) ?>
</div>
<div class="update-banner" data-update-banner hidden>
    <span>Results have changed.</span> <button type="button" class="small" data-refresh-now>Refresh now</button>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
