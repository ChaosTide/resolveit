<?php
declare(strict_types=1);
// First-run setup for the local Docker stack (deploy/docker/docker-compose.yml). Safe to run on every start.
//  1. waits for the SQL Server container to accept connections
//  2. as the SQL admin: creates the database and a separate, least-privilege login for the website
//  3. as the website login: creates/upgrades the tables (scripts/azure-migrate.php)
//  4. creates the site administrator from ADMIN_EMAIL / ADMIN_PASSWORD if that account does not exist
if (PHP_SAPI !== 'cli') exit;

function env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') { fwrite(STDERR, "Missing environment variable $key\n"); exit(1); }
    return $value;
}

$server = env_required('DB_SERVER');
$dbName = env_required('DB_NAME');
$appUser = env_required('DB_USER');
$appPassword = env_required('DB_PASSWORD');
$saUser = env_required('DB_ADMIN_USER');
$saPassword = env_required('DB_ADMIN_PASSWORD');
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $dbName) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $appUser)) {
    fwrite(STDERR, "DB_NAME and DB_USER must be simple identifiers.\n"); exit(1);
}
$dsn = fn (string $database) => "sqlsrv:Server=$server;Database=$database;Encrypt=yes;TrustServerCertificate=true";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

// 1. SQL Server takes a while to start the first time.
$master = null;
for ($attempt = 1; $attempt <= 60 && !$master; $attempt++) {
    try { $master = new PDO($dsn('master'), $saUser, $saPassword, $options); }
    catch (PDOException $e) { echo "Waiting for SQL Server ($attempt/60)...\n"; sleep(2); }
}
if (!$master) { fwrite(STDERR, "SQL Server did not become available.\n"); exit(1); }

// 2. Database and website login (identifiers validated above; the password is a quoted literal).
$quoted = "N'" . str_replace("'", "''", $appPassword) . "'";
$master->exec("IF DB_ID(N'$dbName') IS NULL CREATE DATABASE [$dbName]");
$master->exec("IF SUSER_ID(N'$appUser') IS NULL CREATE LOGIN [$appUser] WITH PASSWORD = $quoted, CHECK_POLICY = ON
               ELSE ALTER LOGIN [$appUser] WITH PASSWORD = $quoted");
$master->exec("USE [$dbName];
    IF USER_ID(N'$appUser') IS NULL CREATE USER [$appUser] FOR LOGIN [$appUser];
    IF IS_ROLEMEMBER(N'db_owner', N'$appUser') = 0 ALTER ROLE db_owner ADD MEMBER [$appUser];");
$master = null;
echo "Database $dbName and login $appUser are ready.\n";

// 3. Tables, reference data, and later upgrades (shared with Azure).
passthru('php ' . escapeshellarg(__DIR__ . '/azure-migrate.php'), $status);
if ($status !== 0) exit($status);

// 4. Site administrator for password sign-in.
$adminEmail = mb_strtolower(env_required('ADMIN_EMAIL'));
$adminPassword = env_required('ADMIN_PASSWORD');
if (strlen($adminPassword) < 12) { fwrite(STDERR, "ADMIN_PASSWORD must be at least 12 characters.\n"); exit(1); }
$pdo = new PDO($dsn($dbName), $appUser, $appPassword, $options);
$exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
$exists->execute([$adminEmail]);
if ((int) $exists->fetchColumn() === 0) {
    $pdo->prepare("INSERT INTO users (role_id, name, email, password_hash) SELECT id, ?, ?, ? FROM roles WHERE name = 'administrator'")
        ->execute([getenv('ADMIN_NAME') ?: 'Docker Admin', $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT)]);
    echo "Created administrator $adminEmail.\n";
} else {
    echo "Administrator $adminEmail already exists.\n";
}
