<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Tiny admin i18n. English is the default (passed inline as the fallback), so
 * only a single Arabic dictionary file (admin/lang/ar.php) is needed. Locale is
 * stored in the session and toggled from the admin. Untranslated keys fall back
 * to the English default, so the UI is never broken by a missing string.
 */
final class Lang
{
    /** @var array<string,string> */
    private static array $strings = [];

    private static ?string $locale = null;

    public static function locale(): string
    {
        if (self::$locale === null) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $l = $_SESSION['admin_locale'] ?? 'en';
            self::$locale = in_array($l, ['en', 'ar'], true) ? $l : 'en';
        }
        return self::$locale;
    }

    public static function setLocale(string $l): void
    {
        if (!in_array($l, ['en', 'ar'], true)) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['admin_locale'] = $l;
        self::$locale = $l;
        self::$strings = [];
    }

    public static function isRtl(): bool
    {
        return self::locale() === 'ar';
    }

    public static function get(string $key, ?string $default = null): string
    {
        if (self::locale() === 'en') {
            return $default ?? $key;
        }
        if (self::$strings === []) {
            $file = base_path('admin/lang/ar.php');
            self::$strings = is_file($file) ? (require $file) : ['__' => ''];
        }
        return self::$strings[$key] ?? ($default ?? $key);
    }
}
