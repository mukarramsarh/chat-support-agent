<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/** CRUD for the offline golden-Q&A eval harness (sets → cases → runs → results). */
final class EvalRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function sets(int $agentId): array
    {
        return $this->db->all(
            'SELECT s.*, (SELECT COUNT(*) FROM eval_cases c WHERE c.eval_set_id = s.id) AS case_count
               FROM eval_sets s WHERE s.agent_id = :a ORDER BY s.id DESC',
            ['a' => $agentId]
        );
    }

    public function findSet(int $id): ?array
    {
        return $this->db->first('SELECT * FROM eval_sets WHERE id = :id', ['id' => $id]);
    }

    public function createSet(int $agentId, string $name): int
    {
        $this->db->run('INSERT INTO eval_sets (agent_id, name) VALUES (:a, :n)', ['a' => $agentId, 'n' => $name]);
        return (int) $this->db->lastId();
    }

    public function deleteSet(int $id): void
    {
        $this->db->run('DELETE FROM eval_sets WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function cases(int $setId): array
    {
        return $this->db->all('SELECT * FROM eval_cases WHERE eval_set_id = :s ORDER BY id ASC', ['s' => $setId]);
    }

    /** @param string[] $mustInclude */
    public function addCase(int $setId, string $question, string $expected, array $mustInclude): int
    {
        $this->db->run(
            'INSERT INTO eval_cases (eval_set_id, question, expected_answer, must_include) VALUES (:s, :q, :e, :m)',
            ['s' => $setId, 'q' => $question, 'e' => $expected, 'm' => $mustInclude ? json_encode(array_values($mustInclude)) : null]
        );
        return (int) $this->db->lastId();
    }

    public function deleteCase(int $id): void
    {
        $this->db->run('DELETE FROM eval_cases WHERE id = :id', ['id' => $id]);
    }

    public function createRun(int $setId): int
    {
        $this->db->run('INSERT INTO eval_runs (eval_set_id, status) VALUES (:s, \'running\')', ['s' => $setId]);
        return (int) $this->db->lastId();
    }

    public function finishRun(int $runId, float $avg, float $hit, float $grounded, float $cost): void
    {
        $this->db->run(
            'UPDATE eval_runs SET status = \'done\', avg_score = :a, hit_rate = :h, grounded_rate = :g, total_cost_usd = :c WHERE id = :id',
            ['a' => $avg, 'h' => $hit, 'g' => $grounded, 'c' => $cost, 'id' => $runId]
        );
    }

    public function addResult(int $runId, int $caseId, string $answer, float $score, bool $grounded, bool $retrieved, string $notes): void
    {
        $this->db->run(
            'INSERT INTO eval_results (eval_run_id, eval_case_id, answer, score, grounded, retrieved_ok, notes)
             VALUES (:r, :c, :ans, :s, :g, :ret, :n)',
            ['r' => $runId, 'c' => $caseId, 'ans' => $answer, 's' => $score, 'g' => $grounded ? 1 : 0, 'ret' => $retrieved ? 1 : 0, 'n' => mb_substr($notes, 0, 500)]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function runs(int $setId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->all('SELECT * FROM eval_runs WHERE eval_set_id = :s ORDER BY id DESC LIMIT ' . $limit, ['s' => $setId]);
    }

    public function latestRun(int $setId): ?array
    {
        return $this->db->first('SELECT * FROM eval_runs WHERE eval_set_id = :s ORDER BY id DESC LIMIT 1', ['s' => $setId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function results(int $runId): array
    {
        return $this->db->all(
            'SELECT r.*, c.question FROM eval_results r JOIN eval_cases c ON c.id = r.eval_case_id
              WHERE r.eval_run_id = :r ORDER BY r.id ASC',
            ['r' => $runId]
        );
    }
}
