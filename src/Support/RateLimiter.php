<?php

declare(strict_types=1);

namespace SupportAI\Support;

use SupportAI\Infrastructure\Database\Database;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table (no Redis needed —
 * shared-hosting friendly). Used to cap public chat/lead abuse and admin login
 * brute-force. Expired buckets are cleaned opportunistically.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Register a hit for $key and report whether it is now over $max within the
     * $windowSeconds window. Returns true when the caller should be blocked.
     */
    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $bucket = $key . ':' . (int) floor(time() / $windowSeconds);
        $expires = date('Y-m-d H:i:s', time() + $windowSeconds);

        $this->db->run(
            'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (:b, 1, :e)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['b' => $bucket, 'e' => $expires]
        );
        $hits = (int) ($this->db->first('SELECT hits FROM rate_limits WHERE bucket = :b', ['b' => $bucket])['hits'] ?? 0);

        // Opportunistic cleanup (~1% of calls) to keep the table small.
        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE expires_at < NOW()');
        }
        return $hits > $max;
    }

    /** Count current hits without incrementing (for lockout checks). */
    public function current(string $key, int $windowSeconds): int
    {
        $bucket = $key . ':' . (int) floor(time() / $windowSeconds);
        return (int) ($this->db->first('SELECT hits FROM rate_limits WHERE bucket = :b', ['b' => $bucket])['hits'] ?? 0);
    }

    public function clear(string $key, int $windowSeconds): void
    {
        $bucket = $key . ':' . (int) floor(time() / $windowSeconds);
        $this->db->run('DELETE FROM rate_limits WHERE bucket = :b', ['b' => $bucket]);
    }
}
