<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('administrator');
$userId = valid_id($_GET['id'] ?? $_POST['id'] ?? null);
if (!$userId) { http_response_code(400); exit('Invalid user ID.'); }
$stmt = $pdo->prepare('SELECT u.id,u.name,u.email,u.department,u.active,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
$stmt->execute([$userId]); $user = $stmt->fetch();
if (!$user) { http_response_code(404); exit('User not found.'); }
$isSelf = $userId === (int) current_user()['id'];
$roles = ['employee','technician','administrator'];
$errors = [];
$user['department'] = (string) ($user['department'] ?? '');
$data = ['name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'department' => $user['department']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $data = ['name' => clean_text($_POST['name'] ?? ''), 'email' => mb_strtolower(clean_text($_POST['email'] ?? '')), 'role' => clean_text($_POST['role'] ?? ''), 'department' => clean_text($_POST['department'] ?? '')];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if ($data['name'] === '' || mb_strlen($data['name']) > 100) $errors[] = 'Name is required and must be at most 100 characters.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 190) $errors[] = 'Enter a valid email.';
    if (!in_array($data['role'], $roles, true)) $errors[] = 'Choose a valid role.';
    if ($isSelf && $data['role'] !== $user['role']) $errors[] = 'You cannot change your own role. Ask another administrator.';
    if (mb_strlen($data['department']) > 100) $errors[] = 'Department must be at most 100 characters.';
    if ($password !== '' && strlen($password) < 12) $errors[] = 'A new password must be at least 12 characters.';
    if (!$errors) {
        $changes = [];
        foreach (['name','email','role','department'] as $field) {
            if ($data[$field] !== $user[$field]) $changes[] = "$field: {$user[$field]} -> {$data[$field]}";
        }
        if ($password !== '') $changes[] = 'password reset';
        if (!$changes) { flash('success', 'No changes to save.'); redirect('users.php'); }
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE users SET name=?, email=?, department=?, role_id=(SELECT id FROM roles WHERE name=?) WHERE id=?');
            $update->execute([$data['name'], $data['email'], $data['department'] === '' ? null : $data['department'], $data['role'], $userId]);
            $now = date('Y-m-d H:i:s');
            if ($password !== '') {
                // password_changed_at signs the user out of every other session (app/auth.php refresh_session_user).
                $pdo->prepare('UPDATE users SET password_hash=?, password_changed_at=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $now, $userId]);
            }
            audit_log($pdo, 'user edited', $data['email'], implode('; ', $changes));
            $pdo->commit();
            if ($isSelf) {
                $_SESSION['user']['name'] = $data['name']; $_SESSION['user']['email'] = $data['email'];
                if ($password !== '') $_SESSION['login_at'] = $now; // keep the administrator's own session
            }
            flash('success', 'User updated.'); redirect('users.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $ex->getCode() === '23000' ? 'That email already belongs to another user.' : 'The user could not be updated.';
        }
    }
}
$pageTitle = 'Edit User'; require __DIR__ . '/partials/header.php';
?>
<div class="page-header"><div><p class="breadcrumb"><a href="users.php">Manage Users</a> / Edit</p><h1>Edit user</h1><p class="subtitle"><?= e($user['name']) ?> &middot; <?= e($user['email']) ?></p></div></div>
<section class="card" style="max-width: 640px">
<p class="meta">Account is <?= $user['active'] ? 'active' : 'deactivated' ?>. Activate or deactivate it from <a href="users.php">Manage Users</a>.</p>
<?php foreach ($errors as $error): ?><p class="errors"><?= e($error) ?></p><?php endforeach; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $userId ?>">
    <label for="name">Name</label><input id="name" name="name" maxlength="100" value="<?= e($data['name']) ?>" required>
    <label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($data['email']) ?>" required>
    <label for="department">Department</label><input id="department" name="department" maxlength="100" value="<?= e($data['department']) ?>" placeholder="e.g. Finance, Library">
    <label for="role">Role</label>
    <select id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>><?php foreach ($roles as $role): ?><option value="<?= $role ?>" <?= $data['role'] === $role ? 'selected' : '' ?>><?= ucfirst($role) ?></option><?php endforeach; ?></select>
    <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($user['role']) ?>"><p class="meta">You cannot change your own role.</p><?php endif; ?>
    <label for="password">New password</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password"><p class="field-hint">Leave blank to keep the current password. Setting one signs this user out everywhere.</p>
    <div class="form-actions"><button type="submit">Save User</button><a class="button secondary" href="users.php" style="margin-top:1rem">Cancel</a></div>
</form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
