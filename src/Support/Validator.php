<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Strict, fail-closed input validation. Every method REJECTS bad input by
 * throwing ValidationException rather than silently trimming or coercing it —
 * the caller turns that into a generic 422 so nothing malformed reaches the
 * database, the LLM, or a downstream fetch.
 *
 * Deliberately tiny and dependency-free (shared-hosting rule). Use for the
 * public request surface where input is attacker-controlled.
 */
final class Validator
{
    /**
     * A required, length-bounded string. Trims first, then enforces bounds on the
     * trimmed value so " " doesn't pass a min-length check.
     */
    public static function string(mixed $value, string $field, int $min = 1, int $max = 4000): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new ValidationException("{$field} is required.");
        }
        $v = trim((string) $value);
        $len = mb_strlen($v);
        if ($len < $min) {
            throw new ValidationException("{$field} is required.");
        }
        if ($len > $max) {
            throw new ValidationException("{$field} is too long.");
        }
        return $v;
    }

    /** An optional string: '' when absent, else length-capped (never throws on emptiness). */
    public static function optionalString(mixed $value, string $field, int $max = 4000): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return self::string($value, $field, 1, $max);
    }

    /** Match a strict pattern (e.g. an id token) or reject. */
    public static function pattern(mixed $value, string $field, string $regex, int $max = 128): string
    {
        $v = self::string($value, $field, 1, $max);
        if (!preg_match($regex, $v)) {
            throw new ValidationException("{$field} is invalid.");
        }
        return $v;
    }

    /** A token-like id (letters, digits, -, _). Empty allowed → returns ''. */
    public static function optionalId(mixed $value, string $field, int $max = 64): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return self::pattern($value, $field, '/^[A-Za-z0-9._-]{1,' . $max . '}$/', $max);
    }

    public static function email(mixed $value, string $field = 'Email'): string
    {
        $v = self::string($value, $field, 3, 254);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Please enter a valid " . strtolower($field) . '.');
        }
        return $v;
    }

    /**
     * A public http(s) URL, length-capped. Rejects other schemes outright.
     * Note: this validates SHAPE only — SSRF host-resolution checks live in
     * UrlGuard, applied on the fetch path.
     */
    public static function httpUrl(mixed $value, string $field = 'URL', int $max = 2048): string
    {
        $v = self::string($value, $field, 4, $max);
        $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($v, PHP_URL_HOST) === null) {
            throw new ValidationException("{$field} must be a valid http(s) URL.");
        }
        return $v;
    }

    /** One of an allowed set, or reject. */
    public static function inSet(mixed $value, string $field, array $allowed): string
    {
        $v = is_string($value) ? $value : '';
        if (!in_array($v, $allowed, true)) {
            throw new ValidationException("{$field} is invalid.");
        }
        return $v;
    }
}
