<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;
use SupportAI\Support\Config;

/**
 * PDO connection factory + a couple of query conveniences. One lazy connection
 * per request is plenty for this workload.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** Cached server capability probe (see supportsNativeVector). */
    private ?bool $nativeVector = null;

    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config->string('db.host'),
            $this->config->int('db.port', 3306),
            $this->config->string('db.name'),
            $this->config->string('db.charset', 'utf8mb4'),
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config->string('db.user'),
                $this->config->string('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        return $this->pdo;
    }

    /** @param array<string,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function lastId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Whether the native VECTOR columns from migration 002 are actually present.
     * The server supporting the VECTOR *type* is not enough — the columns only
     * exist once that optional migration has been applied.
     */
    public function hasVectorColumns(): bool
    {
        try {
            $this->pdo()->query('SELECT embedding_vec FROM chunks LIMIT 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Probe whether the server can actually create and query a VECTOR index.
     * We test the real feature rather than trusting the version string, because
     * shared hosts patch versions unpredictably. Result is cached per request.
     */
    public function supportsNativeVector(): bool
    {
        if ($this->nativeVector !== null) {
            return $this->nativeVector;
        }

        try {
            $pdo = $this->pdo();
            $pdo->exec('CREATE TEMPORARY TABLE _vec_probe (v VECTOR(3))');
            // MySQL 9: STRING_TO_VECTOR; MariaDB: VEC_FromText. Try MySQL first.
            $pdo->exec("INSERT INTO _vec_probe (v) VALUES (STRING_TO_VECTOR('[1,2,3]'))");
            $pdo->exec('DROP TEMPORARY TABLE _vec_probe');
            $this->nativeVector = true;
        } catch (\Throwable) {
            $this->nativeVector = false;
        }

        return $this->nativeVector;
    }
}
