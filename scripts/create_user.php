<?php

declare(strict_types=1);

// Creates one account directly in the database. Used to make the first administrator
// account on a fresh setup, and for any other sample/test account the team needs.
// There is deliberately no web page for this -- "create an account with any role I ask
// for" must never be something an anonymous visitor can reach, so it only exists as a
// script a team member runs themselves, from a machine that already has database access.
if (PHP_SAPI !== 'cli') {
    exit("Command line only.\n");
}
if ($argc !== 5) {
    exit("Usage: php create_user.php email password role \"Full Name\"\nRoles: employee, technician, administrator\n");
}
[$script, $email, $password, $role, $name] = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid email.\n");
}
if (strlen($password) < 12) {
    exit("Password must be at least 12 characters.\n");
}
if (!in_array($role, ['employee','technician','administrator'], true)) {
    exit("Invalid role.\n");
}
$configFile = __DIR__ . '/../app/config.php';
if (!is_file($configFile)) {
    exit("Create app/config.php first.\n");
}
$config = require $configFile;
require_once __DIR__ . '/../app/db.php';
$pdo = db($config['db']);
$stmt = $pdo->prepare('INSERT INTO users (role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE name=?');
$stmt->execute([$name,mb_strtolower($email),password_hash($password, PASSWORD_DEFAULT),$role]);
echo "User created.\n";
