<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Minimal, dependency-free .env loader.
 *
 * We deliberately avoid vlucas/phpdotenv to keep the dependency surface tiny
 * for shared hosting. Values are loaded once into a static map; real process
 * environment variables always win over the file (useful for CI / containers).
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::clean($value);
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // Real environment wins, then .env file, then default.
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return self::$vars[$key] ?? $default;
    }

    /** Strip inline comments, surrounding quotes and whitespace. */
    private static function clean(string $value): string
    {
        $value = trim($value);

        // Remove an unquoted trailing comment: FOO=bar   # note
        if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
            if (preg_match('/\s+#/', $value)) {
                $value = preg_split('/\s+#/', $value, 2)[0];
            }
            return trim($value);
        }

        // Quoted value: strip the matching quotes.
        $quote = $value[0];
        $end = strrpos($value, $quote);
        if ($end > 0) {
            return substr($value, 1, $end - 1);
        }
        return trim($value, "\"'");
    }
}
