<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use PDO;
use PDOException;
use RuntimeException;
use SupportAI\Support\Config;

/**
 * Read-only access to the CMS's own `users` table — the single source of
 * truth for admin login credentials across the CMS, support-ai, and
 * assessment. A separate PDO connection from Database::class because this
 * app deliberately keeps its own database (avoids table-name collisions
 * with the CMS's schema), so the CMS's users live in a different database
 * on the same MySQL server. See AdminController::login().
 */
final class CmsUserRepository
{
    private ?PDO $pdo = null;

    public function __construct(private Config $config)
    {
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config->string('cms_db.host'),
            $this->config->int('cms_db.port', 3306),
            $this->config->string('cms_db.name'),
            $this->config->string('cms_db.charset', 'utf8mb4'),
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config->string('cms_db.user'),
                $this->config->string('cms_db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('CMS database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        return $this->pdo;
    }

    /**
     * Looks up an active, non-contributor CMS user by username or email.
     * Password verification happens in the caller (AdminController::login)
     * against the returned password_hash — this method never checks it.
     *
     * @return array<string,mixed>|null
     */
    public function findLoginable(string $identifier): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, name, email, username, password_hash, role, is_active
             FROM users WHERE (username = :u1 OR email = :u2) LIMIT 1'
        );
        $stmt->execute(['u1' => $identifier, 'u2' => $identifier]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
