<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Infrastructure\Vector\VectorCodec;

final class VectorCodecTest extends TestCase
{
    public function testPackUnpackRoundTrip(): void
    {
        $vec = [0.1, -0.5, 1.0, 0.0, 3.14159];
        $restored = VectorCodec::unpack(VectorCodec::pack($vec));
        self::assertCount(count($vec), $restored);
        foreach ($vec as $i => $v) {
            self::assertEqualsWithDelta($v, $restored[$i], 1e-5);
        }
    }

    public function testUnpackEmptyIsEmpty(): void
    {
        self::assertSame([], VectorCodec::unpack(''));
    }

    public function testCosineIdenticalIsOne(): void
    {
        $a = [1.0, 2.0, 3.0];
        self::assertEqualsWithDelta(1.0, VectorCodec::cosine($a, $a), 1e-9);
    }

    public function testCosineOrthogonalIsZero(): void
    {
        self::assertEqualsWithDelta(0.0, VectorCodec::cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
    }

    public function testCosineOppositeIsMinusOne(): void
    {
        self::assertEqualsWithDelta(-1.0, VectorCodec::cosine([1.0, 1.0], [-1.0, -1.0]), 1e-9);
    }

    public function testCosineZeroVectorIsZero(): void
    {
        self::assertSame(0.0, VectorCodec::cosine([0.0, 0.0], [1.0, 1.0]));
    }
}
