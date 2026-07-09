-- ═══════════════════════════════════════════════════════════════════════════
--  OPTIONAL · MySQL 9.0+ native vector upgrade
--
--  Apply ONLY on MySQL 9.0+ (or MariaDB 11.7+ with the adjusted syntax noted
--  below). The VectorStore layer probes for this at runtime; if the columns
--  exist it uses native DISTANCE()/ANN search, otherwise it falls back to the
--  LONGBLOB + PHP-cosine path. Safe to skip entirely.
--
--  Dimension must match EMBEDDING_DIMENSIONS (1536 for text-embedding-3-small).
--  Changing embedding model later = re-embed + recreate these columns.
-- ═══════════════════════════════════════════════════════════════════════════

-- chunks: add a native VECTOR column alongside the portable BLOB.
ALTER TABLE chunks
    ADD COLUMN embedding_vec VECTOR(1536) NULL AFTER embedding;

-- memories: same treatment.
ALTER TABLE memories
    ADD COLUMN embedding_vec VECTOR(1536) NULL AFTER embedding;

-- ANN index for fast cosine search (MySQL 9.x HeatWave / InnoDB vector index).
-- On MariaDB use:  ALTER TABLE chunks ADD VECTOR INDEX (embedding_vec) DISTANCE=cosine;
CREATE VECTOR INDEX idx_chunks_vec  ON chunks  (embedding_vec) USING HNSW DISTANCE COSINE;
CREATE VECTOR INDEX idx_mem_vec     ON memories(embedding_vec) USING HNSW DISTANCE COSINE;
