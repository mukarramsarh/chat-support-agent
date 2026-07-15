<?php

declare(strict_types=1);

use SupportAI\Support\Config;
use SupportAI\Support\Csrf;
use SupportAI\Support\Env;
use SupportAI\Support\Lang;

if (!function_exists('base_path')) {
    /** Absolute path from the project root. */
    function base_path(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /** HTML-escape for safe output in views. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('u')) {
    /**
     * Build an app URL that respects APP_BASE_PATH (sub-directory deploys).
     * u('/admin') → '/admin' at root, or '/chatbot/admin' under a base path.
     */
    function u(string $path = ''): string
    {
        $base = Config::basePath(Env::get('APP_BASE_PATH', ''));
        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base;
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('t')) {
    /** Translate an admin string (English default, Arabic from admin/lang/ar.php). */
    function t(string $key, ?string $default = null): string
    {
        return Lang::get($key, $default);
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden input carrying the CSRF token, for admin forms. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('str_uuid')) {
    /** RFC-4122 v4 UUID. */
    function str_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
