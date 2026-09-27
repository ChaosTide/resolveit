-- CR-10 help desk features: ticket due dates, first-response tracking, requester departments, knowledge base.
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF COL_LENGTH(N'tickets', N'due_date') IS NULL ALTER TABLE tickets ADD due_date DATE NULL;
IF COL_LENGTH(N'tickets', N'first_response_at') IS NULL ALTER TABLE tickets ADD first_response_at DATETIME2(0) NULL;
IF COL_LENGTH(N'users', N'department') IS NULL ALTER TABLE users ADD department NVARCHAR(100) NULL;
GO
-- Backfill first response from the earliest note or change made by IT staff.
UPDATE t SET first_response_at = f.first_at
FROM tickets t
CROSS APPLY (SELECT MIN(x.at) AS first_at FROM (
    SELECT n.created_at AS at FROM ticket_notes n JOIN users u ON u.id = n.author_id JOIN roles r ON r.id = u.role_id WHERE n.ticket_id = t.id AND r.name <> N'employee'
    UNION ALL
    SELECT h.created_at FROM ticket_history h JOIN users u ON u.id = h.changed_by JOIN roles r ON r.id = u.role_id WHERE h.ticket_id = t.id AND r.name <> N'employee' AND h.action <> N'created'
) x) f
WHERE t.first_response_at IS NULL AND f.first_at IS NOT NULL;
GO
IF OBJECT_ID(N'kb_articles', N'U') IS NULL
BEGIN
    CREATE TABLE kb_articles (
     id INT IDENTITY(1,1) PRIMARY KEY, title NVARCHAR(200) NOT NULL, body NVARCHAR(MAX) NOT NULL,
     category_id SMALLINT NULL, author_id INT NOT NULL,
     published BIT NOT NULL CONSTRAINT df_kb_published DEFAULT 1,
     views INT NOT NULL CONSTRAINT df_kb_views DEFAULT 0,
     created_at DATETIME2(0) NOT NULL CONSTRAINT df_kb_created DEFAULT SYSDATETIME(),
     updated_at DATETIME2(0) NOT NULL CONSTRAINT df_kb_updated DEFAULT SYSDATETIME(),
     CONSTRAINT fk_kb_category FOREIGN KEY (category_id) REFERENCES categories(id),
     CONSTRAINT fk_kb_author FOREIGN KEY (author_id) REFERENCES users(id)
    );
    CREATE INDEX idx_kb_published ON kb_articles(published, updated_at);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_ticket_due_date' AND object_id = OBJECT_ID(N'tickets'))
    CREATE INDEX idx_ticket_due_date ON tickets(due_date);
GO
