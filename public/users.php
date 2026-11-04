<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('administrator');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = clean_text($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = clean_text($_POST['name'] ?? '');
        $organizationId = valid_id($_POST['organization_id'] ?? null);
        $email = mb_strtolower(clean_text($_POST['email'] ?? ''));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $role = clean_text($_POST['role'] ?? '');
        $activeOrganizationIds = array_map('intval', array_column(organization_options($pdo), 'id'));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name is required and must be at most 100 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Enter a valid email.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters.';
        }
        if (!in_array($role, ['employee', 'technician', 'administrator'], true)) {
            $errors[] = 'Choose a valid role.';
        }
        if ($organizationId && !in_array($organizationId, $activeOrganizationIds, true)) {
            $errors[] = 'Choose an active organization.';
        }
        if (!$errors) {
            try {
                $stmt = $pdo->prepare('INSERT INTO users (role_id, name, email, password_hash, organization_id)
                    SELECT id, ?, ?, ?, ? FROM roles WHERE name = ?');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $organizationId, $role]);
                audit_log($pdo, 'user created', $email, "Role: $role");
                flash('success', 'User created.');
                redirect('users.php');
            } catch (PDOException $ex) {
                $errors[] = $ex->getCode() === '23000'
                    ? 'That email already exists.'
                    : 'The user could not be created.';
            }
        }
    } elseif ($action === 'toggle') {
        $userId = valid_id($_POST['user_id'] ?? null);
        if (!$userId || $userId === (int) current_user()['id']) {
            $errors[] = 'You cannot change this account.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END
                OUTPUT INSERTED.email, INSERTED.active WHERE id = ?');
            $stmt->execute([$userId]);
            $changed = $stmt->fetch();
            if ($changed) {
                audit_log($pdo, $changed['active'] ? 'user activated' : 'user deactivated', $changed['email']);
                flash('success', 'Account status changed.');
                redirect('users.php');
            }
            $errors[] = 'That user no longer exists.';
        }
    }
}
$organizations = organization_options($pdo);
// Unlike the create-user dropdown above (active organizations only, so nobody gets newly
// assigned to a retired one), the filter includes inactive organizations too -- someone
// already in one should still be findable. Same approach tickets.php takes for its filter.
$allOrganizations = $pdo->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll();
$roleNames = ['employee' => 'Employee', 'technician' => 'Technician', 'administrator' => 'Administrator'];
$sortOptions = [
    'name' => 'Name (A-Z)', 'role' => 'Role', 'organization' => 'Organization', 'created' => 'Newest first',
];
$filters = [
    'role' => array_key_exists($_GET['role'] ?? '', $roleNames) ? $_GET['role'] : null,
    'organization' => ($_GET['organization'] ?? '') === 'none' ? 'none' : valid_id($_GET['organization'] ?? null),
    'sort' => array_key_exists($_GET['sort'] ?? '', $sortOptions) ? $_GET['sort'] : 'name',
];
// Sort keys are whitelisted above, never interpolated from raw input.
$orderBy = [
    'name' => 'u.name',
    'role' => "CASE r.name WHEN 'administrator' THEN 0 WHEN 'technician' THEN 1 ELSE 2 END, u.name",
    'organization' => 'CASE WHEN o.name IS NULL THEN 1 ELSE 0 END, o.name, u.name',
    'created' => 'u.created_at DESC, u.name',
][$filters['sort']];
$where = ['1 = 1'];
$params = [];
if ($filters['role']) {
    $where[] = 'r.name = ?';
    $params[] = $filters['role'];
}
if ($filters['organization'] === 'none') {
    $where[] = 'u.organization_id IS NULL';
} elseif ($filters['organization']) {
    $where[] = 'u.organization_id = ?';
    $params[] = $filters['organization'];
}
$stmt = $pdo->prepare("SELECT u.id, u.name, u.email, o.name AS organization, u.active, u.created_at, r.name AS role
    FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN organizations o ON o.id = u.organization_id
    WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy");
$stmt->execute($params);
$users = $stmt->fetchAll();
$hasFilters = $filters['role'] !== null || $filters['organization'] !== null;
$roleBadges = ['administrator' => ' status-assigned', 'technician' => ' status-open'];
$me = (int) current_user()['id'];
$pageTitle = 'Manage Users';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1>Manage Users</h1>
        <p class="subtitle">Create accounts for requesters and IT staff, change roles, and reset passwords.</p>
    </div>
</div>
<?php foreach ($errors as $error) : ?>
    <p class="errors" role="alert"><?= e($error) ?></p>
<?php endforeach; ?>
<section class="card"><h2>Create user</h2>
<form method="post">
    <?= action_fields('create') ?>
    <div class="form-grid">
        <div>
            <label for="new-name">Name</label>
            <input id="new-name" name="name" maxlength="100" required>
        </div>
        <div>
            <label for="new-email">Email</label>
            <input id="new-email" name="email" type="email" maxlength="190" required>
        </div>
        <div>
            <label for="new-password">Temporary password</label>
            <input id="new-password" name="password" type="password" minlength="12"
                autocomplete="new-password" required>
            <p class="field-hint">At least 12 characters. Share it privately.</p>
        </div>
        <div>
            <label for="new-organization">Organization</label>
            <select id="new-organization" name="organization_id">
                <option value="">None</option>
                <?= select_options(array_column($organizations, 'name', 'id'), null) ?>
            </select>
            <p class="field-hint">
                Manage the list in <a href="settings.php?tab=organizations">Settings &rarr; Organizations</a>.
            </p>
        </div>
        <div>
            <label for="new-role">Role</label>
            <select id="new-role" name="role">
                <option value="employee">Employee (requester)</option>
                <option value="technician">Technician</option>
                <option value="administrator">Administrator</option>
            </select>
        </div>
    </div>
    <button><?= icon('plus') ?>Create User</button>
</form>
</section>
<form class="card filters" method="get" action="users.php">
    <div class="filter-row">
        <div class="filter">
            <label for="f-role">Role</label>
            <select id="f-role" name="role" data-autosubmit>
                <option value="">Any role</option>
                <?= select_options($roleNames, $filters['role']) ?>
            </select>
        </div>
        <div class="filter">
            <label for="f-organization">Organization</label>
            <select id="f-organization" name="organization" data-autosubmit>
                <option value="">Any organization</option>
                <?= select_options(
                    ['none' => 'No organization'] + array_column($allOrganizations, 'name', 'id'),
                    $filters['organization']
                ) ?>
            </select>
        </div>
        <div class="filter">
            <label for="f-sort">Sort</label>
            <select id="f-sort" name="sort" data-autosubmit>
                <?= select_options($sortOptions, $filters['sort']) ?>
            </select>
        </div>
        <?php if ($hasFilters) : ?>
            <a class="button secondary small" href="users.php">Clear</a>
        <?php endif; ?>
    </div>
</form>
<p class="result-count">
    <?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?><?= $hasFilters ? ' match your filters' : '' ?>
</p>
<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $user) : ?>
        <tr>
            <td>
                <span class="person"><?= avatar($user['name']) ?><span>
                    <?= e($user['name']) ?>
                    <?php if ($user['organization']) : ?>
                        <span class="ticket-sub"><?= e($user['organization']) ?></span>
                    <?php endif; ?>
                </span></span>
            </td>
            <td><?= e($user['email']) ?></td>
            <td>
                <span class="badge<?= $roleBadges[$user['role']] ?? '' ?>"><?= e(ucfirst($user['role'])) ?></span>
            </td>
            <td>
                <?php if ($user['active']) : ?>
                    <span class="badge status-resolved">Active</span>
                <?php else : ?>
                    <span class="badge status-closed">Deactivated</span>
                <?php endif; ?>
            </td>
            <td class="meta"><?= e(format_time($user['created_at'])) ?></td>
            <td>
                <div class="page-actions">
                    <a class="button secondary small" href="user-edit.php?id=<?= (int) $user['id'] ?>">Edit</a>
                    <?php if ((int) $user['id'] !== $me) : ?>
                        <?php
                        $confirmText = 'Deactivate ' . $user['name'] . '? They will be signed out immediately.';
                        $confirm = $user['active'] ? ' data-confirm="' . e($confirmText) . '"' : '';
                        ?>
                        <form method="post"<?= $confirm ?>>
                            <?= action_fields('toggle') ?>
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <button class="small<?= $user['active'] ? ' danger' : '' ?>">
                                <?= $user['active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
