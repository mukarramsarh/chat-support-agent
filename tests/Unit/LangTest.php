<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\Lang;

final class LangTest extends TestCase
{
    public function testDetectsArabicScript(): void
    {
        self::assertTrue(Lang::hasArabic('مرحبا'));
        self::assertTrue(Lang::hasArabic('كم سعر الخدمة؟'));
        // Mixed content still counts as Arabic-present (pick the Arabic copy).
        self::assertTrue(Lang::hasArabic('price السعر'));
    }

    public function testEnglishAndSymbolsAreNotArabic(): void
    {
        self::assertFalse(Lang::hasArabic('How much does it cost?'));
        self::assertFalse(Lang::hasArabic('12345 +966 !?'));
        self::assertFalse(Lang::hasArabic(''));
    }
}
