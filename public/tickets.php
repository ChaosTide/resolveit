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
$technicians = [];
$organizations = [];
if ($staff) {
    $technicians = $pdo->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id
        WHERE r.name IN ('technician', 'administrator') ORDER BY u.name")->fetchAll();
    $organizations = $pdo->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll();
}

// Quick views (chips). Each maps to a WHERE fragment on the ticket row alias "q".
$views = ['all' => 'All', 'open' => 'Open', 'waiting' => $staff ? 'Waiting on requester' : 'Awaiting my reply'];
if ($staff) {
    $views += ['mine' => 'My queue', 'unassigned' => 'Unassigned', 'overdue' => 'SLA overdue', 'pastdue' => 'Past due'];
}
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
    'assignee' => assignee_filter($_GET, $staff),
    'organization' => $staff ? valid_id($_GET['organization'] ?? null) : null,
];
[$sort, $dir] = ticket_sort_from_query($_GET, $staff, $staff ? 'due' : 'updated');
$orderBy = ticket_order_sql($sort, $dir, $resolvedOrder);
$split = ($_GET['layout'] ?? '') === 'split';

// Base scope: employees only ever see their own tickets.
$scopeWhere = $staff ? ['1 = 1'] : ['q.requester_id = ?'];
$scopeParams = $staff ? [] : [$me];

$where = $scopeWhere;
$params = $scopeParams;
[$viewWhere, $viewParams] = $viewSql[$filters['view']];
$where[] = $viewWhere;
$params = array_merge($params, $viewParams);
if ($filters['q'] !== '') {
    $like = like_pattern($filters['q']);
    $idSearch = ltrim($filters['q'], '#');
    if (ctype_digit($idSearch) && strlen($idSearch) < 10) {
        $where[] = "(q.id = ? OR q.title LIKE ? ESCAPE '\\' OR q.description LIKE ? ESCAPE '\\')";
        array_push($params, (int) $idSearch, $like, $like);
    } else {
        $where[] = "(q.title LIKE ? ESCAPE '\\' OR q.description LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like);
    }
}
$equalsFilters = [
    'status' => 'q.status_id',
    'priority' => 'q.priority_id',
    'category' => 'q.category_id',
    'organization' => 'q.requester_organization_id',
];
foreach ($equalsFilters as $key => $column) {
    if ($filters[$key]) {
        $where[] = "$column = ?";
        $params[] = $filters[$key];
    }
}
if ($filters['assignee'] === 'me') {
    $where[] = 'q.assigned_to = ?';
    $params[] = $me;
} elseif ($filters['assignee'] === 'none') {
    $where[] = 'q.assigned_to IS NULL';
} elseif ($filters['assignee']) {
    $where[] = 'q.assigned_to = ?';
    $params[] = $filters['assignee'];
}

$list = ticket_query_sql();
$whereSql = implode(' AND ', $where);

// Automatic refresh: the page polls this and reloads the list only when something changed.
if (($_GET['format'] ?? '') === 'stamp') {
    send_stamp(ticket_stamp($pdo, $whereSql, $params));
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

// CSV export of everything matching the current filters (not just this page).
if (($_GET['export'] ?? '') === 'csv') {
    $all = $pdo->prepare("SELECT * FROM ($list) q WHERE $whereSql ORDER BY $orderBy");
    $all->execute($params);
    $rows = array_map('ticket_csv_row', $all->fetchAll());
    send_csv('resolveit-tickets-' . date('Y-m-d') . '.csv', ticket_csv_columns($staff), $rows);
}

// Counts for the quick-view chips (within the user's scope, ignoring other filters).
$countSql = [];
$countParams = [];
foreach ($viewSql as $key => [$sql, $p]) {
    if (!isset($views[$key])) {
        continue;
    }
    $countSql[] = "SUM(CASE WHEN $sql THEN 1 ELSE 0 END) AS [$key]";
    $countParams = array_merge($countParams, $p);
}
$chips = $pdo->prepare('SELECT ' . implode(', ', $countSql) . " FROM ($list) q WHERE " . implode(' AND ', $scopeWhere));
$chips->execute(array_merge($countParams, $scopeParams));
$chipCounts = array_map('intval', $chips->fetch() ?: []);

// Filters that links (sorting, paging, export, layout toggle) should keep.
$viewParam = $filters['view'] === 'all' ? null : $filters['view'];
$layoutParam = $split ? 'split' : null;
$filterQuery = array_filter([
    'view' => $viewParam,
    'q' => $filters['q'] ?: null,
    'status' => $filters['status'],
    'priority' => $filters['priority'],
    'category' => $filters['category'],
    'assignee' => $filters['assignee'],
    'organization' => $filters['organization'],
    'layout' => $layoutParam,
], fn ($v) => $v !== null && $v !== '');
$activeQuery = $filterQuery + ['sort' => $sort, 'dir' => $dir];
$dropdownFilterCount = count(array_filter([
    $filters['status'], $filters['priority'], $filters['category'], $filters['assignee'], $filters['organization'],
]));
$hasFilters = $filters['q'] !== '' || $dropdownFilterCount > 0;
$layoutToggle = array_filter(['layout' => $split ? null : 'split'] + $activeQuery, fn ($v) => $v !== null);
$clearQuery = array_filter(['view' => $viewParam, 'layout' => $layoutParam]);
$sortOptions = [];
foreach (ticket_sort_options($staff) as $key => [$label]) {
    // Columns without a label are only sortable from their header, but stay listed while active.
    if ($label !== null || $sort === $key) {
        $sortOptions[$key] = $label ?? ucfirst($key);
    }
}
$emptyText = $hasFilters || $filters['view'] !== 'all' ? 'No tickets match these filters.' : 'No tickets found.';

$pageTitle = $staff ? 'Tickets' : 'My Tickets';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $staff ? 'All Tickets' : 'My Tickets' ?></h1>
        <p class="subtitle">
            <?= $staff
                ? 'Every request in the service desk, with live SLA status.'
                : 'Requests you have submitted to the IT help desk.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="button secondary" href="search.php"><?= icon('search') ?>Advanced search</a>
        <a class="button secondary hide-sm" href="tickets.php?<?= e(http_build_query($layoutToggle)) ?>"
            data-layout-toggle>
            <?= icon('dashboard') ?><?= $split ? 'List view' : 'Split view' ?>
        </a>
        <a class="button" href="ticket-create.php"><?= icon('plus') ?>Create Ticket</a>
    </div>
</div>

<?php
// Quick views as a menu on the left (same look as the dashboard menu), everything else on the
// right. [icon, tone] per view; the tone colors the count when it is above zero.
$viewStyle = [
    'all' => ['tickets', 'primary'], 'open' => ['inbox', 'primary'], 'waiting' => ['mail', 'violet'],
    'mine' => ['user', 'primary'], 'unassigned' => ['users', 'violet'], 'overdue' => ['alert', 'danger'],
    'pastdue' => ['clock', 'danger'], 'resolved' => ['check', 'success'], 'closed' => ['lock', 'primary'],
];
?>
<div class="side-menu-layout">
<nav class="dash-menu view-menu" aria-label="Quick views" id="live-chips" data-live-region>
<?php foreach ($views as $key => $label) : ?>
    <?php
    $chipQuery = array_filter([
        'view' => $key === 'all' ? null : $key,
        'sort' => $sort,
        'dir' => $dir,
        'layout' => $layoutParam,
    ]);
    $chipCount = $chipCounts[$key] ?? 0;
    $chipActive = $filters['view'] === $key;
    [$viewIcon, $viewTone] = $viewStyle[$key];
    ?>
    <a class="dash-menu-item tone-<?= $viewTone ?><?= $chipCount === 0 && !$chipActive ? ' is-empty' : '' ?>"
        href="tickets.php?<?= e(http_build_query($chipQuery)) ?>" <?= $chipActive ? 'aria-current="true"' : '' ?>>
        <?= icon($viewIcon) ?><span class="dash-menu-label"><?= e($label) ?></span>
        <span class="dash-menu-count<?= $chipCount > 0 ? ' has-value' : '' ?>"><?= $chipCount ?></span>
        <span class="dash-menu-chevron"><?= icon('arrow-right') ?></span>
    </a>
<?php endforeach; ?>
</nav>
<div class="side-menu-main">

<form class="card filters" method="get" action="tickets.php">
    <?php if ($viewParam !== null) : ?><input type="hidden" name="view" value="<?= e($viewParam) ?>"><?php endif; ?>
    <?php if ($split) : ?><input type="hidden" name="layout" value="split"><?php endif; ?>
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <div class="filter-row">
        <div class="filter wide">
            <label for="f-q">Search</label>
            <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                placeholder="Ticket #, title, or description">
        </div>
        <button type="submit"><?= icon('search') ?>Apply</button>
        <?php if ($hasFilters) : ?>
            <a class="button secondary" href="tickets.php?<?= e(http_build_query($clearQuery)) ?>">Clear</a>
        <?php endif; ?>
    </div>
    <details class="filter-more"<?= $dropdownFilterCount > 0 ? ' open' : '' ?>>
        <summary>
            More filters
            <?php if ($dropdownFilterCount > 0) : ?>
                <span class="count"><?= $dropdownFilterCount ?></span>
            <?php endif; ?>
        </summary>
        <div class="filter-row">
            <div class="filter">
                <label for="f-status">Status</label>
                <select id="f-status" name="status" data-autosubmit>
                    <option value="">Any status</option>
                    <?= select_options(array_column($statuses, 'name', 'id'), $filters['status']) ?>
                </select>
            </div>
            <div class="filter">
                <label for="f-priority">Priority</label>
                <select id="f-priority" name="priority" data-autosubmit>
                    <option value="">Any priority</option>
                    <?= select_options(array_column($priorities, 'name', 'id'), $filters['priority']) ?>
                </select>
            </div>
            <div class="filter">
                <label for="f-category">Category</label>
                <select id="f-category" name="category" data-autosubmit>
                    <option value="">Any category</option>
                    <?= select_options(array_column($categories, 'name', 'id'), $filters['category']) ?>
                </select>
            </div>
            <?php if ($staff) : ?>
            <div class="filter">
                <label for="f-assignee">Assigned to</label>
                <select id="f-assignee" name="assignee" data-autosubmit>
                    <option value="">Anyone</option>
                    <?= select_options(
                        ['me' => 'Me', 'none' => 'Unassigned'] + array_column($technicians, 'name', 'id'),
                        $filters['assignee']
                    ) ?>
                </select>
            </div>
            <div class="filter">
                <label for="f-organization">Organization</label>
                <select id="f-organization" name="organization" data-autosubmit>
                    <option value="">Any organization</option>
                    <?= select_options(array_column($organizations, 'name', 'id'), $filters['organization']) ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter">
                <label for="f-sort">Sort</label>
                <select id="f-sort" name="sort" data-autosubmit data-reset-dir>
                    <?= select_options($sortOptions, $sort) ?>
                </select>
            </div>
        </div>
    </details>
</form>

<?php if ($staff) : ?>
<form id="bulk-form" class="card bulk-bar" method="post" action="tickets-bulk.php" data-bulk-form>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="return" value="<?= e(http_build_query($activeQuery)) ?>">
    <strong class="bulk-count" data-bulk-count>Bulk update</strong>
    <select name="assigned_to" aria-label="Assign selected tickets to">
        <option value="">Assign to… (no change)</option>
        <option value="none">Unassigned</option>
        <?= select_options(array_column($technicians, 'name', 'id'), null) ?>
    </select>
    <select name="priority_id" aria-label="Set priority of selected tickets">
        <option value="">Priority… (no change)</option>
        <?= select_options(array_column($priorities, 'name', 'id'), null) ?>
    </select>
    <button type="submit" class="small">Apply to selected</button>
    <span class="meta">Status changes stay one ticket at a time so the workflow is enforced.</span>
</form>
<?php endif; ?>

<div class="<?= $split ? 'split-layout' : '' ?>">
    <div class="<?= $split ? 'split-list' : '' ?>">
        <div id="live-results" data-live-region data-stamp="<?= e(ticket_stamp($pdo, $whereSql, $params)) ?>">
            <div class="list-toolbar">
                <p class="result-count">
                    <?= $total ?> ticket<?= $total === 1 ? '' : 's' ?><?= $hasFilters ? ' match your filters' : '' ?>
                </p>
                <div class="toolbar-actions">
                    <span class="meta live-status" data-live-status>Updated <?= e(date('g:i A')) ?></span>
                    <label class="checkbox-label auto-refresh">
                        <input type="checkbox" data-auto-refresh checked> Auto-refresh
                    </label>
                    <a class="button secondary small" href="tickets.php?<?= e(http_build_query($activeQuery)) ?>"
                        data-refresh title="Refresh the list">&#x21bb; Refresh</a>
                    <a class="button secondary small"
                        href="tickets.php?<?= e(http_build_query($activeQuery + ['export' => 'csv'])) ?>">
                        <?= icon('download') ?>Export CSV
                    </a>
                </div>
            </div>
            <?= ticket_table(
                $tickets,
                $staff,
                $emptyText,
                $staff,
                ['key' => $sort, 'dir' => $dir, 'query' => $filterQuery]
            ) ?>
            <?= pagination($page, $pages, $activeQuery) ?>
        </div>
    </div>
    <?php if ($split) : ?>
    <aside class="split-pane card" id="split-pane" aria-live="polite" data-split-pane>
        <div class="empty-state">
            <span class="empty-icon"><?= icon('tickets') ?></span><p>Select a ticket to see it here.</p>
        </div>
    </aside>
    <?php endif; ?>
</div>
</div><!-- .side-menu-main -->
</div><!-- .side-menu-layout -->
<div class="update-banner" data-update-banner hidden>
    <span>Tickets have changed.</span> <button type="button" class="small" data-refresh-now>Refresh now</button>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
