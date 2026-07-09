<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/** Append-only compliance audit trail. */
final class AuditRepository
{
    public function __construct(private Database $db)
    {
    }

    public function log(string $action, ?string $subject = null, array $detail = [], ?int $adminId = null, ?string $ip = null): void
    {
        $this->db->run(
            'INSERT INTO audit_log (admin_id, action, subject, detail, ip) VALUES (:a, :act, :s, :d, :ip)',
            [
                'a'   => $adminId,
                'act' => $action,
                's'   => $subject,
                'd'   => $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
                'ip'  => $ip,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);
    }
}
