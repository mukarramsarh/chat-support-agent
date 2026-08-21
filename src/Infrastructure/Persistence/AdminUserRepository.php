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

    /**
     * The account SSO signs into: the owner if one exists, else the earliest
     * admin. @return array<string,mixed>|null
     */
    public function firstOwner(): ?array
    {
        return $this->db->first("SELECT * FROM admin_users WHERE role = 'owner' ORDER BY id ASC LIMIT 1")
            ?? $this->db->first('SELECT * FROM admin_users ORDER BY id ASC LIMIT 1');
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

    /**
     * Looks up (or creates) the local profile row for a CMS-authenticated
     * user by email, syncing name/role on every login so it never drifts
     * from the CMS. This row is NOT a credential store — password_hash is
     * only ever set once at creation to an unusable random value; the real
     * password lives in, and is verified against, the CMS's own users
     * table (see CmsUserRepository). @return array<string,mixed>
     */
    public function syncFromCms(string $email, string $name, string $role): array
    {
        $existing = $this->findByEmail($email);

        if ($existing === null) {
            $id = $this->create($email, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role);
            return $this->findByEmail($email) ?? ['id' => $id, 'email' => $email, 'name' => $name, 'role' => $role];
        }

        if ($existing['name'] !== $name || $existing['role'] !== $role) {
            $this->db->run(
                'UPDATE admin_users SET name = :n, role = :r WHERE id = :id',
                ['n' => $name, 'r' => $role, 'id' => $existing['id']]
            );
            $existing['name'] = $name;
            $existing['role'] = $role;
        }

        return $existing;
    }
}
