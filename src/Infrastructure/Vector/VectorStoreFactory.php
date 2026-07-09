<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Vector;

use SupportAI\Domain\Vector\VectorStore;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Support\Config;
use SupportAI\Support\Http\HttpClient;
use SupportAI\Support\Logger;

/**
 * Selects the vector backend ONCE per request and memoises it. In `auto` mode
 * it walks the ladder:
 *
 *     MySQL 9 native VECTOR  →  Pinecone (key configured)  →  PHP cosine
 *
 * The MySQL rung uses a real feature probe (create+insert a temp VECTOR), not
 * version-string trust, so a patched/limited host degrades gracefully. An
 * explicit VECTOR_DRIVER overrides the probe.
 */
final class VectorStoreFactory
{
    private ?VectorStore $store = null;

    public function __construct(
        private Config $config,
        private Database $db,
        private HttpClient $http,
        private Logger $logger,
    ) {
    }

    public function make(): VectorStore
    {
        if ($this->store instanceof VectorStore) {
            return $this->store;
        }

        $driver = $this->config->string('vector.driver', 'auto');
        $store = match ($driver) {
            'mysql'    => new MySqlVectorStore($this->db),
            'pinecone' => $this->pinecone(),
            'php'      => new PhpCosineVectorStore($this->db),
            default    => $this->autoSelect(),
        };

        $this->logger->info('Vector store selected', ['driver' => $store->driver(), 'mode' => $driver]);
        return $this->store = $store;
    }

    private function autoSelect(): VectorStore
    {
        // Tier 1 requires BOTH native VECTOR support AND the migration-002
        // columns; otherwise we'd query a column that doesn't exist.
        if ($this->db->supportsNativeVector()) {
            if ($this->db->hasVectorColumns()) {
                return new MySqlVectorStore($this->db);
            }
            $this->logger->info('Native VECTOR available but columns absent — apply migrations/002_mysql9_vector.sql to enable tier 1.');
        }
        if ($this->pineconeConfigured()) {
            return $this->pinecone();
        }
        return new PhpCosineVectorStore($this->db);
    }

    private function pineconeConfigured(): bool
    {
        return $this->config->string('vector.pinecone_key') !== ''
            && $this->config->string('vector.pinecone_host') !== '';
    }

    private function pinecone(): PineconeVectorStore
    {
        return new PineconeVectorStore(
            $this->http,
            $this->config->string('vector.pinecone_key'),
            rtrim($this->config->string('vector.pinecone_host'), '/'),
        );
    }
}
