<?php

declare(strict_types=1);

namespace SupportAI\Application\Ingestion;

use SupportAI\Infrastructure\Persistence\DocumentRepository;
use SupportAI\Infrastructure\Persistence\JobQueueRepository;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Drains ingestion jobs from the queue (called by cron). Each job is one source
 * so failures are isolated and retried with backoff. This is the background path
 * for large files / big URL batches; the synchronous path stays available for
 * small sources (see DocumentController / INGEST_ASYNC).
 */
final class IngestionWorker
{
    public function __construct(
        private JobQueueRepository $jobs,
        private IngestionService $ingestion,
        private DocumentRepository $documents,
        private Logger $logger,
    ) {
    }

    /** @return array{done:int,failed:int} */
    public function process(int $limit = 10): array
    {
        $done = 0;
        $failed = 0;
        foreach ($this->jobs->claimBatch($limit) as $job) {
            try {
                $this->dispatch((string) $job['type'], json_decode((string) $job['payload'], true) ?: []);
                $this->jobs->markDone((int) $job['id']);
                $done++;
            } catch (Throwable $e) {
                $this->jobs->markFailed((int) $job['id'], $e->getMessage());
                $this->logger->warning('Ingestion job failed', ['id' => $job['id'], 'error' => $e->getMessage()]);
                $failed++;
            }
        }
        return ['done' => $done, 'failed' => $failed];
    }

    private function dispatch(string $type, array $p): void
    {
        $agentId = (int) ($p['agent_id'] ?? 0);
        switch ($type) {
            case 'ingest.text':
                $this->ingestion->ingest($agentId, 'text', ['title' => $p['title'] ?? '', 'content' => $p['content'] ?? '']);
                break;

            case 'ingest.url':
                $r = $this->ingestion->ingest($agentId, 'url', ['url' => $p['url'] ?? '']);
                if (!empty($p['refresh_minutes'])) {
                    $this->documents->setRefreshSchedule($r['document_id'], (int) $p['refresh_minutes']);
                }
                break;

            case 'ingest.file':
                $path = (string) ($p['path'] ?? '');
                try {
                    $this->ingestion->ingest($agentId, (string) $p['source_type'], [
                        'path' => $path, 'title' => $p['title'] ?? '', 'filename' => $p['filename'] ?? '',
                    ]);
                } finally {
                    if ($path !== '' && is_file($path)) {
                        @unlink($path);
                    }
                }
                break;

            default:
                throw new \RuntimeException("Unknown job type: {$type}");
        }
    }
}
