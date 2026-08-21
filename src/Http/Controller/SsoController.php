<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AdminUserRepository;
use SupportAI\Infrastructure\Persistence\AuditRepository;
use SupportAI\Infrastructure\Persistence\CmsUserRepository;
use SupportAI\Support\Config;
use SupportAI\Support\RateLimiter;
use SupportAI\Support\SsoToken;

/**
 * CMS -> support-ai single sign-on. A CMS admin/editor (already holding a
 * real, password-verified CMS session) clicks a nav link carrying a
 * short-lived HMAC token bound to their email; we verify it, look that
 * email up in the CMS's own users table (the single source of truth for
 * credentials — see CmsUserRepository), sync a local profile row, and
 * start the admin session directly. No second password, no separate
 * login form.
 *
 * The token only proves "the CMS vouched for this email, just now", so
 * every layer that could turn that into abuse is closed here: per-IP rate
 * limit, single-use replay guard, constant-time compare, fresh session id,
 * audit trail. The endpoint is intentionally public (the visitor isn't
 * logged in yet) but self-guards entirely by signature.
 */
final class SsoController
{
    public function __construct(
        private AdminUserRepository $admins,
        private CmsUserRepository $cmsUsers,
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
        $email = trim((string) $request->input('email', ''));
        $ttl = max(15, $this->config->int('auth.sso_ttl', 60));

        if (!SsoToken::verify($secret, $t, $h, $email, $ttl, time())) {
            $this->audit->log('sso_login_fail', 'bad_or_expired', [], null, $ip);
            Response::error('This sign-in link is invalid or has expired. Please go back to the CMS and click it again.', 403);
            return;
        }

        // Single-use: the exact signature works once. A second hit within the
        // window (replay from history/logs/referrer) is rejected.
        if ($this->rateLimiter->hit('sso:used:' . $h, max(120, $ttl)) > 1) {
            $this->audit->log('sso_login_fail', 'replayed', [], null, $ip);
            Response::error('This sign-in link was already used. Please go back to the CMS and click it again.', 403);
            return;
        }

        $cmsUser = $this->cmsUsers->findLoginable($email);
        if ($cmsUser === null || !(bool) $cmsUser['is_active'] || $cmsUser['role'] === 'contributor') {
            $this->audit->log('sso_login_fail', 'no_matching_cms_user', ['email' => $email], null, $ip);
            Response::error('Your CMS account does not have access to this panel.', 403);
            return;
        }

        $localRole = $cmsUser['role'] === 'admin' ? 'owner' : 'admin'; // CMS admin -> owner, CMS editor -> admin
        $local = $this->admins->syncFromCms((string) $cmsUser['email'], (string) $cmsUser['name'], $localRole);

        session_regenerate_id(true); // fresh id once authenticated (anti-fixation)
        $_SESSION['admin_id'] = (int) $local['id'];
        $this->admins->touchLogin((int) $local['id']);
        $this->audit->log('sso_login', (string) $cmsUser['email'], ['via' => 'cms'], (int) $local['id'], $ip);

        Response::redirect(u('/admin'));
    }
}
