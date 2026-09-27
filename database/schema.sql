-- CTS 4911 Ticket Management System for Microsoft SQL Server 2019+
-- Run while connected to WORKSTATION\JLB282 with Windows Authentication.
USE master;
GO
IF DB_ID(N'TicketManagement') IS NULL CREATE DATABASE TicketManagement;
GO
USE TicketManagement;
GO
CREATE TABLE roles (id TINYINT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(30) NOT NULL UNIQUE);
CREATE TABLE users (
 id INT IDENTITY(1,1) PRIMARY KEY, role_id TINYINT NOT NULL, name NVARCHAR(100) NOT NULL,
 email NVARCHAR(190) NOT NULL UNIQUE, password_hash NVARCHAR(255) NOT NULL,
 active BIT NOT NULL CONSTRAINT df_users_active DEFAULT 1,
 created_at DATETIME2(0) NOT NULL CONSTRAINT df_users_created DEFAULT SYSDATETIME(),
 CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
);
CREATE TABLE categories (id SMALLINT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(80) NOT NULL UNIQUE, active BIT NOT NULL CONSTRAINT df_categories_active DEFAULT 1);
CREATE TABLE priorities (id TINYINT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(20) NOT NULL UNIQUE, sort_order TINYINT NOT NULL UNIQUE);
CREATE TABLE statuses (id TINYINT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(30) NOT NULL UNIQUE, sort_order TINYINT NOT NULL UNIQUE);
CREATE TABLE tickets (
 id INT IDENTITY(1,1) PRIMARY KEY, requester_id INT NOT NULL, assigned_to INT NULL,
 category_id SMALLINT NOT NULL, priority_id TINYINT NOT NULL, status_id TINYINT NOT NULL,
 title NVARCHAR(150) NOT NULL, description NVARCHAR(MAX) NOT NULL, resolution NVARCHAR(MAX) NULL,
 created_at DATETIME2(0) NOT NULL CONSTRAINT df_tickets_created DEFAULT SYSDATETIME(),
 updated_at DATETIME2(0) NOT NULL CONSTRAINT df_tickets_updated DEFAULT SYSDATETIME(),
 resolved_at DATETIME2(0) NULL, closed_at DATETIME2(0) NULL,
 CONSTRAINT fk_ticket_requester FOREIGN KEY (requester_id) REFERENCES users(id),
 CONSTRAINT fk_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
 CONSTRAINT fk_ticket_category FOREIGN KEY (category_id) REFERENCES categories(id),
 CONSTRAINT fk_ticket_priority FOREIGN KEY (priority_id) REFERENCES priorities(id),
 CONSTRAINT fk_ticket_status FOREIGN KEY (status_id) REFERENCES statuses(id)
);
CREATE INDEX idx_ticket_requester ON tickets(requester_id);
CREATE INDEX idx_ticket_assignee ON tickets(assigned_to);
CREATE INDEX idx_ticket_status_priority ON tickets(status_id, priority_id);
CREATE TABLE ticket_notes (
 id BIGINT IDENTITY(1,1) PRIMARY KEY, ticket_id INT NOT NULL, author_id INT NOT NULL,
 note NVARCHAR(MAX) NOT NULL, created_at DATETIME2(0) NOT NULL CONSTRAINT df_notes_created DEFAULT SYSDATETIME(),
 CONSTRAINT fk_note_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_note_author FOREIGN KEY (author_id) REFERENCES users(id)
);
CREATE INDEX idx_note_ticket ON ticket_notes(ticket_id, created_at);
CREATE TABLE ticket_history (
 id BIGINT IDENTITY(1,1) PRIMARY KEY, ticket_id INT NOT NULL, changed_by INT NOT NULL,
 action NVARCHAR(40) NOT NULL, old_value NVARCHAR(255) NULL, new_value NVARCHAR(255) NULL,
 created_at DATETIME2(0) NOT NULL CONSTRAINT df_history_created DEFAULT SYSDATETIME(),
 CONSTRAINT fk_history_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
);
CREATE INDEX idx_history_ticket ON ticket_history(ticket_id, created_at);
CREATE TABLE admin_audit_log (
 id BIGINT IDENTITY(1,1) PRIMARY KEY, admin_id INT NOT NULL,
 action NVARCHAR(60) NOT NULL, target NVARCHAR(190) NULL, details NVARCHAR(500) NULL,
 created_at DATETIME2(0) NOT NULL CONSTRAINT df_audit_created DEFAULT SYSDATETIME(),
 CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES users(id)
);
CREATE INDEX idx_audit_created ON admin_audit_log(created_at);
INSERT INTO roles (name) VALUES (N'employee'), (N'technician'), (N'administrator');
INSERT INTO categories (name) VALUES (N'Hardware'), (N'Software'), (N'Network'), (N'Account Access'), (N'Other');
INSERT INTO priorities (name, sort_order) VALUES (N'Low',1), (N'Medium',2), (N'High',3), (N'Critical',4);
INSERT INTO statuses (name, sort_order) VALUES (N'Open',1), (N'Assigned',2), (N'In Progress',3), (N'Resolved',4), (N'Closed',5);
GO
SELECT DB_NAME() AS database_name, (SELECT COUNT(*) FROM sys.tables) AS table_count;
GO
