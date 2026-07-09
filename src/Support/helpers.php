<?php

declare(strict_types=1);

use SupportAI\Support\Env;

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
