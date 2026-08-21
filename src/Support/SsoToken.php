<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Verifies the short-lived HMAC hand-off token used for CMS -> support-ai
 * single sign-on. The CMS (already holding a real, password-verified admin
 * session) proves both knowledge of the shared secret and which exact user
 * this is, right now:
 *
 *   t     = current unix timestamp
 *   email = the CMS user's email
 *   h     = hash_hmac('sha256', "{t}|{email}", SSO_SECRET)
 *   -> GET /admin/sso?t=<t>&email=<email>&h=<h>
 *
 * The controller looks that email up in the CMS's own users table (see
 * CmsUserRepository) rather than trusting the email string alone — the
 * signature only proves the CMS vouched for it a moment ago. Pure and
 * side-effect free so it can be unit-tested; replay-prevention,
 * rate-limiting and session creation live in the controller.
 */
final class SsoToken
{
    /** The signature the CMS must send for timestamp $ts and $email. */
    public static function sign(string $secret, int $ts, string $email): string
    {
        return hash_hmac('sha256', $ts . '|' . $email, $secret);
    }

    /**
     * Constant-time verification of a token. Returns false for anything off:
     * unconfigured secret, non-numeric timestamp, outside the freshness
     * window, or a signature that doesn't match. $ttl is the allowed age in
     * seconds; a small backward window also tolerates minor clock skew
     * between the two servers.
     */
    public static function verify(string $secret, string $t, string $h, string $email, int $ttl, int $now): bool
    {
        if ($secret === '' || $h === '' || $email === '' || !ctype_digit($t)) {
            return false;
        }
        $ts = (int) $t;
        if (abs($now - $ts) > $ttl) {
            return false;
        }
        return hash_equals(self::sign($secret, $ts, $email), $h);
    }
}
