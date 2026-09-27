<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role('administrator');
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf(); $action=clean_text($_POST['action']??'');
    if ($action==='create') {
        $name=clean_text($_POST['name']??''); $email=mb_strtolower(clean_text($_POST['email']??'')); $password=is_string($_POST['password']??null)?$_POST['password']:''; $role=clean_text($_POST['role']??'');
        if ($name===''||mb_strlen($name)>100) $errors[]='Name is required and must be at most 100 characters.';
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>190) $errors[]='Enter a valid email.';
        if (strlen($password)<12) $errors[]='Password must be at least 12 characters.';
        if (!in_array($role,['employee','technician','administrator'],true)) $errors[]='Choose a valid role.';
        if (!$errors) { try { $stmt=$pdo->prepare('INSERT INTO users (role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE name=?'); $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]); audit_log($pdo,'user created',$email,"Role: $role"); flash('success','User created.'); redirect('users.php'); } catch(PDOException $ex) { $errors[]=$ex->getCode()==='23000'?'That email already exists.':'The user could not be created.'; } }
    } elseif ($action==='toggle') {
        $userId=valid_id($_POST['user_id']??null); if (!$userId||$userId===(int)current_user()['id']) $errors[]='You cannot change this account.';
        if (!$errors) {
            $stmt=$pdo->prepare('UPDATE users SET active=CASE WHEN active=1 THEN 0 ELSE 1 END OUTPUT INSERTED.email, INSERTED.active WHERE id=?'); $stmt->execute([$userId]); $changed=$stmt->fetch();
            if ($changed) { audit_log($pdo,$changed['active']?'user activated':'user deactivated',$changed['email']); flash('success','Account status changed.'); redirect('users.php'); }
            $errors[]='That user no longer exists.';
        }
    }
}
$users=$pdo->query('SELECT u.id,u.name,u.email,u.active,u.created_at,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.name')->fetchAll();
$pageTitle='Manage Users'; require __DIR__.'/partials/header.php';
?>
<h1>Manage Users</h1><?php foreach($errors as $error): ?><p class="errors"><?= e($error) ?></p><?php endforeach; ?>
<section class="card"><h2>Create user</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" maxlength="100" required></label><label>Email<input name="email" type="email" maxlength="190" required></label><label>Temporary password<input name="password" type="password" minlength="12" required></label><label>Role<select name="role"><option value="employee">Employee</option><option value="technician">Technician</option><option value="administrator">Administrator</option></select></label><button>Create User</button></form></section>
<table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active</th><th>Action</th></tr></thead><tbody><?php foreach($users as $user): ?><tr><td><?= e($user['name']) ?></td><td><?= e($user['email']) ?></td><td><?= e($user['role']) ?></td><td><?= $user['active']?'Yes':'No' ?></td><td><a href="user-edit.php?id=<?= (int)$user['id'] ?>">Edit</a><?php if((int)$user['id']!==(int)current_user()['id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button><?= $user['active']?'Deactivate':'Activate' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php require __DIR__.'/partials/footer.php'; ?>
