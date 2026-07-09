-- ═══════════════════════════════════════════════════════════════════════════
--  Migration 003 · FULLTEXT index on messages.content
--
--  Powers the "relevant memory" recall in MemoryService (long-term recall of a
--  visitor's earlier messages that match the current question). Pure MySQL, no
--  token cost. Safe to skip — MemoryService degrades to recent-window only if
--  the index is absent.
--
--  Existing installs: run this once. Fresh installs already include it via
--  schema.sql.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE messages ADD FULLTEXT KEY ft_msg_content (content);
