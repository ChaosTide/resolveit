<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
if ($argc !== 5) { exit("Usage: php create_user.php email password role \"Full Name\"\nRoles: employee, technician, administrator\n"); }
[$script, $email, $password, $role, $name] = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Invalid email.\n");
if (strlen($password) < 12) exit("Password must be at least 12 characters.\n");
if (!in_array($role, ['employee','technician','administrator'], true)) exit("Invalid role.\n");
$configFile=__DIR__.'/../app/config.php'; if(!is_file($configFile)) exit("Create app/config.php first.\n");
$config=require $configFile; require_once __DIR__.'/../app/db.php'; $pdo=db($config['db']);
$stmt=$pdo->prepare('INSERT INTO users (role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE name=?');
$stmt->execute([$name,mb_strtolower($email),password_hash($password,PASSWORD_DEFAULT),$role]);
echo "User created.\n";
