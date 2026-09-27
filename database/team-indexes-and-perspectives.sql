-- CTS 4911: Indexes and user perspectives
-- READ ONLY: safe to execute for the team demonstration.
-- Connect to WORKSTATION\JLB282. This is the local prototype.
USE TicketManagement;
GO
SELECT @@SERVERNAME AS ServerName, DB_NAME() AS DatabaseName;

-- A clustered index stores the table rows at its leaf level in key order.
-- Each project table currently has one clustered primary-key index on id.
-- A nonclustered index is a separate lookup structure.
-- Indexes support retrieval; they do NOT enforce application permissions.
-- Always use ORDER BY when a query needs a guaranteed display order.

-- 1. Actual indexes and their ordered key columns.
SELECT t.name AS TableName, i.name AS IndexName, i.type_desc AS IndexType,
       i.is_primary_key AS IsPrimaryKey, i.is_unique AS IsUnique,
       STRING_AGG(CONVERT(nvarchar(max), QUOTENAME(c.name)), ', ')
           WITHIN GROUP (ORDER BY ic.key_ordinal) AS KeyColumns,
       CASE
         WHEN i.is_primary_key=1 THEN 'Unique row identifier; clustered table structure ordered by the primary key.'
         WHEN t.name='users' AND i.is_unique=1 THEN 'Prevents duplicate email accounts and supports email lookup during login.'
         WHEN i.name='idx_ticket_requester' THEN 'Supports finding tickets for a requester: employee My Tickets.'
         WHEN i.name='idx_ticket_assignee' THEN 'Supports finding tickets assigned to a support staff member.'
         WHEN i.name='idx_ticket_status_priority' THEN 'Supports filtering by status, or status plus priority. Priority IDs are not urgency rankings.'
         WHEN i.name='idx_note_ticket' THEN 'Supports retrieving one ticket''s notes in timestamp order.'
         WHEN i.name='idx_history_ticket' THEN 'Supports retrieving one ticket''s change history in timestamp order.'
         WHEN i.is_unique=1 THEN 'Enforces distinct reference names or sort-order values.'
         ELSE 'Additional lookup structure.'
       END AS Purpose
FROM sys.tables t
JOIN sys.indexes i ON i.object_id=t.object_id
JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
WHERE t.name IN ('users','roles','tickets','categories','priorities','statuses','ticket_notes','ticket_history')
  AND ic.key_ordinal>0 AND i.is_hypothetical=0
GROUP BY t.name,i.name,i.type_desc,i.is_primary_key,i.is_unique
ORDER BY t.name,i.is_primary_key DESC,i.name;
GO

-- 2. How the tables support each perspective.
SELECT Perspective, MainTables, Explanation
FROM (VALUES
 ('Employee', 'tickets, users, categories, priorities, statuses', 'Submit a request and see its status. The application must restrict results to the signed-in requester.'),
 ('Technician', 'tickets, ticket_notes, ticket_history, reference tables', 'Review work, assign responsibility, prioritize, record troubleshooting, and resolve requests.'),
 ('Administrator', 'users, roles, tickets', 'Manage accounts and perform technician functions. Current user-management page supports creation and activation/deactivation.'),
 ('Testing expert', 'All eight project tables', 'Compare requirements with behavior, verify record relationships, and document defects. This is Jordan''s project responsibility, not a fourth application role.')
) AS Perspectives(Perspective, MainTables, Explanation);
GO

-- 3. Employee perspective: choose a DEMO requester from an existing ticket.
-- This is a presentation query, not a security boundary.
-- In PHP, requester_id must come from the authenticated session.
DECLARE @RequesterId int=(SELECT TOP (1) requester_id FROM tickets ORDER BY id);
SELECT @RequesterId AS DemoRequesterId;
SELECT t.id AS TicketNumber,t.title,c.name AS Category,p.name AS Priority,s.name AS Status
FROM tickets t
JOIN categories c ON c.id=t.category_id
JOIN priorities p ON p.id=t.priority_id
JOIN statuses s ON s.id=t.status_id
WHERE t.requester_id=@RequesterId
ORDER BY t.updated_at DESC,t.id DESC;
GO

-- 4. Technician perspective: queue with readable labels and assignments.
SELECT t.id AS TicketNumber,t.title,r.name AS Requester,a.name AS AssignedTo,
       p.name AS Priority,s.name AS Status
FROM tickets t
JOIN users r ON r.id=t.requester_id
LEFT JOIN users a ON a.id=t.assigned_to
JOIN priorities p ON p.id=t.priority_id
JOIN statuses s ON s.id=t.status_id
ORDER BY s.sort_order,p.sort_order DESC,t.created_at,t.id;
GO

-- 5. Administrator perspective: accounts and roles, without password hashes.
SELECT u.id,u.name,r.name AS ApplicationRole,u.active,u.created_at
FROM users u JOIN roles r ON r.id=u.role_id
ORDER BY r.name,u.name;
GO

-- 6. The Views folder is different from a user perspective.
-- A SQL VIEW is a stored SELECT definition. This prototype currently uses
-- queries in PHP instead. Empty results below mean no user-created SQL views.
SELECT SCHEMA_NAME(schema_id) AS SchemaName,name AS SqlViewName
FROM sys.views WHERE is_ms_shipped=0 ORDER BY name;
GO

-- Presentation note: indexes may not be chosen for tiny tables; SQL Server
-- can choose a scan. No performance improvement is claimed without measurement.
-- dbo.sysdiagrams, if present, supports SSMS diagrams, not ticket workflows.
