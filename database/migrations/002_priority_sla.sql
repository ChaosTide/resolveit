-- Phase 4 analytics: resolution targets (SLA) per priority and a created_at index for date-range reports.
-- Safe to run more than once. Run while connected to WORKSTATION\JLB282.
USE TicketManagement;
GO
IF COL_LENGTH(N'priorities', N'sla_hours') IS NULL
BEGIN
    ALTER TABLE priorities ADD sla_hours SMALLINT NOT NULL CONSTRAINT df_priorities_sla DEFAULT 24
        CONSTRAINT ck_priorities_sla CHECK (sla_hours BETWEEN 1 AND 720);
END
GO
-- Default targets by level: Low 72h, Medium 24h, High 8h, Critical 4h (only on first run).
IF NOT EXISTS (SELECT 1 FROM sys.extended_properties WHERE name = N'cts_sla_seeded')
BEGIN
    UPDATE priorities SET sla_hours = CASE sort_order WHEN 1 THEN 72 WHEN 2 THEN 24 WHEN 3 THEN 8 WHEN 4 THEN 4 ELSE sla_hours END;
    EXEC sys.sp_addextendedproperty @name = N'cts_sla_seeded', @value = N'1';
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_ticket_created' AND object_id = OBJECT_ID(N'tickets'))
    CREATE INDEX idx_ticket_created ON tickets(created_at);
GO
