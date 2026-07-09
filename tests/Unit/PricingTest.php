<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Domain\LLM\Usage;
use SupportAI\Infrastructure\LLM\Pricing;

final class PricingTest extends TestCase
{
    public function testVersionedGeminiIdResolvesNonZero(): void
    {
        // Regression: "gemini-2.5-flash" lacks the contiguous "gemini-flash".
        $cost = (new Pricing())->costOf('gemini-2.5-flash', new Usage(1_000_000, 0));
        self::assertGreaterThan(0.0, $cost);
    }

    public function testFlashLiteCheaperThanFlash(): void
    {
        $p = new Pricing();
        $lite = $p->costOf('gemini-2.0-flash-lite', new Usage(1_000_000, 0));
        $flash = $p->costOf('gemini-2.5-flash', new Usage(1_000_000, 0));
        self::assertLessThan($flash, $lite);
    }

    public function testUnknownModelStillCharged(): void
    {
        $cost = (new Pricing())->costOf('totally-unknown-model', new Usage(1_000_000, 1_000_000));
        self::assertGreaterThan(0.0, $cost, 'Unknown models must not read as $0 (budget bypass).');
    }

    public function testCachedTokensAreCheaperThanFresh(): void
    {
        $p = new Pricing();
        $fresh = $p->costOf('gpt-4o', new Usage(1000, 0, 0));
        $cached = $p->costOf('gpt-4o', new Usage(1000, 0, 1000));
        self::assertLessThan($fresh, $cached);
    }

    public function testOutputCostsCounted(): void
    {
        $cost = (new Pricing())->costOf('claude-sonnet-5', new Usage(0, 1_000_000));
        self::assertGreaterThan(0.0, $cost);
    }
}
