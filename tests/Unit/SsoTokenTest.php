<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\SsoToken;

final class SsoTokenTest extends TestCase
{
    private const SECRET = 'shared-secret-value-32-chars-long!!';
    private const EMAIL = 'admin@procurementhub.sa';

    public function testValidTokenVerifies(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now, self::EMAIL);
        self::assertTrue(SsoToken::verify(self::SECRET, (string) $now, $h, self::EMAIL, 60, $now));
    }

    public function testMatchesCmsSideFormula(): void
    {
        // The CMS's Sso::url() computes exactly this — keep them in lock-step.
        $ts = 1_700_000_123;
        $expected = hash_hmac('sha256', $ts . '|' . self::EMAIL, self::SECRET);
        self::assertSame($expected, SsoToken::sign(self::SECRET, $ts, self::EMAIL));
    }

    public function testExpiredTokenRejected(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts, self::EMAIL);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $ts, $h, self::EMAIL, 60, $ts + 61));
    }

    public function testFreshWithinWindowAccepted(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts, self::EMAIL);
        self::assertTrue(SsoToken::verify(self::SECRET, (string) $ts, $h, self::EMAIL, 60, $ts + 59));
    }

    public function testTamperedSignatureRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now, self::EMAIL);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $now, $h . '0', self::EMAIL, 60, $now));
    }

    public function testWrongSecretRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now, self::EMAIL);
        self::assertFalse(SsoToken::verify('a-different-secret-entirely-here!!', (string) $now, $h, self::EMAIL, 60, $now));
    }

    public function testEmptySecretAlwaysRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign('', $now, self::EMAIL);
        self::assertFalse(SsoToken::verify('', (string) $now, $h, self::EMAIL, 60, $now));
    }

    public function testNonNumericTimestampRejected(): void
    {
        self::assertFalse(SsoToken::verify(self::SECRET, 'not-a-number', 'deadbeef', self::EMAIL, 60, 1_700_000_000));
    }

    public function testFutureBeyondWindowRejected(): void
    {
        $ts = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $ts, self::EMAIL);
        // 61s in the future is outside the symmetric 60s window.
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $ts, $h, self::EMAIL, 60, $ts - 61));
    }

    public function testEmptyEmailAlwaysRejected(): void
    {
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now, self::EMAIL);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $now, $h, '', 60, $now));
    }

    public function testSignatureIsBoundToTheEmailItWasSignedFor(): void
    {
        // A valid signature for one email must not verify for a different
        // one — otherwise a captured token could be replayed to impersonate
        // whichever local account an attacker chooses.
        $now = 1_700_000_000;
        $h = SsoToken::sign(self::SECRET, $now, self::EMAIL);
        self::assertFalse(SsoToken::verify(self::SECRET, (string) $now, $h, 'someone-else@procurementhub.sa', 60, $now));
    }
}
