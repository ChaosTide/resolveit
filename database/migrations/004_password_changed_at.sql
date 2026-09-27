-- Signs users out everywhere after an administrator resets their password (checked in app/bootstrap.php).
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF COL_LENGTH(N'users', N'password_changed_at') IS NULL
    ALTER TABLE users ADD password_changed_at DATETIME2(0) NULL;
GO
