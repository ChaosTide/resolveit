-- Adds the administrator audit log used by users.php, user-edit.php, and settings.php.
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF OBJECT_ID(N'admin_audit_log', N'U') IS NULL
BEGIN
    CREATE TABLE admin_audit_log (
     id BIGINT IDENTITY(1,1) PRIMARY KEY, admin_id INT NOT NULL,
     action NVARCHAR(60) NOT NULL, target NVARCHAR(190) NULL, details NVARCHAR(500) NULL,
     created_at DATETIME2(0) NOT NULL CONSTRAINT df_audit_created DEFAULT SYSDATETIME(),
     CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES users(id)
    );
    CREATE INDEX idx_audit_created ON admin_audit_log(created_at);
END
GO
