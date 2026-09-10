<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpClientOptions;
use PHPUnit\Framework\TestCase;

final class LlmHttpClientOptionsTest extends TestCase
{
    public function testConstructUsesDefaultsAndExplicitValues(): void
    {
        $defaults = new LlmHttpClientOptions();
        $this->assertSame(LlmHttpClientOptions::DEFAULT_TIMEOUT, $defaults->timeout);
        $this->assertSame(LlmHttpClientOptions::DEFAULT_MAX_DURATION, $defaults->maxDuration);

        $explicit = new LlmHttpClientOptions(60, 300);
        $this->assertSame(60, $explicit->timeout);
        $this->assertSame(300, $explicit->maxDuration);
    }

    public function testConstructRejectsInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmHttpClientOptions(timeout: 0);
    }

    public function testHttpClientOptions(): void
    {
        $options = new LlmHttpClientOptions(timeout: 45, maxDuration: 180);

        $this->assertSame(['timeout' => 45, 'max_duration' => 180], $options->httpClientOptions());
    }
}
