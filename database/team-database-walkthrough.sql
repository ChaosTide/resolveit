-- CTS 4911: Team database walkthrough
-- Read-only: these queries do not create, change, or delete data.
-- Local server: WORKSTATION\JLB282
-- This demonstrates the local database, not the Azure migration status.
USE TicketManagement;
GO

-- 1. Confirm which server and database we are viewing.
SELECT @@SERVERNAME AS ServerName, DB_NAME() AS DatabaseName;
GO

-- 2. Explain the eight project tables.
SELECT TableName, Purpose
FROM (VALUES
 (1, 'users', 'Account names, emails, password hashes, active status, and role. Password hashes are not displayed in this walkthrough.'),
 (2, 'roles', 'Employee, technician, and administrator role names. Application code enforces their permissions.'),
 (3, 'tickets', 'Support requests: requester, assigned staff member, description, category, priority, status, and resolution.'),
 (4, 'categories', 'Issue classifications such as Hardware, Software, Network, and Account Access.'),
 (5, 'priorities', 'Urgency levels: Low, Medium, High, and Critical.'),
 (6, 'statuses', 'Lifecycle labels: Open, Assigned, In Progress, Resolved, and Closed. Labels alone do not enforce transition order.'),
 (7, 'ticket_notes', 'Troubleshooting notes linked to a ticket, with author and timestamp.'),
 (8, 'ticket_history', 'Significant ticket changes, including actor, time, and old/new values.')
) AS Explanation(DisplayOrder, TableName, Purpose)
ORDER BY DisplayOrder;
GO

-- 3. See the actual tables and current row counts.
SELECT t.name AS TableName, SUM(p.rows) AS ApproximateRowCount
FROM sys.tables t
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
WHERE t.is_ms_shipped = 0
GROUP BY t.name
ORDER BY t.name;
GO

-- 4. See the columns and data types in each table.
SELECT TABLE_NAME AS TableName, COLUMN_NAME AS ColumnName,
       DATA_TYPE AS DataType, IS_NULLABLE AS AllowsNull
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'dbo'
ORDER BY TABLE_NAME, ORDINAL_POSITION;
GO

-- 5. Foreign keys connect related records.
SELECT OBJECT_NAME(fkc.parent_object_id) AS FromTable,
       COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS FromColumn,
       OBJECT_NAME(fkc.referenced_object_id) AS ReferencedTable,
       COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ReferencedColumn
FROM sys.foreign_key_columns fkc
ORDER BY FromTable, FromColumn;
GO

-- 6. Reference values used by the application.
SELECT id, name FROM roles ORDER BY id;
SELECT id, name, active FROM categories ORDER BY id;
SELECT id, name, sort_order FROM priorities ORDER BY sort_order;
SELECT id, name, sort_order FROM statuses ORDER BY sort_order;
GO

-- 7. Readable ticket example: joins turn numeric IDs into meaningful labels.
-- QA tickets are test evidence, not real employee incidents.
SELECT TOP (20) t.id AS TicketNumber, t.title,
       requester.name AS RequestedBy, assignee.name AS AssignedTo,
       c.name AS Category, p.name AS Priority, s.name AS Status,
       t.created_at, t.updated_at
FROM tickets t
JOIN users requester ON requester.id = t.requester_id
LEFT JOIN users assignee ON assignee.id = t.assigned_to
JOIN categories c ON c.id = t.category_id
JOIN priorities p ON p.id = t.priority_id
JOIN statuses s ON s.id = t.status_id
ORDER BY t.id DESC;
GO

-- 8. Supporting notes and history for the most recent tickets.
SELECT TOP (20) n.ticket_id, u.name AS Author, n.note, n.created_at
FROM ticket_notes n JOIN users u ON u.id = n.author_id
ORDER BY n.id DESC;
SELECT TOP (30) h.ticket_id, u.name AS ChangedBy, h.action,
       h.old_value, h.new_value, h.created_at
FROM ticket_history h JOIN users u ON u.id = h.changed_by
ORDER BY h.id DESC;
GO
