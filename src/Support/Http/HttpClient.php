<?php

declare(strict_types=1);

namespace SupportAI\Support\Http;

use RuntimeException;

/**
 * Thin cURL wrapper. We avoid Guzzle so the app runs on bare shared hosting
 * with only ext-curl. Supports normal JSON requests and streaming responses
 * (needed to relay provider token streams to the browser over SSE).
 */
final class HttpClient
{
    public function __construct(private int $timeout = 60)
    {
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $ch = curl_init($url);
        $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->normaliseHeaders($headers),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }
        return new HttpResponse($status, (string) $raw);
    }

    /**
     * Stream a response line-by-line. $onChunk receives each raw chunk as it
     * arrives; return value is the final HTTP status code.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|string $body
     * @param callable(string):void $onChunk
     */
    public function stream(string $method, string $url, array $headers, array|string $body, callable $onChunk): int
    {
        $ch = curl_init($url);
        $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $this->normaliseHeaders($headers),
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($onChunk): int {
                $onChunk($data);
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' && $status === 0) {
            throw new RuntimeException("HTTP stream failed: {$error}");
        }
        return $status;
    }

    /** @param array<string,string> $headers */
    private function normaliseHeaders(array $headers): array
    {
        $out = [];
        $headers += ['Content-Type' => 'application/json'];
        foreach ($headers as $name => $value) {
            $out[] = "{$name}: {$value}";
        }
        return $out;
    }
}
