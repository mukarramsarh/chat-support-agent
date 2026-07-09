<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SupportAI\Support\Crypto;

final class CryptoTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $c = new Crypto('a-sufficiently-long-app-key-1234567890');
        $secret = 'sk-super-secret-provider-key';
        self::assertSame($secret, $c->decrypt($c->encrypt($secret)));
    }

    public function testCiphertextIsNotPlaintext(): void
    {
        $c = new Crypto('a-sufficiently-long-app-key-1234567890');
        $cipher = $c->encrypt('hello world');
        self::assertStringNotContainsString('hello world', $cipher);
        self::assertStringStartsWith('v1.', $cipher);
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $a = new Crypto('key-number-one-aaaaaaaaaaaaaaaaaaaa');
        $b = new Crypto('key-number-two-bbbbbbbbbbbbbbbbbbbb');
        $this->expectException(RuntimeException::class);
        $b->decrypt($a->encrypt('secret'));
    }

    public function testShortKeyRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new Crypto('short');
    }
}
