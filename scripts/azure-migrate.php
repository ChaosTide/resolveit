<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../app/db.php';
$config = require __DIR__ . '/../app/config.azure.php';
$pdo = db($config['db']);
// The empty Azure database receives the reviewed schema through a direct connection.
// A committed migration marker makes container restarts safe.
if (!$pdo->query("SELECT OBJECT_ID('dbo.capstone_migrations')")->fetchColumn()) {
    $pdo->beginTransaction();
    try {
        if ($pdo->query("SELECT COUNT(*) FROM sys.tables")->fetchColumn() != 0) {
            throw new RuntimeException('Refusing to overwrite existing tables.');
        }
        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
        $schema = substr($schema, strpos($schema, 'CREATE TABLE roles'));
        $schema = preg_split('/^GO\s*$/m', $schema)[0];
        $stmt = $pdo->query($schema);
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        $pdo->exec('CREATE TABLE dbo.capstone_migrations (id INT PRIMARY KEY, applied_at DATETIME2 DEFAULT SYSUTCDATETIME())');
        // Team sign-in uses Microsoft identity; passwords are random and never used remotely.
        $names = ['jlb282' => 'Jordan Black','jpd34' => 'James Dawson','nhp6' => 'Noah Parker','scs77' => 'Serigne Seck','rhk7' => 'Ryan Kalenda','jpe15' => 'Joseph Eilerman'];
        $insert = $pdo->prepare('INSERT INTO users(role_id,name,email,password_hash) SELECT id,?,?,? FROM roles WHERE name=?');
        foreach ($names as $prefix => $name) {
            $insert->execute([$name,$prefix . '@students.uwf.edu',password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),$prefix === 'jlb282' ? 'administrator' : 'employee']);
        }
        // schema.sql already contains migrations 2-7 (audit log, SLA, login attempts, password_changed_at, help desk features, organizations), so mark them applied.
        $pdo->exec('INSERT INTO dbo.capstone_migrations(id) VALUES(1),(2),(3),(4),(5),(6),(7)');
        $pdo->commit();
        echo "Cloud schema and six team accounts created.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
// Upgrades for cloud databases created before later features (mirrors database/migrations/*.sql).
$upgrades = [
    2 => ["IF OBJECT_ID(N'admin_audit_log', N'U') IS NULL BEGIN
            CREATE TABLE admin_audit_log (id BIGINT IDENTITY(1,1) PRIMARY KEY, admin_id INT NOT NULL,
             action NVARCHAR(60) NOT NULL, target NVARCHAR(190) NULL, details NVARCHAR(500) NULL,
             created_at DATETIME2(0) NOT NULL CONSTRAINT df_audit_created DEFAULT SYSDATETIME(),
             CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES users(id));
            CREATE INDEX idx_audit_created ON admin_audit_log(created_at);
          END"],
    3 => ["IF COL_LENGTH(N'priorities', N'sla_hours') IS NULL
            ALTER TABLE priorities ADD sla_hours SMALLINT NOT NULL CONSTRAINT df_priorities_sla DEFAULT 24
             CONSTRAINT ck_priorities_sla CHECK (sla_hours BETWEEN 1 AND 720)",
          "UPDATE priorities SET sla_hours = CASE sort_order WHEN 1 THEN 72 WHEN 2 THEN 24 WHEN 3 THEN 8 WHEN 4 THEN 4 ELSE sla_hours END",
          "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_ticket_created' AND object_id = OBJECT_ID(N'tickets'))
            CREATE INDEX idx_ticket_created ON tickets(created_at)"],
    4 => ["IF OBJECT_ID(N'login_attempts', N'U') IS NULL BEGIN
            CREATE TABLE login_attempts (id BIGINT IDENTITY(1,1) PRIMARY KEY, email NVARCHAR(190) NOT NULL, ip NVARCHAR(45) NOT NULL,
             succeeded BIT NOT NULL, created_at DATETIME2(0) NOT NULL CONSTRAINT df_login_attempts_created DEFAULT SYSDATETIME());
            CREATE INDEX idx_login_attempts_email ON login_attempts(email, created_at);
            CREATE INDEX idx_login_attempts_ip ON login_attempts(ip, created_at);
          END"],
    5 => ["IF COL_LENGTH(N'users', N'password_changed_at') IS NULL ALTER TABLE users ADD password_changed_at DATETIME2(0) NULL"],
    6 => ["IF COL_LENGTH(N'tickets', N'due_date') IS NULL ALTER TABLE tickets ADD due_date DATE NULL",
          "IF COL_LENGTH(N'tickets', N'first_response_at') IS NULL ALTER TABLE tickets ADD first_response_at DATETIME2(0) NULL",
          "IF COL_LENGTH(N'users', N'department') IS NULL ALTER TABLE users ADD department NVARCHAR(100) NULL",
          "IF OBJECT_ID(N'kb_articles', N'U') IS NULL BEGIN
            CREATE TABLE kb_articles (id INT IDENTITY(1,1) PRIMARY KEY, title NVARCHAR(200) NOT NULL, body NVARCHAR(MAX) NOT NULL,
             category_id SMALLINT NULL, author_id INT NOT NULL, published BIT NOT NULL CONSTRAINT df_kb_published DEFAULT 1,
             views INT NOT NULL CONSTRAINT df_kb_views DEFAULT 0,
             created_at DATETIME2(0) NOT NULL CONSTRAINT df_kb_created DEFAULT SYSDATETIME(),
             updated_at DATETIME2(0) NOT NULL CONSTRAINT df_kb_updated DEFAULT SYSDATETIME(),
             CONSTRAINT fk_kb_category FOREIGN KEY (category_id) REFERENCES categories(id),
             CONSTRAINT fk_kb_author FOREIGN KEY (author_id) REFERENCES users(id));
            CREATE INDEX idx_kb_published ON kb_articles(published, updated_at);
          END",
          "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_ticket_due_date' AND object_id = OBJECT_ID(N'tickets'))
            CREATE INDEX idx_ticket_due_date ON tickets(due_date)"],
    7 => ["IF OBJECT_ID(N'organizations', N'U') IS NULL
            CREATE TABLE organizations (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100) NOT NULL CONSTRAINT uq_organizations_name UNIQUE,
             active BIT NOT NULL CONSTRAINT df_organizations_active DEFAULT 1,
             created_at DATETIME2(0) NOT NULL CONSTRAINT df_organizations_created DEFAULT SYSDATETIME())",
          "IF COL_LENGTH(N'users', N'organization_id') IS NULL
            ALTER TABLE users ADD organization_id INT NULL CONSTRAINT fk_users_organization FOREIGN KEY REFERENCES organizations(id)",
          "IF COL_LENGTH(N'users', N'department') IS NOT NULL
            EXEC(N'INSERT INTO organizations (name) SELECT DISTINCT LTRIM(RTRIM(department)) FROM users
                   WHERE department IS NOT NULL AND LTRIM(RTRIM(department)) <> N'''' AND LTRIM(RTRIM(department)) NOT IN (SELECT name FROM organizations);
                   UPDATE u SET organization_id = o.id FROM users u JOIN organizations o ON o.name = LTRIM(RTRIM(u.department)) WHERE u.organization_id IS NULL;
                   ALTER TABLE users DROP COLUMN department;')",
          "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_users_organization' AND object_id = OBJECT_ID(N'users'))
            CREATE INDEX idx_users_organization ON users(organization_id)"],
];
$applied = array_map('intval', $pdo->query('SELECT id FROM dbo.capstone_migrations')->fetchAll(PDO::FETCH_COLUMN));
foreach ($upgrades as $id => $statements) {
    if (in_array($id, $applied, true)) {
        continue;
    }
    $pdo->beginTransaction();
    try {
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare('INSERT INTO dbo.capstone_migrations(id) VALUES(?)')->execute([$id]);
        $pdo->commit();
        echo "Applied cloud migration $id.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
echo 'Cloud table count: ' . $pdo->query('SELECT COUNT(*) FROM sys.tables')->fetchColumn() . "\n";
