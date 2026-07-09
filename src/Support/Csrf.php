<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Session-based CSRF tokens for admin state-changing requests. A single
 * per-session token is issued and compared in constant time. Cheap and
 * shared-hosting friendly (no storage beyond the PHP session).
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool
    {
        self::ensureSession();
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
