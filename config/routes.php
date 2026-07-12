<?php

declare(strict_types=1);

use SupportAI\Http\Controller\AdminController;
use SupportAI\Http\Controller\ChatController;
use SupportAI\Http\Controller\DocumentController;
use SupportAI\Http\Controller\EvalController;
use SupportAI\Http\Controller\InstallController;
use SupportAI\Http\Controller\WidgetController;
use SupportAI\Http\Middleware\AdminAuth;
use SupportAI\Http\Middleware\VerifyCsrf;
use SupportAI\Http\Router;
use SupportAI\Support\Container;

/**
 * Route table. Public widget/chat endpoints, then the authenticated admin area.
 * Middleware names are registered on the router and referenced per route.
 */
return function (Router $router, Container $container): void {
    $router->registerMiddleware('admin', new AdminAuth());
    $router->registerMiddleware('csrf', new VerifyCsrf());

    // ── Web installer (self-locks once an agent exists) ──
    $router->get('/install', [InstallController::class, 'show']);
    $router->post('/install', [InstallController::class, 'run']);

    // ── Public: widget + chat API ──
    $router->get('/widget.js', [WidgetController::class, 'script']);
    $router->get('/api/widget/config', [WidgetController::class, 'config']);
    $router->get('/demo', [WidgetController::class, 'demo']);

    $router->post('/api/chat/message', [ChatController::class, 'message']);       // SSE stream
    $router->post('/api/chat/feedback', [ChatController::class, 'feedback']);
    $router->post('/api/chat/lead', [ChatController::class, 'lead']);             // startup form

    // ── Admin area (session-guarded) ──
    $router->get('/admin/login', [AdminController::class, 'loginForm']);
    $router->post('/admin/login', [AdminController::class, 'login'], ['csrf']);
    $router->get('/admin/logout', [AdminController::class, 'logout']);
    $router->get('/admin/locale', [AdminController::class, 'setLocale'], ['admin']);

    $router->get('/admin', [AdminController::class, 'dashboard'], ['admin']);
    $router->get('/admin/agent', [AdminController::class, 'agent'], ['admin']);
    $router->post('/admin/agent', [AdminController::class, 'saveAgent'], ['admin', 'csrf']);
    $router->get('/admin/api/models', [AdminController::class, 'models'], ['admin']);
    $router->get('/admin/knowledge', [AdminController::class, 'knowledge'], ['admin']);
    $router->post('/admin/knowledge/text', [DocumentController::class, 'addText'], ['admin', 'csrf']);
    $router->post('/admin/knowledge/url', [DocumentController::class, 'addUrl'], ['admin', 'csrf']);
    $router->post('/admin/knowledge/upload', [DocumentController::class, 'upload'], ['admin', 'csrf']);
    $router->post('/admin/knowledge/delete', [DocumentController::class, 'delete'], ['admin', 'csrf']);
    $router->post('/admin/knowledge/refresh', [DocumentController::class, 'refresh'], ['admin', 'csrf']);
    $router->get('/admin/conversations', [AdminController::class, 'conversations'], ['admin']);
    $router->get('/admin/conversations/{id}', [AdminController::class, 'conversationDetail'], ['admin']);
    $router->get('/admin/conversations/{id}/export', [AdminController::class, 'exportConversation'], ['admin']);
    $router->post('/admin/conversations/{id}/status', [AdminController::class, 'updateConversationStatus'], ['admin', 'csrf']);
    $router->get('/admin/costs', [AdminController::class, 'costs'], ['admin']);
    $router->get('/admin/test', [EvalController::class, 'sandbox'], ['admin']);
    $router->post('/admin/test', [EvalController::class, 'sandbox'], ['admin', 'csrf']);
    $router->get('/admin/evals', [EvalController::class, 'index'], ['admin']);
    $router->post('/admin/evals/set', [EvalController::class, 'createSet'], ['admin', 'csrf']);
    $router->post('/admin/evals/set/delete', [EvalController::class, 'deleteSet'], ['admin', 'csrf']);
    $router->post('/admin/evals/case', [EvalController::class, 'addCase'], ['admin', 'csrf']);
    $router->post('/admin/evals/case/delete', [EvalController::class, 'deleteCase'], ['admin', 'csrf']);
    $router->post('/admin/evals/run', [EvalController::class, 'run'], ['admin', 'csrf']);

    $router->get('/admin/privacy', [AdminController::class, 'privacy'], ['admin']);
    $router->post('/admin/privacy', [AdminController::class, 'savePrivacy'], ['admin', 'csrf']);
    $router->post('/admin/privacy/erase', [AdminController::class, 'eraseVisitor'], ['admin', 'csrf']);
    $router->get('/admin/privacy/export', [AdminController::class, 'exportVisitor'], ['admin']);
};
