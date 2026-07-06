<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Config\Ai\AiHttpConfig
 */
final class AiHttpConfigTest extends TestCase
{
    // ── fromArray: empty → all null ───────────────────────────────────────

    public function testFromArrayEmptyProducesAllNullFields(): void
    {
        $config = AiHttpConfig::fromArray([]);

        $this->assertNull($config->timeout);
        $this->assertNull($config->maxDuration);
        $this->assertNull($config->maxRetries);
        $this->assertNull($config->baseDelayMs);
        $this->assertNull($config->maxDelayMs);
    }

    // ── fromArray: explicit ints ──────────────────────────────────────────

    public function testFromArrayParsesExplicitInts(): void
    {
        $config = AiHttpConfig::fromArray([
            'timeout' => 30,
            'max_duration' => 120,
            'max_retries' => 5,
            'base_delay_ms' => 2000,
            'max_delay_ms' => 120_000,
        ]);

        $this->assertSame(30, $config->timeout);
        $this->assertSame(120, $config->maxDuration);
        $this->assertSame(5, $config->maxRetries);
        $this->assertSame(2000, $config->baseDelayMs);
        $this->assertSame(120_000, $config->maxDelayMs);
    }

    public function testFromArrayParsesIndividualKeysIndependently(): void
    {
        $config = AiHttpConfig::fromArray(['timeout' => 15]);

        $this->assertSame(15, $config->timeout);
        $this->assertNull($config->maxDuration);
        $this->assertNull($config->maxRetries);
        $this->assertNull($config->baseDelayMs);
        $this->assertNull($config->maxDelayMs);
    }

    // ── fromArray: env:VARNAME ────────────────────────────────────────────

    public function testFromArrayResolvesEnvVar(): void
    {
        putenv('HATFIELD_TEST_HTTP_TIMEOUT=45');
        try {
            $config = AiHttpConfig::fromArray(['timeout' => 'env:HATFIELD_TEST_HTTP_TIMEOUT']);
            $this->assertSame(45, $config->timeout);
        } finally {
            putenv('HATFIELD_TEST_HTTP_TIMEOUT');
        }
    }

    public function testFromArrayResolvesEnvVarForAllKeys(): void
    {
        putenv('HATFIELD_TEST_HTTP_T=8');
        putenv('HATFIELD_TEST_HTTP_D=60');
        putenv('HATFIELD_TEST_HTTP_R=3');
        putenv('HATFIELD_TEST_HTTP_B=500');
        putenv('HATFIELD_TEST_HTTP_M=30000');
        try {
            $config = AiHttpConfig::fromArray([
                'timeout' => 'env:HATFIELD_TEST_HTTP_T',
                'max_duration' => 'env:HATFIELD_TEST_HTTP_D',
                'max_retries' => 'env:HATFIELD_TEST_HTTP_R',
                'base_delay_ms' => 'env:HATFIELD_TEST_HTTP_B',
                'max_delay_ms' => 'env:HATFIELD_TEST_HTTP_M',
            ]);
            $this->assertSame(8, $config->timeout);
            $this->assertSame(60, $config->maxDuration);
            $this->assertSame(3, $config->maxRetries);
            $this->assertSame(500, $config->baseDelayMs);
            $this->assertSame(30000, $config->maxDelayMs);
        } finally {
            putenv('HATFIELD_TEST_HTTP_T');
            putenv('HATFIELD_TEST_HTTP_D');
            putenv('HATFIELD_TEST_HTTP_R');
            putenv('HATFIELD_TEST_HTTP_B');
            putenv('HATFIELD_TEST_HTTP_M');
        }
    }

    public function testFromArrayEnvVarUnsetReturnsNull(): void
    {
        // Unset env — ensure the var is not set
        putenv('HATFIELD_TEST_HTTP_NONEXISTENT');
        $config = AiHttpConfig::fromArray(['timeout' => 'env:HATFIELD_TEST_HTTP_NONEXISTENT']);

        $this->assertNull($config->timeout);
    }

    public function testFromArrayEnvVarEmptyReturnsNull(): void
    {
        putenv('HATFIELD_TEST_HTTP_EMPTY=');
        try {
            $config = AiHttpConfig::fromArray(['timeout' => 'env:HATFIELD_TEST_HTTP_EMPTY']);
            $this->assertNull($config->timeout);
        } finally {
            putenv('HATFIELD_TEST_HTTP_EMPTY');
        }
    }

    // ── fromArray: plain numeric string ───────────────────────────────────

    public function testFromArrayParsesNumericString(): void
    {
        $config = AiHttpConfig::fromArray(['timeout' => '15']);

        $this->assertSame(15, $config->timeout);
    }

    // ── fromArray: invalid values ─────────────────────────────────────────

    public function testFromArrayThrowsForNonNumericString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ai.http.timeout');

        AiHttpConfig::fromArray(['timeout' => 'abc']);
    }

    public function testFromArrayThrowsForBareEnv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('env:');

        AiHttpConfig::fromArray(['timeout' => 'env:']);
    }

    public function testFromArrayThrowsForNonScalarType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ai.http.max_retries');

        AiHttpConfig::fromArray(['max_retries' => []]);
    }

    // ── Integration: AiConfig reads ai.http via fromArray ─────────────────

    public function testAiConfigFromArrayPassesHttpBlock(): void
    {
        $aiData = [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'http' => [
                'timeout' => 7,
                'max_retries' => 0,
            ],
        ];

        $config = AiConfig::fromArray($aiData);

        $this->assertSame(7, $config->http->timeout);
        $this->assertSame(0, $config->http->maxRetries);
        $this->assertNull($config->http->maxDuration);
        $this->assertNull($config->http->baseDelayMs);
        $this->assertNull($config->http->maxDelayMs);
    }

    public function testAiConfigFromArrayDefaultsHttpToEmpty(): void
    {
        $aiData = [
            'default_model' => 'deepseek/deepseek-v4-pro',
        ];

        $config = AiConfig::fromArray($aiData);

        $this->assertNotNull($config->http);
        $this->assertNull($config->http->timeout);
        $this->assertNull($config->http->maxRetries);
    }
}
