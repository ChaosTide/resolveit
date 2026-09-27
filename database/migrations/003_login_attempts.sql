-- Records sign-in attempts so repeated wrong passwords can be locked out (app/auth.php).
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF OBJECT_ID(N'login_attempts', N'U') IS NULL
BEGIN
    CREATE TABLE login_attempts (
     id BIGINT IDENTITY(1,1) PRIMARY KEY, email NVARCHAR(190) NOT NULL, ip NVARCHAR(45) NOT NULL,
     succeeded BIT NOT NULL, created_at DATETIME2(0) NOT NULL CONSTRAINT df_login_attempts_created DEFAULT SYSDATETIME()
    );
    CREATE INDEX idx_login_attempts_email ON login_attempts(email, created_at);
    CREATE INDEX idx_login_attempts_ip ON login_attempts(ip, created_at);
END
GO
