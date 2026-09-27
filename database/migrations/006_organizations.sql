-- CR-11: organizations become real records (replacing the free-text users.department from migration 005).
-- Existing department names are copied into organizations before the old column is dropped.
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF OBJECT_ID(N'organizations', N'U') IS NULL
BEGIN
    CREATE TABLE organizations (
     id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100) NOT NULL CONSTRAINT uq_organizations_name UNIQUE,
     active BIT NOT NULL CONSTRAINT df_organizations_active DEFAULT 1,
     created_at DATETIME2(0) NOT NULL CONSTRAINT df_organizations_created DEFAULT SYSDATETIME()
    );
END
GO
IF COL_LENGTH(N'users', N'organization_id') IS NULL
    ALTER TABLE users ADD organization_id INT NULL CONSTRAINT fk_users_organization FOREIGN KEY REFERENCES organizations(id);
GO
IF COL_LENGTH(N'users', N'department') IS NOT NULL
BEGIN
    EXEC(N'INSERT INTO organizations (name)
          SELECT DISTINCT LTRIM(RTRIM(department)) FROM users
          WHERE department IS NOT NULL AND LTRIM(RTRIM(department)) <> N''''
            AND LTRIM(RTRIM(department)) NOT IN (SELECT name FROM organizations);
          UPDATE u SET organization_id = o.id FROM users u JOIN organizations o ON o.name = LTRIM(RTRIM(u.department))
          WHERE u.organization_id IS NULL;');
    ALTER TABLE users DROP COLUMN department;
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_users_organization' AND object_id = OBJECT_ID(N'users'))
    CREATE INDEX idx_users_organization ON users(organization_id);
GO
