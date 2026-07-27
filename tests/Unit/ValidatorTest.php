<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\ValidationException;
use SupportAI\Support\Validator;

final class ValidatorTest extends TestCase
{
    public function testStringTrimsAndReturns(): void
    {
        self::assertSame('hello', Validator::string('  hello  ', 'Message'));
    }

    public function testStringRejectsEmptyAndWhitespace(): void
    {
        $this->expectException(ValidationException::class);
        Validator::string('   ', 'Message');
    }

    public function testStringRejectsOverLength(): void
    {
        $this->expectException(ValidationException::class);
        Validator::string(str_repeat('a', 2001), 'Message', 1, 2000);
    }

    public function testStringRejectsNonScalar(): void
    {
        $this->expectException(ValidationException::class);
        Validator::string(['array'], 'Message');
    }

    public function testOptionalStringAllowsEmpty(): void
    {
        self::assertSame('', Validator::optionalString(null, 'Page URL'));
        self::assertSame('', Validator::optionalString('', 'Page URL'));
    }

    public function testOptionalStringStillCapsLength(): void
    {
        $this->expectException(ValidationException::class);
        Validator::optionalString(str_repeat('x', 50), 'Page URL', 10);
    }

    public function testOptionalIdAcceptsTokenChars(): void
    {
        self::assertSame('v_ab-12.CD', Validator::optionalId('v_ab-12.CD', 'Session'));
        self::assertSame('', Validator::optionalId('', 'Session'));
    }

    /**
     * The important one for abuse: a visitor id is a key in rate-limit / cache
     * buckets, so it must not carry spaces, slashes or control chars.
     */
    public function testOptionalIdRejectsInjectionChars(): void
    {
        $this->expectException(ValidationException::class);
        Validator::optionalId('v/../etc passwd', 'Session');
    }

    public function testEmailAcceptsValidRejectsInvalid(): void
    {
        self::assertSame('a@b.com', Validator::email('a@b.com'));
        $this->expectException(ValidationException::class);
        Validator::email('not-an-email');
    }

    public function testHttpUrlAcceptsHttpAndHttps(): void
    {
        self::assertSame('https://example.com/x', Validator::httpUrl('https://example.com/x'));
        self::assertSame('http://example.com', Validator::httpUrl('http://example.com'));
    }

    public function testHttpUrlRejectsOtherSchemes(): void
    {
        $this->expectException(ValidationException::class);
        Validator::httpUrl('javascript:alert(1)');
    }

    public function testHttpUrlRejectsFileScheme(): void
    {
        $this->expectException(ValidationException::class);
        Validator::httpUrl('file:///etc/passwd');
    }

    public function testInSetEnforcesAllowedValues(): void
    {
        self::assertSame('ar', Validator::inSet('ar', 'Lang', ['en', 'ar']));
        $this->expectException(ValidationException::class);
        Validator::inSet('fr', 'Lang', ['en', 'ar']);
    }
}
