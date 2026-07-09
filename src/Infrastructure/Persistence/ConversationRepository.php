<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

final class ConversationRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->first('SELECT * FROM conversations WHERE public_id = :id', ['id' => $publicId]);
    }

    public function create(int $agentId, string $visitorId, ?string $pageUrl): array
    {
        $publicId = str_uuid();
        $this->db->run(
            'INSERT INTO conversations (public_id, agent_id, visitor_id, page_url)
             VALUES (:pid, :aid, :vid, :url)',
            ['pid' => $publicId, 'aid' => $agentId, 'vid' => $visitorId, 'url' => $pageUrl]
        );
        return $this->findByPublicId($publicId);
    }

    /** Get-or-create by public id, so the widget can resume a session. */
    public function resolve(int $agentId, ?string $publicId, string $visitorId, ?string $pageUrl): array
    {
        if ($publicId !== null && $publicId !== '') {
            $existing = $this->findByPublicId($publicId);
            if ($existing !== null) {
                return $existing;
            }
        }
        return $this->create($agentId, $visitorId, $pageUrl);
    }

    public function touch(int $id, float $addedCost): void
    {
        $this->db->run(
            'UPDATE conversations
                SET message_count = message_count + 1,
                    total_cost_usd = total_cost_usd + :cost
              WHERE id = :id',
            ['cost' => $addedCost, 'id' => $id]
        );
    }

    public function setSummary(int $id, string $summary): void
    {
        $this->db->run('UPDATE conversations SET summary = :s WHERE id = :id', ['s' => $summary, 'id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->run('UPDATE conversations SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
    }
}
