-- ═══════════════════════════════════════════════════════════════════════════
--  support-ai · core schema (portable baseline)
--
--  Design notes:
--   • Embeddings are stored as LONGBLOB (packed little-endian float32) so this
--     runs on ANY MySQL 5.7+/8.x/MariaDB host. On MySQL 9 you may additionally
--     apply migrations/002_mysql9_vector.sql to add a native VECTOR column and
--     ANN index; the VectorStore layer detects and uses it automatically.
--   • Money is DECIMAL(12,6) — six places because per-call LLM costs are tiny.
--   • Everything is scoped by agent_id even though v1 ships single-agent, so the
--     door to multi-tenant stays open without a migration.
--   • utf8mb4 throughout for full Unicode (emoji, non-Latin scripts).
-- ═══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ─────────────────────────────────────────────────────────────────────────
--  settings · editable app-level key/value config (managed from admin)
--  Holds the embedding-model lock, active provider/model, feature flags, etc.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    `key`        VARCHAR(100)  NOT NULL,
    `value`      TEXT          NULL,
    `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  admin_users · panel authentication
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190)  NOT NULL,
    name          VARCHAR(120)  NOT NULL DEFAULT '',
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('owner','admin','viewer') NOT NULL DEFAULT 'admin',
    last_login_at TIMESTAMP     NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  agents · the support persona + its runtime config
--  Single-agent installs have exactly one row (id = 1).
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agents (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(36)      NOT NULL,               -- used by the widget shortcode
    name              VARCHAR(120)  NOT NULL DEFAULT 'Support Assistant',
    persona           TEXT          NULL,                   -- system-prompt persona / tone
    welcome_message   VARCHAR(500)  NOT NULL DEFAULT 'Hi! How can I help you today?',
    fallback_message  VARCHAR(500)  NOT NULL DEFAULT 'I''m not fully sure — want me to connect you to a person?',
    -- Model routing (nullable = fall back to .env defaults)
    chat_provider     ENUM('gemini','openai','anthropic') NOT NULL DEFAULT 'gemini',
    chat_model        VARCHAR(120)  NULL,
    utility_model     VARCHAR(120)  NULL,
    temperature       DECIMAL(3,2)  NOT NULL DEFAULT 0.30,
    -- Budget guardrails (override global)
    monthly_budget_usd DECIMAL(10,2) NOT NULL DEFAULT 2.00,
    max_answer_tokens  SMALLINT UNSIGNED NOT NULL DEFAULT 800,
    -- JSON blobs: theme{} (colors, position, avatar), retrieval{}, features{}
    theme             JSON          NULL,
    retrieval_config  JSON          NULL,
    is_active         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_public (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  agent_domains · CORS / embed allowlist per agent
--  Stops third parties from embedding your widget and spending your budget.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agent_domains (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id   BIGINT UNSIGNED NOT NULL,
    domain     VARCHAR(190)    NOT NULL,   -- e.g. example.com (matches sub-paths)
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_domain (agent_id, domain),
    CONSTRAINT fk_domain_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  documents · a knowledge source (pdf | docx | url | text)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id      BIGINT UNSIGNED NOT NULL,
    source_type   ENUM('pdf','docx','url','text') NOT NULL,
    title         VARCHAR(300)  NOT NULL DEFAULT '',
    source_uri    VARCHAR(1000) NULL,               -- URL or stored file path
    content_hash  CHAR(64)      NULL,               -- sha256 of raw content (dedupe / change-detect)
    byte_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
    chunk_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    error_message VARCHAR(500)  NULL,
    metadata      JSON          NULL,               -- page count, author, language, ...
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_agent_status (agent_id, status),
    KEY idx_doc_hash (content_hash),
    CONSTRAINT fk_document_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  chunks · retrievable units + their embeddings
--  embedding: packed float32 LONGBLOB. FULLTEXT on content powers the hybrid
--  keyword prefilter that runs before cosine on the PHP/MySQL-BLOB path.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chunks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id  BIGINT UNSIGNED NOT NULL,
    agent_id     BIGINT UNSIGNED NOT NULL,
    ordinal      INT UNSIGNED    NOT NULL DEFAULT 0,   -- position within document
    content      MEDIUMTEXT      NOT NULL,
    token_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    embedding    LONGBLOB        NULL,                 -- packed float32 vector
    embed_model  VARCHAR(120)    NULL,                 -- model used (dimension lock guard)
    embed_dims   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metadata     JSON            NULL,                 -- {source_title, page, heading, uri}
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chunk_agent (agent_id),
    KEY idx_chunk_doc (document_id),
    FULLTEXT KEY ft_chunk_content (content),
    CONSTRAINT fk_chunk_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chunk_agent    FOREIGN KEY (agent_id)    REFERENCES agents(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  conversations · one chat session with a visitor
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(36)      NOT NULL,             -- exposed to the widget
    agent_id      BIGINT UNSIGNED NOT NULL,
    visitor_id    VARCHAR(64)   NOT NULL,             -- stable anon id from widget (localStorage)
    channel       VARCHAR(40)   NOT NULL DEFAULT 'widget',
    summary       MEDIUMTEXT    NULL,                 -- rolling long-term summary of older turns
    status        ENUM('open','closed','escalated') NOT NULL DEFAULT 'open',
    page_url      VARCHAR(1000) NULL,                 -- where the chat started
    metadata      JSON          NULL,
    message_count INT UNSIGNED  NOT NULL DEFAULT 0,
    total_cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conv_public (public_id),
    KEY idx_conv_agent_visitor (agent_id, visitor_id),
    KEY idx_conv_status (status),
    CONSTRAINT fk_conv_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  messages · individual turns, with per-message cost + eval telemetry
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role            ENUM('user','assistant','system') NOT NULL,
    content         MEDIUMTEXT    NOT NULL,
    citations       JSON          NULL,               -- [{chunk_id, document_id, title, uri}]
    model           VARCHAR(120)  NULL,
    tokens_in       INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_out      INT UNSIGNED  NOT NULL DEFAULT 0,
    cost_usd        DECIMAL(12,6) NOT NULL DEFAULT 0,
    -- Eval telemetry from the pre-answer loop:
    -- {grounded:bool, confidence:0-1, answered:bool, retries:int, verdict:'sent'|'declined'|'escalated'}
    eval            JSON          NULL,
    latency_ms      INT UNSIGNED  NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_msg_conv (conversation_id, id),
    FULLTEXT KEY ft_msg_content (content),   -- powers relevant-memory recall
    CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  memories · long-term durable facts + conversation summaries
--  Retrieved like knowledge (embedded), scoped to a visitor when personal.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS memories (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id          BIGINT UNSIGNED NOT NULL,
    visitor_id        VARCHAR(64)   NULL,             -- NULL = global fact for the agent
    conversation_id   BIGINT UNSIGNED NULL,
    kind              ENUM('fact','summary','preference') NOT NULL DEFAULT 'fact',
    content           TEXT          NOT NULL,
    embedding         LONGBLOB      NULL,
    embed_dims        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    importance        TINYINT UNSIGNED NOT NULL DEFAULT 3, -- 1..5, drives retention/ranking
    source_message_id BIGINT UNSIGNED NULL,
    expires_at        TIMESTAMP     NULL,             -- NULL = never
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mem_agent_visitor (agent_id, visitor_id),
    KEY idx_mem_kind (kind),
    CONSTRAINT fk_mem_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  job_queue · cron-drained work (parse + embed) — no worker daemon needed
--  cron.php pulls a small batch each tick, respecting available_at + attempts.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS job_queue (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type          VARCHAR(60)   NOT NULL,             -- ingest.parse | ingest.embed | memory.extract | summarize
    payload       JSON          NOT NULL,
    status        ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    priority      TINYINT       NOT NULL DEFAULT 5,   -- lower = sooner
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at   TIMESTAMP     NULL,
    last_error    VARCHAR(500)  NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_job_pick (status, available_at, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  usage_log · every billable provider call → powers the cost dashboard
--  and the monthly budget guardrail (SUM over the current month).
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usage_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id      BIGINT UNSIGNED NULL,
    conversation_id BIGINT UNSIGNED NULL,
    provider      VARCHAR(40)   NOT NULL,
    model         VARCHAR(120)  NOT NULL,
    operation     VARCHAR(40)   NOT NULL,             -- chat | embed | rerank | summarize | eval
    tokens_in     INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_out    INT UNSIGNED  NOT NULL DEFAULT 0,
    cost_usd      DECIMAL(12,6) NOT NULL DEFAULT 0,
    cached        TINYINT(1)    NOT NULL DEFAULT 0,   -- prompt/answer cache hit
    usage_day     DATE          NOT NULL,             -- denormalised for fast grouping
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_usage_day (usage_day),
    KEY idx_usage_agent_day (agent_id, usage_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  answer_cache · exact + semantic FAQ cache to skip the LLM entirely
--  key_hash = sha256(normalised_query + kb_version). value stores answer+citations.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS answer_cache (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id    BIGINT UNSIGNED NOT NULL,
    key_hash    CHAR(64)      NOT NULL,
    query_text  VARCHAR(500)  NOT NULL,
    embedding   LONGBLOB      NULL,                   -- for semantic (near-duplicate) hits
    answer      MEDIUMTEXT    NOT NULL,
    citations   JSON          NULL,
    hit_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    kb_version  INT UNSIGNED  NOT NULL DEFAULT 1,     -- bumped on any ingest → invalidates stale entries
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit_at TIMESTAMP     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cache_key (agent_id, key_hash),
    KEY idx_cache_agent (agent_id, kb_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  rate_limits · fixed-window counter (per visitor/ip) — abuse + budget guard
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket      VARCHAR(160)  NOT NULL,               -- e.g. chat:visitor:<id>:<yyyymmddHHMM>
    hits        INT UNSIGNED  NOT NULL DEFAULT 0,
    expires_at  TIMESTAMP     NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rl_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
--  Offline evaluation harness (admin-triggered regression testing)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS eval_sets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id   BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(160)  NOT NULL,
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_evalset_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eval_cases (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eval_set_id     BIGINT UNSIGNED NOT NULL,
    question        TEXT          NOT NULL,
    expected_answer TEXT          NULL,               -- reference for the judge
    must_include    JSON          NULL,               -- keywords/phrases that must appear
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_evalcase_set FOREIGN KEY (eval_set_id) REFERENCES eval_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eval_runs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eval_set_id   BIGINT UNSIGNED NOT NULL,
    status        ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    avg_score     DECIMAL(4,3)  NULL,                 -- 0..1 aggregate
    hit_rate      DECIMAL(4,3)  NULL,                 -- retrieval hit rate
    grounded_rate DECIMAL(4,3)  NULL,
    total_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_evalrun_set FOREIGN KEY (eval_set_id) REFERENCES eval_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eval_results (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eval_run_id  BIGINT UNSIGNED NOT NULL,
    eval_case_id BIGINT UNSIGNED NOT NULL,
    answer       MEDIUMTEXT    NULL,
    score        DECIMAL(4,3)  NULL,                  -- judge score 0..1
    grounded     TINYINT(1)    NULL,
    retrieved_ok TINYINT(1)    NULL,
    notes        VARCHAR(500)  NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evalresult_run (eval_run_id),
    CONSTRAINT fk_evalresult_run  FOREIGN KEY (eval_run_id)  REFERENCES eval_runs(id)  ON DELETE CASCADE,
    CONSTRAINT fk_evalresult_case FOREIGN KEY (eval_case_id) REFERENCES eval_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
