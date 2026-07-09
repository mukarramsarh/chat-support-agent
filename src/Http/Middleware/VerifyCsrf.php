<?php

declare(strict_types=1);

namespace SupportAI\Http\Middleware;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Support\Csrf;

/**
 * Rejects admin POST requests whose CSRF token is missing or wrong. Applied to
 * state-changing admin routes only — public JSON APIs (the widget) are exempt
 * because they are cross-origin and protected by the domain allowlist instead.
 */
final class VerifyCsrf
{
    public function __invoke(Request $request): bool
    {
        if ($request->method !== 'POST') {
            return true;
        }
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            // 403 (not the non-standard 419) — Apache/mod_php maps unknown codes to 500.
            Response::error('Invalid or expired form token. Please reload and try again.', 403);
            return false;
        }
        return true;
    }
}
