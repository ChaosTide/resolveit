<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('administrator');
$tabs = [
    'categories' => 'Categories',
    'organizations' => 'Organizations',
    'priorities' => 'Priorities',
    'statuses' => 'Statuses',
    'audit' => 'Audit Log',
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'categories';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = clean_text($_POST['action'] ?? '');
    $name = clean_text($_POST['name'] ?? '');
    $id = valid_id($_POST['id'] ?? null);
    $toggleSql = 'SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?';
    try {
        if ($action === 'org_add') {
            $tab = 'organizations';
            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Organization name is required and must be at most 100 characters.';
            }
            if (!$errors) {
                $pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute([$name]);
                audit_log($pdo, 'organization added', $name);
                flash('success', 'Organization added.');
                redirect('settings.php?tab=organizations');
            }
        } elseif ($action === 'org_rename') {
            $tab = 'organizations';
            if (!$id) {
                $errors[] = 'Invalid organization.';
            }
            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Organization name is required and must be at most 100 characters.';
            }
            if (!$errors) {
                $old = $pdo->prepare('SELECT name FROM organizations WHERE id = ?');
                $old->execute([$id]);
                $oldName = $old->fetchColumn();
                if ($oldName === false) {
                    $errors[] = 'That organization no longer exists.';
                } else {
                    if ($oldName !== $name) {
                        $pdo->prepare('UPDATE organizations SET name = ? WHERE id = ?')->execute([$name, $id]);
                        audit_log($pdo, 'organization renamed', $name, "Was: $oldName");
                    }
                    flash('success', 'Organization saved.');
                    redirect('settings.php?tab=organizations');
                }
            }
        } elseif ($action === 'org_toggle') {
            $tab = 'organizations';
            $stmt = $pdo->prepare('SELECT name, active FROM organizations WHERE id = ?');
            $stmt->execute([$id]);
            $org = $stmt->fetch();
            if (!$org) {
                $errors[] = 'That organization no longer exists.';
            } else {
                // Deactivating hides it from new-user forms; existing users keep it.
                $pdo->prepare("UPDATE organizations $toggleSql")->execute([$id]);
                audit_log($pdo, $org['active'] ? 'organization deactivated' : 'organization activated', $org['name']);
                flash('success', 'Organization updated.');
                redirect('settings.php?tab=organizations');
            }
        } elseif ($action === 'category_add') {
            $tab = 'categories';
            if ($name === '' || mb_strlen($name) > 80) {
                $errors[] = 'Category name is required and must be at most 80 characters.';
            }
            if (!$errors) {
                $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
                audit_log($pdo, 'category added', $name);
                flash('success', 'Category added.');
                redirect('settings.php?tab=categories');
            }
        } elseif ($action === 'category_rename') {
            $tab = 'categories';
            if (!$id) {
                $errors[] = 'Invalid category.';
            }
            if ($name === '' || mb_strlen($name) > 80) {
                $errors[] = 'Category name is required and must be at most 80 characters.';
            }
            if (!$errors) {
                $old = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
                $old->execute([$id]);
                $oldName = $old->fetchColumn();
                if ($oldName === false) {
                    $errors[] = 'That category no longer exists.';
                } else {
                    if ($oldName !== $name) {
                        $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
                        audit_log($pdo, 'category renamed', $name, "Was: $oldName");
                    }
                    flash('success', 'Category saved.');
                    redirect('settings.php?tab=categories');
                }
            }
        } elseif ($action === 'category_toggle') {
            $tab = 'categories';
            if (!$id) {
                $errors[] = 'Invalid category.';
            }
            if (!$errors) {
                $stmt = $pdo->prepare('SELECT name, active FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                $category = $stmt->fetch();
                $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE active = 1')->fetchColumn();
                if (!$category) {
                    $errors[] = 'That category no longer exists.';
                } elseif ($category['active'] && $activeCount <= 1) {
                    $errors[] = 'At least one category must stay active so employees can submit tickets.';
                } else {
                    $pdo->prepare("UPDATE categories $toggleSql")->execute([$id]);
                    $event = $category['active'] ? 'category deactivated' : 'category activated';
                    audit_log($pdo, $event, $category['name']);
                    flash('success', 'Category updated.');
                    redirect('settings.php?tab=categories');
                }
            }
        } elseif ($action === 'priority_update') {
            $tab = 'priorities';
            $slaHours = filter_var($_POST['sla_hours'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 720],
            ]);
            if (!$id) {
                $errors[] = 'Invalid priority.';
            }
            if ($name === '' || mb_strlen($name) > 20) {
                $errors[] = 'Priority name is required and must be at most 20 characters.';
            }
            if ($slaHours === false) {
                $errors[] = 'SLA target must be a whole number of hours from 1 to 720.';
            }
            if (!$errors) {
                $old = $pdo->prepare('SELECT name, sla_hours FROM priorities WHERE id = ?');
                $old->execute([$id]);
                $oldRow = $old->fetch();
                if (!$oldRow) {
                    $errors[] = 'That priority no longer exists.';
                } else {
                    $pdo->prepare('UPDATE priorities SET name = ?, sla_hours = ? WHERE id = ?')
                        ->execute([$name, $slaHours, $id]);
                    if ($oldRow['name'] !== $name) {
                        audit_log($pdo, 'priority renamed', $name, "Was: {$oldRow['name']}");
                    }
                    if ((int) $oldRow['sla_hours'] !== $slaHours) {
                        audit_log($pdo, 'priority SLA changed', $name, "{$oldRow['sla_hours']}h -> {$slaHours}h");
                    }
                    flash('success', 'Priority saved.');
                    redirect('settings.php?tab=priorities');
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    } catch (PDOException $ex) {
        if (str_starts_with($action, 'priority')) {
            $thing = 'priority';
        } elseif (str_starts_with($action, 'org_')) {
            $thing = 'organization';
        } else {
            $thing = 'category';
        }
        $errors[] = unique_error($ex, $thing);
    }
}

$pageTitle = 'Settings';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1>System settings</h1>
        <p class="subtitle">Categories, priorities and SLA targets, the ticket workflow, and the audit log.</p>
    </div>
</div>
<nav class="tabs" aria-label="Settings sections">
<?php foreach ($tabs as $key => $label) : ?>
    <a href="settings.php?tab=<?= $key ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
</nav>
<?php foreach ($errors as $error) : ?>
    <p class="errors"><?= e($error) ?></p>
<?php endforeach; ?>

<?php if ($tab === 'categories') : ?>
    <?php
    $categories = $pdo->query('SELECT c.id, c.name, c.active, COUNT(t.id) AS tickets
        FROM categories c LEFT JOIN tickets t ON t.category_id = c.id
        GROUP BY c.id, c.name, c.active ORDER BY c.name')->fetchAll();
    ?>
<section class="card"><h2>Add category</h2>
    <form method="post" class="inline-form">
        <?= action_fields('category_add') ?>
        <input name="name" maxlength="80" placeholder="New category name" aria-label="New category name" required>
        <button>Add Category</button>
    </form>
</section>
<p class="meta">
    Categories are never deleted because existing tickets use them.
    Deactivate a category to hide it from the New Ticket form.
</p>
<table>
    <thead><tr><th>Name</th><th class="num">Tickets</th><th>Active</th><th>Deactivate / activate</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $category) : ?>
        <tr>
            <td>
                <form method="post" class="inline-form">
                    <?= action_fields('category_rename', (int) $category['id']) ?>
                    <input name="name" maxlength="80" value="<?= e($category['name']) ?>"
                        aria-label="Category name" required>
                    <button class="secondary small">Rename</button>
                </form>
            </td>
            <td class="num"><?= (int) $category['tickets'] ?></td>
            <td><?= $category['active'] ? 'Yes' : 'No' ?></td>
            <td>
                <form method="post">
                    <?= action_fields('category_toggle', (int) $category['id']) ?>
                    <button class="<?= $category['active'] ? 'secondary' : '' ?>">
                        <?= $category['active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php elseif ($tab === 'organizations') : ?>
    <?php
    $orgs = $pdo->query('SELECT o.id, o.name, o.active,
        (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id) AS members,
        (SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.requester_id WHERE u.organization_id = o.id) AS tickets
        FROM organizations o ORDER BY o.name')->fetchAll();
    ?>
<section class="card"><h2>Add organization</h2>
    <form method="post" class="inline-form">
        <?= action_fields('org_add') ?>
        <input name="name" maxlength="100" placeholder="e.g. Finance Office, Library, Housing"
            aria-label="New organization name" required>
        <button>Add Organization</button>
    </form>
</section>
<p class="meta">
    Organizations group requesters (for example departments or offices). Assign people to one in Manage Users;
    tickets can be filtered and searched by organization.
    Deactivating an organization hides it from the user forms but keeps existing members.
</p>
<table>
    <thead>
        <tr>
            <th>Name</th><th class="num">Members</th><th class="num">Tickets</th>
            <th>Active</th><th>Deactivate / activate</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($orgs as $org) : ?>
        <tr>
            <td>
                <form method="post" class="inline-form">
                    <?= action_fields('org_rename', (int) $org['id']) ?>
                    <input name="name" maxlength="100" value="<?= e($org['name']) ?>"
                        aria-label="Organization name" required>
                    <button class="secondary small">Rename</button>
                </form>
            </td>
            <td class="num"><?= (int) $org['members'] ?></td>
            <td class="num">
                <a href="search.php?organization=<?= (int) $org['id'] ?>"><?= (int) $org['tickets'] ?></a>
            </td>
            <td><?= $org['active'] ? 'Yes' : 'No' ?></td>
            <td>
                <form method="post">
                    <?= action_fields('org_toggle', (int) $org['id']) ?>
                    <button class="<?= $org['active'] ? 'secondary' : '' ?>">
                        <?= $org['active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$orgs) : ?>
        <tr><td colspan="5"><div class="empty-state"><?= icon('users') ?><p>No organizations yet.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php elseif ($tab === 'priorities') : ?>
    <?php
    $priorities = $pdo->query('SELECT p.id, p.name, p.sort_order, p.sla_hours, COUNT(t.id) AS tickets
        FROM priorities p LEFT JOIN tickets t ON t.priority_id = p.id
        GROUP BY p.id, p.name, p.sort_order, p.sla_hours ORDER BY p.sort_order')->fetchAll();
    ?>
<p class="meta">
    Priorities can be renamed and given an SLA target: the number of hours a ticket at that priority has to be
    resolved after it is created. The four levels and their order are fixed; new tickets start at level 2.
    SLA results appear on the <a href="reports.php">Reports</a> page.
</p>
<table>
    <thead><tr><th class="num">Level</th><th>Name and SLA target (hours)</th><th class="num">Tickets</th></tr></thead>
    <tbody>
    <?php foreach ($priorities as $priority) : ?>
        <tr>
            <td class="num"><?= (int) $priority['sort_order'] ?></td>
            <td>
                <form method="post" class="inline-form">
                    <?= action_fields('priority_update', (int) $priority['id']) ?>
                    <input name="name" maxlength="20" value="<?= e($priority['name']) ?>"
                        aria-label="Priority name" required>
                    <input name="sla_hours" type="number" min="1" max="720" step="1"
                        value="<?= (int) $priority['sla_hours'] ?>"
                        aria-label="SLA target in hours for <?= e($priority['name']) ?>" class="hours-input" required>
                    <span class="meta">h</span>
                    <button class="secondary small">Save</button>
                </form>
            </td>
            <td class="num"><?= (int) $priority['tickets'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php elseif ($tab === 'statuses') : ?>
    <?php
    $statuses = $pdo->query('SELECT s.id, s.name, s.sort_order, COUNT(t.id) AS tickets
        FROM statuses s LEFT JOIN tickets t ON t.status_id = s.id
        GROUP BY s.id, s.name, s.sort_order ORDER BY s.sort_order')->fetchAll();
    $names = array_column($statuses, 'name', 'id');
    ?>
<p class="meta">
    Statuses are locked because the ticket workflow is built on them. Tickets move one step at a time;
    a Resolved ticket can be reopened to In Progress; Closed is final.
</p>
<table>
    <thead><tr><th class="num">Step</th><th>Status</th><th>Can move to</th><th class="num">Tickets</th></tr></thead>
    <tbody>
    <?php foreach ($statuses as $status) : ?>
        <?php
        $statusId = (int) $status['id'];
        $next = array_map(fn ($id) => $names[$id], array_diff(allowed_status_ids($statuses, $statusId), [$statusId]));
        ?>
        <tr>
            <td class="num"><?= (int) $status['sort_order'] ?></td>
            <td><?= e($status['name']) ?></td>
            <td><?= $next ? e(implode(', ', $next)) : '<span class="meta">Final</span>' ?></td>
            <td class="num"><?= (int) $status['tickets'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php else : ?>
    <?php
    $audit = $pdo->query('SELECT TOP 100 a.created_at, u.name AS admin, a.action, a.target, a.details
        FROM admin_audit_log a JOIN users u ON u.id = a.admin_id ORDER BY a.id DESC')->fetchAll();
    $history = $pdo->query('SELECT TOP 50 h.created_at, u.name AS changed_by, h.ticket_id, h.action,
            h.old_value, h.new_value
        FROM ticket_history h JOIN users u ON u.id = h.changed_by ORDER BY h.id DESC')->fetchAll();
    ?>
<section class="card"><h2>Administrator actions</h2>
    <p class="meta">Latest 100 user and settings changes. Passwords are never recorded.</p>
    <table>
        <thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Target</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $row) : ?>
            <tr>
                <td><?= e($row['created_at']) ?></td>
                <td><?= e($row['admin']) ?></td>
                <td><?= e($row['action']) ?></td>
                <td><?= e($row['target']) ?></td>
                <td><?= e($row['details']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$audit) : ?><tr><td colspan="5">No administrator actions recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<section class="card"><h2>Recent ticket changes</h2>
    <table>
        <thead><tr><th>When</th><th>By</th><th>Ticket</th><th>Change</th></tr></thead>
        <tbody>
        <?php foreach ($history as $row) : ?>
            <?php
            // "status changed: Open → Assigned", or "created: Open" when there is no old value.
            if ($row['old_value'] !== null && $row['old_value'] !== '') {
                $change = ': ' . e($row['old_value']) . ' &rarr; ';
            } else {
                $change = $row['new_value'] ? ': ' : '';
            }
            ?>
            <tr>
                <td><?= e($row['created_at']) ?></td>
                <td><?= e($row['changed_by']) ?></td>
                <td><a href="ticket.php?id=<?= (int) $row['ticket_id'] ?>">#<?= (int) $row['ticket_id'] ?></a></td>
                <td><?= e($row['action']) ?><?= $change ?><?= e($row['new_value']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
