-- ═══════════════════════════════════════════════════════════════════════════
--  Migration 007 · conversation memory maintenance marker
--  Tracks how far a conversation has been summarised / fact-extracted so the
--  cron job only processes new turns. Run once on existing installs.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE conversations
    ADD COLUMN maintained_upto INT UNSIGNED NOT NULL DEFAULT 0 AFTER message_count;
