<?php

declare(strict_types=1);

namespace SupportAI\Application\Compliance;

use SupportAI\Infrastructure\Persistence\AuditRepository;
use SupportAI\Infrastructure\Persistence\LeadRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Infrastructure\Database\Database;

/**
 * Implements the KSA PDPL data-subject rights and retention duties in code:
 *   • erase()   — right to erasure: delete a visitor's conversations, messages
 *                 (cascade), memories and leads.
 *   • export()  — right to access / data portability: everything held on a visitor.
 *   • purge()   — storage-limitation: auto-delete data older than the retention
 *                 window (run from cron).
 * Every action is written to the audit log.
 */
final class ComplianceService
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private AuditRepository $audit,
        private SettingsRepository $settings,
    ) {
    }

    /** Right to erasure. Returns counts removed. @return array<string,int> */
    public function erase(string $visitorId, ?int $adminId = null, ?string $ip = null): array
    {
        $convIds = array_map(
            static fn ($r) => (int) $r['id'],
            $this->db->all('SELECT id FROM conversations WHERE visitor_id = :v', ['v' => $visitorId])
        );

        $counts = [
            'conversations' => count($convIds),
            'leads'         => (int) ($this->db->first('SELECT COUNT(*) c FROM leads WHERE visitor_id = :v', ['v' => $visitorId])['c'] ?? 0),
            'memories'      => (int) ($this->db->first('SELECT COUNT(*) c FROM memories WHERE visitor_id = :v', ['v' => $visitorId])['c'] ?? 0),
        ];

        // messages cascade with conversations (FK ON DELETE CASCADE).
        $this->db->run('DELETE FROM conversations WHERE visitor_id = :v', ['v' => $visitorId]);
        $this->db->run('DELETE FROM leads WHERE visitor_id = :v', ['v' => $visitorId]);
        $this->db->run('DELETE FROM memories WHERE visitor_id = :v', ['v' => $visitorId]);

        $this->audit->log('data.erase', $visitorId, $counts, $adminId, $ip);
        return $counts;
    }

    /** Right to access. Returns all data held for the visitor (PII decrypted). @return array<string,mixed> */
    public function export(string $visitorId, ?int $adminId = null, ?string $ip = null): array
    {
        $conversations = $this->db->all('SELECT * FROM conversations WHERE visitor_id = :v', ['v' => $visitorId]);
        foreach ($conversations as &$c) {
            $c['messages'] = $this->db->all(
                'SELECT role, content, created_at FROM messages WHERE conversation_id = :id ORDER BY id',
                ['id' => $c['id']]
            );
        }
        unset($c);

        $leads = array_map(function ($row) {
            return [
                'created_at'   => $row['created_at'],
                'consent'      => (bool) $row['consent'],
                'consented_at' => $row['consented_at'],
                'fields'       => $this->leads->decryptFields($row),
            ];
        }, $this->leads->forVisitor($visitorId));

        $this->audit->log('data.export', $visitorId, ['conversations' => count($conversations)], $adminId, $ip);

        return [
            'visitor_id'    => $visitorId,
            'exported_at'   => date('c'),
            'conversations' => $conversations,
            'leads'         => $leads,
        ];
    }

    /** Storage limitation: delete data older than retention_days (0 = disabled). @return int conversations purged */
    public function purge(): int
    {
        $days = (int) ($this->settings->compliance()['retention_days'] ?? 0);
        if ($days <= 0) {
            return 0;
        }
        $convIds = $this->db->all(
            'SELECT id FROM conversations WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
        );
        $n = count($convIds);
        if ($n > 0) {
            $this->db->run('DELETE FROM conversations WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)');
            $this->db->run('DELETE FROM leads WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)');
            $this->audit->log('data.purge', null, ['days' => $days, 'conversations' => $n]);
        }
        return $n;
    }
}
