-- ═══════════════════════════════════════════════════════════════════════════
--  Migration 005 · startup-form leads + compliance audit log
--  Run once on existing installs. Fresh installs get these via schema.sql.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS leads (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id        BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    visitor_id      VARCHAR(64)   NOT NULL,
    data_encrypted  TEXT          NOT NULL,
    consent         TINYINT(1)    NOT NULL DEFAULT 0,
    consent_text    VARCHAR(1000) NULL,
    consented_at    TIMESTAMP     NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lead_agent_visitor (agent_id, visitor_id),
    CONSTRAINT fk_lead_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    BIGINT UNSIGNED NULL,
    action      VARCHAR(60)   NOT NULL,
    subject     VARCHAR(190)  NULL,
    detail      JSON          NULL,
    ip          VARCHAR(45)   NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
