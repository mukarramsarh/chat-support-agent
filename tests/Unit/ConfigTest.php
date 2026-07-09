<?php

declare(strict_types=1);

namespace SupportAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupportAI\Support\Config;

final class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'app'    => ['debug' => true, 'url' => 'https://x.test'],
            'budget' => ['monthly_usd' => 2.5, 'top_k' => 20],
            'nested' => ['a' => ['b' => 'deep']],
        ]);
    }

    public function testDotNotationGet(): void
    {
        self::assertSame('deep', $this->config->get('nested.a.b'));
        self::assertSame('https://x.test', $this->config->string('app.url'));
    }

    public function testDefaultsForMissingKeys(): void
    {
        self::assertSame('fallback', $this->config->string('missing.key', 'fallback'));
        self::assertSame(7, $this->config->int('missing.key', 7));
        self::assertSame(1.5, $this->config->float('missing.key', 1.5));
    }

    public function testTypedAccessors(): void
    {
        self::assertSame(2.5, $this->config->float('budget.monthly_usd'));
        self::assertSame(20, $this->config->int('budget.top_k'));
        self::assertTrue($this->config->bool('app.debug'));
    }
}
