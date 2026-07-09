-- ═══════════════════════════════════════════════════════════════════════════
--  Seed · minimal bootstrap data for a fresh install
--  The default admin password is set by the installer (bin/console install),
--  NOT here, so we never ship a known credential. This file only seeds the
--  single agent + baseline settings.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO agents (public_id, name, persona, welcome_message)
SELECT UUID(), 'Support Assistant',
       'You are a helpful, concise support assistant. Answer only from the provided knowledge context. If the answer is not in the context, say you do not know and offer to connect the user with a human. Never invent facts, prices, or policies.',
       'Hi! How can I help you today?'
WHERE NOT EXISTS (SELECT 1 FROM agents);

INSERT INTO settings (`key`, `value`) VALUES
    ('embedding_locked_model', NULL),
    ('embedding_locked_dims', NULL),
    ('kb_version', '1'),
    ('installed_at', NULL)
ON DUPLICATE KEY UPDATE `key` = `key`;
