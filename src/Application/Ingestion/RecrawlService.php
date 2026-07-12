<?php

declare(strict_types=1);

namespace SupportAI\Application\Ingestion;

use SupportAI\Infrastructure\Persistence\DocumentRepository;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Drives scheduled URL refreshes from cron. Each due source is re-fetched and
 * re-indexed only if its content changed. Sources are processed independently
 * so one failing URL never blocks the others — exactly what you want when
 * watching e.g. a government-rules page for updates.
 */
final class RecrawlService
{
    public function __construct(
        private DocumentRepository $documents,
        private IngestionService $ingestion,
        private Logger $logger,
    ) {
    }

    /**
     * Process up to $limit due URL sources.
     * @return array{checked:int,updated:int,unchanged:int,failed:int}
     */
    public function refreshDue(int $limit = 5): array
    {
        $due = $this->documents->findDueForRefresh($limit);
        $stats = ['checked' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($due as $doc) {
            $stats['checked']++;
            try {
                $result = $this->ingestion->reingest((int) $doc['id']);
                $stats[$result['status'] === 'updated' ? 'updated' : 'unchanged']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->logger->warning('Recrawl item failed', ['id' => $doc['id'], 'error' => $e->getMessage()]);
            }
        }
        return $stats;
    }

    /** Refresh a single source immediately (admin "Refresh now"). */
    public function refreshOne(int $documentId): array
    {
        return $this->ingestion->reingest($documentId);
    }
}
