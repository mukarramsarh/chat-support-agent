<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ChunkRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Config;
use SupportAI\Support\Logger;
use Throwable;

/**
 * The real RAG retriever (replaces NullRetriever). For each question:
 *
 *   embed(query) → vector search (top_k) → min-score gate → take final_k
 *   → fetch chunk text → build a numbered, cited KNOWLEDGE block.
 *
 * "Retrieve, don't stuff": only the few best chunks are injected, which is the
 * core of smart-token usage. The min-score gate is the free (no-LLM) guard that
 * stops us pretending to have knowledge we don't.
 *
 * Works across all three vector tiers unchanged — it depends only on the
 * VectorStore interface. Query embeddings use the SAME locked model as ingest,
 * so vectors are comparable.
 */
final class RagRetriever implements ContextRetriever
{
    public function __construct(
        private ProviderFactory $providers,
        private VectorStoreFactory $vectors,
        private ChunkRepository $chunks,
        private UsageRepository $usage,
        private Config $config,
        private Logger $logger,
    ) {
    }

    public function retrieve(int $agentId, ?string $visitorId, string $query): RetrievedContext
    {
        $query = trim($query);
        if ($query === '') {
            return RetrievedContext::empty();
        }

        $topK = $this->config->int('budget.top_k', 20);
        $finalK = $this->config->int('budget.final_k', 5);
        $minScore = $this->config->float('budget.min_score', 0.20);

        try {
            // 1) Embed the query (cheap) and record the spend.
            $embedder = $this->providers->embeddings();
            $embedded = $embedder->embed([$query]);
            $vector = $embedded['vectors'][0] ?? [];
            $this->usage->record($embedder->name(), $embedder->model(), 'embed', $embedded['usage'], $agentId);
            if ($vector === []) {
                return RetrievedContext::empty();
            }

            // 2) Vector search scoped to this agent.
            $matches = $this->vectors->make()->query('chunks', $vector, $topK, ['agent_id' => $agentId]);
            if ($matches === []) {
                return RetrievedContext::empty();
            }

            // 3) Free gate: nothing relevant enough → let the agent say it doesn't know.
            $topScore = $matches[0]->score;
            if ($topScore < $minScore) {
                $this->logger->info('RAG: below min-score gate', ['top' => $topScore, 'min' => $minScore]);
                return new RetrievedContext('', [], $topScore, false);
            }

            // 4) Take the best final_k and load their text.
            $bestIds = array_map(static fn ($m) => $m->id, array_slice($matches, 0, $finalK));
            $rows = $this->chunks->findByIds($bestIds);
            if ($rows === []) {
                return RetrievedContext::empty();
            }

            // 5) Build a numbered KNOWLEDGE block + citations.
            $block = '';
            $citations = [];
            $n = 0;
            foreach ($rows as $row) {
                $n++;
                $block .= "[{$n}] " . ($row['title'] !== '' ? "({$row['title']}) " : '') . trim($row['content']) . "\n\n";
                $citations[] = [
                    'chunk_id'    => $row['id'],
                    'document_id' => $row['document_id'],
                    'title'       => $row['title'],
                    'uri'         => $row['uri'],
                ];
            }

            return new RetrievedContext(trim($block), $citations, $topScore, true);
        } catch (Throwable $e) {
            // RAG must never break chat — degrade to persona-only answering.
            $this->logger->error('RAG retrieval failed', ['error' => $e->getMessage()]);
            return RetrievedContext::empty();
        }
    }
}
