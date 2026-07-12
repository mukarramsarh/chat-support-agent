<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/**
 * Minimal DB-backed job queue (no Redis/daemon — shared-hosting friendly). Used
 * to run heavy ingestion in the background off the cron tick so large files /
 * many URLs never time out a web request. Claiming is a guarded UPDATE so a
 * single cron worker is safe; done jobs are deleted to keep the table small.
 */
final class JobQueueRepository
{
    public function __construct(private Database $db)
    {
    }

    public function enqueue(string $type, array $payload, int $priority = 5): int
    {
        $this->db->run(
            'INSERT INTO job_queue (type, payload, priority, status) VALUES (:t, :p, :pr, \'queued\')',
            ['t' => $type, 'p' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'pr' => $priority]
        );
        return (int) $this->db->lastId();
    }

    /**
     * Atomically claim up to $limit runnable jobs (marks them running).
     * @return array<int,array<string,mixed>>
     */
    public function claimBatch(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db->all(
            "SELECT id FROM job_queue
              WHERE status = 'queued' AND available_at <= NOW()
           ORDER BY priority ASC, id ASC
              LIMIT " . $limit
        );
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        // Guarded transition so a second worker can't grab the same rows.
        $this->db->run("UPDATE job_queue SET status = 'running', reserved_at = NOW() WHERE id IN ({$in}) AND status = 'queued'");

        return $this->db->all("SELECT * FROM job_queue WHERE id IN ({$in}) AND status = 'running'");
    }

    public function markDone(int $id): void
    {
        $this->db->run('DELETE FROM job_queue WHERE id = :id', ['id' => $id]);
    }

    /** Retry with backoff up to max_attempts, then park as failed. */
    public function markFailed(int $id, string $error): void
    {
        $this->db->run(
            "UPDATE job_queue
                SET attempts = attempts + 1,
                    last_error = :e,
                    status = IF(attempts + 1 >= max_attempts, 'failed', 'queued'),
                    available_at = DATE_ADD(NOW(), INTERVAL LEAST(POW(2, attempts) , 30) MINUTE)
              WHERE id = :id",
            ['e' => mb_substr($error, 0, 500), 'id' => $id]
        );
    }

    public function pendingCount(): int
    {
        return (int) ($this->db->first("SELECT COUNT(*) c FROM job_queue WHERE status IN ('queued','running')")['c'] ?? 0);
    }
}
