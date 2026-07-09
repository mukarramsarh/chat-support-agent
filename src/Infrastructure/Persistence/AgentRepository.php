<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use RuntimeException;
use SupportAI\Infrastructure\Database\Database;

/**
 * Reads the agent configuration. Single-agent installs use find() to grab the
 * one row; the public_id lookup exists so the widget can resolve its agent.
 */
final class AgentRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(): ?array
    {
        return $this->hydrate($this->db->first('SELECT * FROM agents ORDER BY id ASC LIMIT 1'));
    }

    /** @return array<string,mixed> */
    public function findOrFail(): array
    {
        $agent = $this->find();
        if ($agent === null) {
            throw new RuntimeException('No agent configured. Run the installer / seed the database.');
        }
        return $agent;
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->hydrate($this->db->first(
            'SELECT * FROM agents WHERE public_id = :id',
            ['id' => $publicId]
        ));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $columns = [
            'name', 'persona', 'welcome_message', 'fallback_message', 'chat_provider',
            'chat_model', 'utility_model', 'temperature', 'monthly_budget_usd',
            'max_answer_tokens', 'is_active',
        ];
        $jsonColumns = ['theme', 'retrieval_config'];

        $set = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $set[] = "{$key} = :{$key}";
                $params[$key] = $value;
            } elseif (in_array($key, $jsonColumns, true)) {
                $set[] = "{$key} = :{$key}";
                $params[$key] = is_string($value) ? $value : json_encode($value);
            }
        }
        if ($set === []) {
            return;
        }
        $this->db->run('UPDATE agents SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
    }

    /** Decode JSON columns into arrays for convenient access. */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['theme', 'retrieval_config'] as $col) {
            $row[$col] = $row[$col] ? json_decode((string) $row[$col], true) : [];
        }
        return $row;
    }
}
