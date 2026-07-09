<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;
use SupportAI\Support\Crypto;

/**
 * Startup-form leads. PII is encrypted at rest (Crypto) and only decrypted when
 * an authorised admin views/exports it — a PDPL data-minimisation measure.
 */
final class LeadRepository
{
    public function __construct(
        private Database $db,
        private Crypto $crypto,
    ) {
    }

    /** @param array<string,string> $fields */
    public function create(int $agentId, ?int $conversationId, string $visitorId, array $fields, bool $consent, ?string $consentText): int
    {
        $this->db->run(
            'INSERT INTO leads (agent_id, conversation_id, visitor_id, data_encrypted, consent, consent_text, consented_at)
             VALUES (:a, :c, :v, :d, :consent, :ctext, :cat)',
            [
                'a'       => $agentId,
                'c'       => $conversationId,
                'v'       => $visitorId,
                'd'       => $this->crypto->encrypt(json_encode($fields, JSON_UNESCAPED_UNICODE)),
                'consent' => $consent ? 1 : 0,
                'ctext'   => $consentText,
                'cat'     => $consent ? date('Y-m-d H:i:s') : null,
            ]
        );
        return (int) $this->db->lastId();
    }

    /** Decrypt a lead's fields for authorised viewing/export. @return array<string,string> */
    public function decryptFields(array $row): array
    {
        try {
            $json = $this->crypto->decrypt((string) $row['data_encrypted']);
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function forVisitor(string $visitorId): array
    {
        return $this->db->all('SELECT * FROM leads WHERE visitor_id = :v ORDER BY id DESC', ['v' => $visitorId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function latestForConversation(int $conversationId): array
    {
        return $this->db->all('SELECT * FROM leads WHERE conversation_id = :c ORDER BY id DESC', ['c' => $conversationId]);
    }

    public function hasForConversation(int $conversationId): bool
    {
        return $this->db->first('SELECT id FROM leads WHERE conversation_id = :c LIMIT 1', ['c' => $conversationId]) !== null;
    }
}
