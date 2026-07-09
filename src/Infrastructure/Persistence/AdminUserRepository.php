<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

final class AdminUserRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->first('SELECT * FROM admin_users WHERE email = :e', ['e' => strtolower($email)]);
    }

    public function count(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM admin_users')['c'] ?? 0);
    }

    public function create(string $email, string $name, string $passwordHash, string $role = 'owner'): int
    {
        $this->db->run(
            'INSERT INTO admin_users (email, name, password_hash, role) VALUES (:e, :n, :p, :r)',
            ['e' => strtolower($email), 'n' => $name, 'p' => $passwordHash, 'r' => $role]
        );
        return (int) $this->db->lastId();
    }

    public function touchLogin(int $id): void
    {
        $this->db->run('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }
}
