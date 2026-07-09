<?php

declare(strict_types=1);

namespace SupportAI\Http;

/**
 * Response helpers. Note: streaming (SSE) responses bypass this object and
 * write directly to the output buffer — see SseStream.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(['error' => $message] + $extra, $status);
    }

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function noContent(int $status = 204): void
    {
        http_response_code($status);
    }

    public static function redirect(string $to, int $status = 302): void
    {
        http_response_code($status);
        header("Location: {$to}");
    }
}
