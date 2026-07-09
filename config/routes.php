<?php

declare(strict_types=1);

use SupportAI\Http\Controller\AdminController;
use SupportAI\Http\Controller\ChatController;
use SupportAI\Http\Controller\WidgetController;
use SupportAI\Http\Middleware\AdminAuth;
use SupportAI\Http\Router;
use SupportAI\Support\Container;

/**
 * Route table. Public widget/chat endpoints, then the authenticated admin area.
 * Middleware names are registered on the router and referenced per route.
 */
return function (Router $router, Container $container): void {
    $router->registerMiddleware('admin', new AdminAuth());

    // ── Public: widget + chat API ──
    $router->get('/widget.js', [WidgetController::class, 'script']);
    $router->get('/api/widget/config', [WidgetController::class, 'config']);
    $router->get('/demo', [WidgetController::class, 'demo']);

    $router->post('/api/chat/message', [ChatController::class, 'message']);       // SSE stream
    $router->post('/api/chat/feedback', [ChatController::class, 'feedback']);

    // ── Admin area (session-guarded) ──
    $router->get('/admin/login', [AdminController::class, 'loginForm']);
    $router->post('/admin/login', [AdminController::class, 'login']);
    $router->get('/admin/logout', [AdminController::class, 'logout']);

    $router->get('/admin', [AdminController::class, 'dashboard'], ['admin']);
    $router->get('/admin/agent', [AdminController::class, 'agent'], ['admin']);
    $router->post('/admin/agent', [AdminController::class, 'saveAgent'], ['admin']);
    $router->get('/admin/api/models', [AdminController::class, 'models'], ['admin']);
    $router->get('/admin/knowledge', [AdminController::class, 'knowledge'], ['admin']);
    $router->get('/admin/conversations', [AdminController::class, 'conversations'], ['admin']);
    $router->get('/admin/costs', [AdminController::class, 'costs'], ['admin']);
};
