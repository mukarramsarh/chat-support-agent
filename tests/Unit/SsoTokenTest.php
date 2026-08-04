<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\SsoToken;

final class SsoTokenTest extends TestCase
{
    private const SECRET = 'shared-secret-value-32-chars-long!!';

    public function testValidTokenVerifies(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now);
        self::assertTrue(SsoToken::verify(self::SECRET, (string) $now, $h, 60, $now));
    }

    public function testMatchesWordPressSideFormula(): void
    {
        // The WP snippet computes exactly this — keep them in lock-step.
        $ts = 1_700_000_123;
        $expected = hash_hmac('sha256', (string) $ts, self::SECRET);
        self::assertSame($expected, SsoToken::sign(self::SECRET, $ts));
    }

    public function testExpiredTokenRejected(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $ts, $h, 60, $ts + 61));
    }

    public function testFreshWithinWindowAccepted(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts);
        self::assertTrue(SsoToken::verify(self::SECRET, (string) $ts, $h, 60, $ts + 59));
    }

    public function testTamperedSignatureRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $now, $h . '0', 60, $now));
    }

    public function testWrongSecretRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now);
        self::assertFalse(SsoToken::verify('a-different-secret-entirely-here!!', (string) $now, $h, 60, $now));
    }

    public function testEmptySecretAlwaysRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign('', $now);
        self::assertFalse(SsoToken::verify('', (string) $now, $h, 60, $now));
    }

    public function testNonNumericTimestampRejected(): void
    {
        self::assertFalse(SsoToken::verify(self::SECRET, 'not-a-number', 'deadbeef', 60, 1_700_000_000));
    }

    public function testFutureBeyondWindowRejected(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts);
        // 61s in the future is outside the symmetric 60s window.
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $ts, $h, 60, $ts - 61));
    }
}
