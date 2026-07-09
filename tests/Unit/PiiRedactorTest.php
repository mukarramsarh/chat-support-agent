<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\PiiRedactor;

final class PiiRedactorTest extends TestCase
{
    private PiiRedactor $r;

    protected function setUp(): void
    {
        $this->r = new PiiRedactor();
    }

    public function testRedactsEmail(): void
    {
        $out = $this->r->clean('Contact me at ali.hassan@example.com please');
        self::assertStringNotContainsString('@example.com', $out);
        self::assertStringContainsString('[EMAIL]', $out);
    }

    public function testRedactsSaudiPhone(): void
    {
        $out = $this->r->clean('Call 0512345678 now');
        self::assertStringContainsString('[PHONE]', $out);
        self::assertStringNotContainsString('0512345678', $out);
    }

    public function testRedactsNationalId(): void
    {
        $out = $this->r->clean('My id is 1098765432');
        self::assertStringContainsString('[ID]', $out);
    }

    public function testRedactsCardAndIban(): void
    {
        $card = $this->r->clean('card 4111 1111 1111 1111');
        self::assertStringContainsString('[CARD]', $card);
        $iban = $this->r->clean('SA0380000000608010167519');
        self::assertStringContainsString('[IBAN]', $iban);
    }

    public function testCleanTextUnchanged(): void
    {
        $text = 'What are your opening hours on weekends?';
        $result = $this->r->redact($text);
        self::assertSame($text, $result['text']);
        self::assertFalse($result['redacted']);
    }

    public function testReportsCounts(): void
    {
        $result = $this->r->redact('a@b.com and c@d.com');
        self::assertSame(2, $result['counts']['[EMAIL]'] ?? 0);
    }
}
