<?php

declare(strict_types=1);

namespace SupportAI\Application\Ingestion;

use RuntimeException;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ChunkRepository;
use SupportAI\Infrastructure\Persistence\DocumentRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Turns a raw source into retrievable, embedded chunks.
 *
 * Runs synchronously (fine for text/URLs and reasonably-sized files) so the
 * admin gets immediate feedback. The job_queue/cron path is the future
 * optimisation for very large batches; the seam (this service) stays the same.
 *
 * Enforces the embedding-model LOCK: the first ingest fixes the model + vector
 * dimension, because vectors from different models aren't comparable.
 */
final class IngestionService
{
    /** Embed in batches to stay within provider request limits. */
    private const EMBED_BATCH = 64;

    public function __construct(
        private TextExtractor $extractor,
        private Chunker $chunker,
        private ProviderFactory $providers,
        private VectorStoreFactory $vectors,
        private DocumentRepository $documents,
        private ChunkRepository $chunks,
        private SettingsRepository $settings,
        private UsageRepository $usage,
        private Logger $logger,
    ) {
    }

    /**
     * @param array{content?:string,title?:string,url?:string,path?:string,filename?:string} $source
     * @return array{document_id:int,chunks:int,title:string}
     */
    public function ingest(int $agentId, string $type, array $source): array
    {
        // 1) Extract (before creating a row, so extraction errors don't litter DB).
        $extracted = $this->extractor->extract($type, $source);
        $text = $extracted['text'];
        $hash = hash('sha256', $text);

        if ($this->documents->existsByHash($agentId, $hash)) {
            throw new RuntimeException('This content is already in the knowledge base.');
        }

        // 2) Verify the embedding lock is compatible (does NOT set it yet — we
        //    only commit the lock after a fully successful ingest below).
        $embedder = $this->providers->embeddings();
        $this->checkEmbeddingLock($embedder->model(), $embedder->dimensions());

        // 3) Create the document row (processing).
        $sourceUri = $source['url'] ?? ($source['filename'] ?? null);
        $documentId = $this->documents->create(
            $agentId, $type, $extracted['title'], $sourceUri, $hash, mb_strlen($text)
        );

        try {
            // 4) Chunk.
            $chunks = $this->chunker->chunk($text);
            if ($chunks === []) {
                throw new RuntimeException('No text content found to index.');
            }

            $store = $this->vectors->make();
            $ordinal = 0;
            $totalChunks = 0;

            // 5) Embed + store in batches.
            foreach (array_chunk($chunks, self::EMBED_BATCH) as $batch) {
                $texts = array_map(static fn ($c) => $c['content'], $batch);
                $result = $embedder->embed($texts);
                $vectors = $result['vectors'];

                $this->usage->record($embedder->name(), $embedder->model(), 'embed', $result['usage'], $agentId);

                $items = [];
                foreach ($batch as $i => $chunk) {
                    $meta = [
                        'title'       => $extracted['title'],
                        'document_id' => $documentId,
                        'ordinal'     => $ordinal,
                    ] + $extracted['meta'];

                    $chunkId = $this->chunks->insert(
                        $documentId, $agentId, $ordinal, $chunk['content'], $chunk['tokens'],
                        $embedder->model(), $embedder->dimensions(), $meta
                    );
                    $items[] = ['id' => $chunkId, 'vector' => $vectors[$i] ?? [], 'metadata' => ['agent_id' => $agentId] + $meta];
                    $ordinal++;
                    $totalChunks++;
                }
                $store->upsert('chunks', $items);
            }

            // 6) Finalise — commit the embedding lock only now that it worked.
            $this->lockEmbeddingIfUnset($embedder->model(), $embedder->dimensions());
            $this->documents->markReady($documentId, $totalChunks, $extracted['meta']);
            $this->settings->bumpKbVersion();
            $this->logger->info('Ingested document', ['id' => $documentId, 'chunks' => $totalChunks, 'store' => $store->driver()]);

            return ['document_id' => $documentId, 'chunks' => $totalChunks, 'title' => $extracted['title']];
        } catch (Throwable $e) {
            $this->documents->markFailed($documentId, $e->getMessage());
            $this->logger->error('Ingestion failed', ['id' => $documentId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function checkEmbeddingLock(string $model, int $dims): void
    {
        $lockedModel = $this->settings->get('embedding_locked_model');
        $lockedDims = $this->settings->get('embedding_locked_dims');

        if ($lockedModel === null || $lockedModel === '') {
            return; // not locked yet — anything goes
        }
        if ($lockedModel !== $model || (int) $lockedDims !== $dims) {
            throw new RuntimeException(sprintf(
                'Embedding model is locked to "%s" (%dd). Changing it needs a full re-index; clear the knowledge base first.',
                $lockedModel, (int) $lockedDims
            ));
        }
    }

    private function lockEmbeddingIfUnset(string $model, int $dims): void
    {
        if (($this->settings->get('embedding_locked_model') ?? '') === '') {
            $this->settings->set('embedding_locked_model', $model);
            $this->settings->set('embedding_locked_dims', (string) $dims);
        }
    }
}
