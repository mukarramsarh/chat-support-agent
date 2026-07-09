-- ═══════════════════════════════════════════════════════════════════════════
--  Migration 004 · richer conversation lifecycle statuses
--
--  Replaces open/closed/escalated with a fuller set used by the admin session
--  view and dashboard counts. Done in 3 steps so existing rows are mapped, not
--  truncated. Fresh installs already have the final enum via schema.sql.
-- ═══════════════════════════════════════════════════════════════════════════

-- 1) Widen to a superset (old + new) so existing values stay valid.
ALTER TABLE conversations MODIFY status
    ENUM('open','closed','escalated','incomplete','ai_answered','needs_attention','resolved','abandoned')
    NOT NULL DEFAULT 'incomplete';

-- 2) Map legacy values.
UPDATE conversations SET status = 'incomplete' WHERE status = 'open';
UPDATE conversations SET status = 'resolved'   WHERE status = 'closed';

-- 3) Narrow to the final set.
ALTER TABLE conversations MODIFY status
    ENUM('incomplete','ai_answered','needs_attention','escalated','resolved','abandoned')
    NOT NULL DEFAULT 'incomplete';
