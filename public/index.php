<?php

declare(strict_types=1);

/**
 * Front controller. All web traffic routes through here. On shared hosting,
 * point the domain's document root at this /public directory (the .htaccess
 * rewrites everything to this file).
 */

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Http\Router;
use SupportAI\Support\Config;
use SupportAI\Support\Container;
use SupportAI\Support\Logger;

/** @var Container $container */
$container = require dirname(__DIR__) . '/bootstrap.php';

$config = $container->get(Config::class);

// Error handling: verbose locally, safe in production.
if ($config->bool('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) use ($container): void {
        $container->get(Logger::class)->error('Unhandled exception', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
        ]);
        if (!headers_sent()) {
            Response::error('Internal server error', 500);
        }
    });
}

// CORS preflight: the cross-origin widget sends OPTIONS before POSTing to the
// chat API. Answer it here so the real request is allowed through.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
    http_response_code(204);
    return;
}

$router = new Router($container);
(require dirname(__DIR__) . '/config/routes.php')($router, $container);

$router->dispatch(Request::fromGlobals());
