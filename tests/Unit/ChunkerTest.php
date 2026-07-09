<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Application\Ingestion\Chunker;

final class ChunkerTest extends TestCase
{
    public function testEmptyTextYieldsNoChunks(): void
    {
        self::assertSame([], (new Chunker())->chunk('   '));
    }

    public function testShortTextIsOneChunk(): void
    {
        $chunks = (new Chunker())->chunk('Returns are accepted within 30 days.');
        self::assertCount(1, $chunks);
        self::assertStringContainsString('30 days', $chunks[0]['content']);
        self::assertGreaterThan(0, $chunks[0]['tokens']);
    }

    public function testLongTextSplitsIntoMultipleChunks(): void
    {
        $para = str_repeat('This is a sentence about shipping and delivery policies. ', 60);
        $chunks = (new Chunker(400, 50))->chunk($para);
        self::assertGreaterThan(1, count($chunks));
        // No chunk should hugely exceed the target size.
        foreach ($chunks as $c) {
            self::assertLessThanOrEqual(900, mb_strlen($c['content']));
        }
    }

    public function testParagraphsArePreserved(): void
    {
        $text = "First paragraph about refunds.\n\nSecond paragraph about shipping.";
        $chunks = (new Chunker())->chunk($text);
        self::assertStringContainsString('refunds', $chunks[0]['content']);
        self::assertStringContainsString('shipping', $chunks[0]['content']);
    }
}
