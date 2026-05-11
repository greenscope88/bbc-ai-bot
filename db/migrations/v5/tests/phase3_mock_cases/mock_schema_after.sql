-- PHASE3_MOCK_SCHEMA_INPUT do_not_execute
-- Mock DDL text for Phase 3 schema-diff workflow drills only.
-- Do not run against any SQL Server. Not derived from production.

CREATE TABLE mock_p3_widgets (
    mock_id INT NOT NULL PRIMARY KEY,
    mock_label NVARCHAR(64) NULL,
    mock_optional_note NVARCHAR(50) NULL
);
