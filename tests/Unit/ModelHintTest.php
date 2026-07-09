<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Infrastructure\LLM\ModelHint;

final class ModelHintTest extends TestCase
{
    public function testCheapTiers(): void
    {
        self::assertSame('cheapest & fastest', ModelHint::for('gemini-2.0-flash-lite'));
        self::assertSame('cheapest & fastest', ModelHint::for('claude-haiku-4-5'));
        self::assertSame('cheapest & fastest', ModelHint::for('gpt-4o-mini'));
    }

    public function testBalancedTier(): void
    {
        self::assertSame('fast & low cost', ModelHint::for('gemini-2.5-flash'));
    }

    public function testAccuracyTier(): void
    {
        self::assertSame('highest accuracy', ModelHint::for('claude-opus-4'));
        self::assertSame('highest accuracy', ModelHint::for('gpt-4o'));
        self::assertSame('highest accuracy', ModelHint::for('gemini-2.5-pro'));
    }

    public function testSortPutsHigherVersionsFirst(): void
    {
        $sorted = ModelHint::sort([
            ['id' => 'gemini-1.5-flash', 'hint' => ''],
            ['id' => 'gemini-2.5-flash', 'hint' => ''],
        ]);
        self::assertSame('gemini-2.5-flash', $sorted[0]['id']);
    }
}
