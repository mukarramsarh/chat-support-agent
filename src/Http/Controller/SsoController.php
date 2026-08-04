<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AdminUserRepository;
use SupportAI\Infrastructure\Persistence\AuditRepository;
use SupportAI\Support\Config;
use SupportAI\Support\RateLimiter;
use SupportAI\Support\SsoToken;

/**
 * WordPress → support-ai single sign-on. A logged-in WordPress admin clicks a
 * dashboard link carrying a short-lived HMAC token; we verify it and start the
 * admin session, so the same WordPress dashboard controls both this panel and
 * the sister "assessment" admin without a second password.
 *
 * The token only proves "someone holding the shared secret asked, just now", so
 * every layer that could turn that into abuse is closed here: per-IP rate limit,
 * single-use replay guard, constant-time compare, fresh session id, audit trail.
 * The endpoint is intentionally public (the visitor isn't logged in yet) but
 * self-guards entirely by signature.
 */
final class SsoController
{
    public function __construct(
        private AdminUserRepository $admins,
        private AuditRepository $audit,
        private RateLimiter $rateLimiter,
        private Config $config,
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function login(Request $request): void
    {
        $ip = $request->ip();

        // Cap attempts so the HMAC can't be brute-forced by hammering the endpoint.
        if ($this->rateLimiter->tooMany('sso:' . $ip, 20, 300)) {
            Response::error('Too many attempts. Please wait a few minutes.', 429);
            return;
        }

        $secret = $this->config->string('auth.sso_secret');
        if ($secret === '') {
            $this->audit->log('sso_login_fail', 'not_configured', [], null, $ip);
            Response::error('Single sign-on is not configured.', 503);
            return;
        }

        $t = (string) $request->input('t', '');
        $h = (string) $request->input('h', '');
        $ttl = max(15, $this->config->int('auth.sso_ttl', 60));

        if (!SsoToken::verify($secret, $t, $h, $ttl, time())) {
            $this->audit->log('sso_login_fail', 'bad_or_expired', [], null, $ip);
            Response::error('This sign-in link is invalid or has expired. Please click it again from WordPress.', 403);
            return;
        }

        // Single-use: the exact signature works once. A second hit within the
        // window (replay from history/logs/referrer) is rejected.
        if ($this->rateLimiter->hit('sso:used:' . $h, max(120, $ttl)) > 1) {
            $this->audit->log('sso_login_fail', 'replayed', [], null, $ip);
            Response::error('This sign-in link was already used. Please click it again from WordPress.', 403);
            return;
        }

        $admin = $this->admins->firstOwner();
        if ($admin === null) {
            Response::error('No admin account exists yet. Finish setup first.', 409);
            return;
        }

        session_regenerate_id(true); // fresh id once authenticated (anti-fixation)
        $_SESSION['admin_id'] = (int) $admin['id'];
        $this->admins->touchLogin((int) $admin['id']);
        $this->audit->log('sso_login', (string) $admin['email'], ['via' => 'wordpress'], (int) $admin['id'], $ip);

        Response::redirect(u('/admin'));
    }
}
