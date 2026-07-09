<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/**
 * Key/value app settings (embedding lock, kb_version, install flag, …).
 * Values are stored as strings; callers cast as needed.
 */
final class SettingsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->first('SELECT `value` FROM settings WHERE `key` = :k', ['k' => $key]);
        return $row['value'] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['k' => $key, 'v' => $value]
        );
    }

    public function kbVersion(): int
    {
        return (int) ($this->get('kb_version', '1'));
    }

    /** Bumped on any ingest so the answer cache invalidates automatically. */
    public function bumpKbVersion(): void
    {
        $this->set('kb_version', (string) ($this->kbVersion() + 1));
    }
}
