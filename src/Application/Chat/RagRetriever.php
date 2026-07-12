<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

use SupportAI\Application\Compliance\PrivacyFilter;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ChunkRepository;
use SupportAI\Infrastructure\Persistence\MemoryRepository;
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
        private MemoryRepository $memories,
        private UsageRepository $usage,
        private PrivacyFilter $privacy,
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
            // 1) Embed the query (cheap) and record the spend. Redact PII first
            //    so personal data isn't sent to the (external) embedding provider.
            $embedder = $this->providers->embeddings();
            $embedded = $embedder->embed([$this->privacy->outbound($query)]);
            $vector = $embedded['vectors'][0] ?? [];
            $this->usage->record($embedder->name(), $embedder->model(), 'embed', $embedded['usage'], $agentId);
            if ($vector === []) {
                return RetrievedContext::empty();
            }

            // 2) Long-term memory: recall this visitor's durable facts using the
            //    SAME query vector (no extra embedding cost). Included even when no
            //    knowledge matches, so the agent still "remembers" the user.
            $factsBlock = $this->recallFacts($agentId, $visitorId, $vector);

            // 3) Vector (semantic) search scoped to this agent.
            $matches = $this->vectors->make()->query('chunks', $vector, $topK, ['agent_id' => $agentId]);
            if ($matches === []) {
                return new RetrievedContext($factsBlock, [], 0.0, false);
            }

            // 4) Free gate: nothing semantically relevant → decline (but keep facts).
            $topScore = $matches[0]->score;
            if ($topScore < $minScore) {
                $this->logger->info('RAG: below min-score gate', ['top' => $topScore, 'min' => $minScore]);
                return new RetrievedContext($factsBlock, [], $topScore, false);
            }

            // 5) Hybrid: blend vector scores with FULLTEXT keyword scores so exact
            //    terms/codes/names get boosted. Keyword hits only re-rank within
            //    the relevant set — they never override the semantic gate above.
            $bestIds = $this->hybridRank($matches, $agentId, $query, $finalK);

            // 6) Load the selected chunks' text (ordered by combined rank).
            $rows = $this->chunks->findByIds($bestIds);
            if ($rows === []) {
                return new RetrievedContext($factsBlock, [], $topScore, false);
            }

            // 7) Build a numbered KNOWLEDGE block + citations.
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

            $full = $factsBlock !== '' ? $factsBlock . "\n\n" . trim($block) : trim($block);
            return new RetrievedContext($full, $citations, $topScore, true);
        } catch (Throwable $e) {
            // RAG must never break chat — degrade to persona-only answering.
            $this->logger->error('RAG retrieval failed', ['error' => $e->getMessage()]);
            return RetrievedContext::empty();
        }
    }

    /** Build the "what we know about this user" block from durable memories. */
    private function recallFacts(int $agentId, ?string $visitorId, array $vector): string
    {
        if ($visitorId === null || $visitorId === '') {
            return '';
        }
        $facts = $this->memories->searchRelevant($agentId, $visitorId, $vector, 3, 0.2);
        if ($facts === []) {
            return '';
        }
        $block = "WHAT YOU KNOW ABOUT THIS USER (from earlier chats):\n";
        foreach ($facts as $f) {
            $block .= '- ' . $f['content'] . "\n";
        }
        return trim($block);
    }

    /**
     * Combine dense (vector) and lexical (FULLTEXT) rankings into a single top-K.
     * Vector scores are already ~[0,1]; keyword scores are normalised to their
     * own max. A chunk appearing in both is boosted (its scores sum).
     *
     * @param \SupportAI\Domain\Vector\VectorMatch[] $matches
     * @return int[] chunk ids, best-first
     */
    private function hybridRank(array $matches, int $agentId, string $query, int $finalK): array
    {
        $wVector = 0.7;
        $wKeyword = 0.3;

        $scores = [];
        foreach ($matches as $m) {
            $scores[$m->id] = $wVector * $m->score;
        }

        $keyword = $this->chunks->fulltextSearch($agentId, $query, count($matches) ?: 20);
        $maxKw = 0.0;
        foreach ($keyword as $k) {
            $maxKw = max($maxKw, $k['score']);
        }
        if ($maxKw > 0.0) {
            foreach ($keyword as $k) {
                $scores[$k['id']] = ($scores[$k['id']] ?? 0.0) + $wKeyword * ($k['score'] / $maxKw);
            }
        }

        arsort($scores);
        return array_slice(array_keys($scores), 0, $finalK);
    }
}
