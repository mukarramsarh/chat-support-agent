<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Verifies the short-lived HMAC sign-in token used for WordPress → support-ai
 * SSO. The identity provider (a WordPress admin) proves both knowledge of the
 * shared secret and that the request is recent:
 *
 *   t = current unix timestamp
 *   h = hash_hmac('sha256', (string) t, SSO_SECRET)
 *   → GET /admin/sso?t=<t>&h=<h>
 *
 * This matches the scheme already used for the sister "assessment" admin so a
 * single WordPress dashboard can drive both. Pure and side-effect free so it can
 * be unit-tested; replay-prevention, rate-limiting and session creation live in
 * the controller.
 */
final class SsoToken
{
    /** The signature WordPress must send for timestamp $ts. */
    public static function sign(string $secret, int $ts): string
    {
        return hash_hmac('sha256', (string) $ts, $secret);
    }

    /**
     * Constant-time verification of a token. Returns false for anything off:
     * unconfigured secret, non-numeric timestamp, outside the freshness window,
     * or a signature that doesn't match. $ttl is the allowed age in seconds; a
     * small backward window also tolerates minor clock skew between the servers.
     */
    public static function verify(string $secret, string $t, string $h, int $ttl, int $now): bool
    {
        if ($secret === '' || $h === '' || !ctype_digit($t)) {
            return false;
        }
        $ts = (int) $t;
        // Symmetric freshness window (matches the assessment module's MAX_AGE),
        // which also tolerates minor clock skew between the two servers.
        if (abs($now - $ts) > $ttl) {
            return false;
        }
        return hash_equals(self::sign($secret, $ts), $h);
    }
}
