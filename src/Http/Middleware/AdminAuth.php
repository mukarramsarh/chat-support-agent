<?php

declare(strict_types=1);

namespace SupportAI\Http\Middleware;

use SupportAI\Http\Request;
use SupportAI\Http\Response;

/**
 * Guards the admin area. Returns false (short-circuiting the route) and issues
 * a redirect to the login page when there is no authenticated session.
 */
final class AdminAuth
{
    public function __invoke(Request $request): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['admin_id'])) {
            Response::redirect(u('/admin/login'));
            return false;
        }
        return true;
    }
}
