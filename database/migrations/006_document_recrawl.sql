-- ═══════════════════════════════════════════════════════════════════════════
--  Migration 006 · scheduled URL recrawl
--  Adds refresh scheduling to documents. cron refetches due URL sources and
--  re-indexes only when the content hash changed. Run once on existing installs.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE documents
    ADD COLUMN refresh_interval_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER metadata,
    ADD COLUMN last_fetched_at TIMESTAMP NULL AFTER refresh_interval_minutes,
    ADD COLUMN next_refresh_at TIMESTAMP NULL AFTER last_fetched_at,
    ADD KEY idx_doc_refresh (next_refresh_at);
