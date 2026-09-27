<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../app/db.php';
$config = require __DIR__.'/../app/config.azure.php';
$pdo = db($config['db']);
// The empty Azure database receives the reviewed schema through a direct connection.
// A committed migration marker makes container restarts safe.
if (!$pdo->query("SELECT OBJECT_ID('dbo.capstone_migrations')")->fetchColumn()) {
    $pdo->beginTransaction();
    try {
        if ($pdo->query("SELECT COUNT(*) FROM sys.tables")->fetchColumn() != 0) throw new RuntimeException('Refusing to overwrite existing tables.');
        $schema = file_get_contents(__DIR__.'/../database/schema.sql');
        $schema = substr($schema, strpos($schema, 'CREATE TABLE roles'));
        $schema = preg_split('/^GO\s*$/m', $schema)[0];
        $stmt=$pdo->query($schema);
        while($stmt->nextRowset()) {}
        $stmt->closeCursor();
        $pdo->exec('CREATE TABLE dbo.capstone_migrations (id INT PRIMARY KEY, applied_at DATETIME2 DEFAULT SYSUTCDATETIME())');
        // Team sign-in uses Microsoft identity; passwords are random and never used remotely.
        $names=['jlb282'=>'Jordan Black','jpd34'=>'James Dawson','nhp6'=>'Noah Parker','scs77'=>'Serigne Seck','rhk7'=>'Ryan Kalenda','jpe15'=>'Joseph Eilerman'];
        $insert=$pdo->prepare('INSERT INTO users(role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE name=?');
        foreach($names as $prefix=>$name) {
            $insert->execute([$name,$prefix.'@students.uwf.edu',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$prefix==='jlb282'?'administrator':'employee']);
        }
        $pdo->exec('INSERT INTO dbo.capstone_migrations(id) VALUES(1)');
        $pdo->commit();
        echo "Cloud schema and six team accounts created.\n";
    } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
}
echo 'Cloud table count: '.$pdo->query('SELECT COUNT(*) FROM sys.tables')->fetchColumn()."\n";
