<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\CompactionConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompactionConfig::class)]
final class CompactionConfigTest extends TestCase
{
    /**
     * Default constructor produces the documented defaults.
     */
    public function testDefaults(): void
    {
        $config = new CompactionConfig();

        self::assertTrue($config->enabled);
        self::assertSame(16384, $config->reserveTokens);
        self::assertSame(20000, $config->keepRecentTokens);
        self::assertNull($config->maxSummaryTokens);
        self::assertNull($config->model);
    }

    /**
     * effectiveMaxSummaryTokens returns the explicit value when set.
     */
    public function testEffectiveMaxSummaryTokensExplicit(): void
    {
        $config = new CompactionConfig(maxSummaryTokens: 5000);

        self::assertSame(5000, $config->effectiveMaxSummaryTokens());
    }

    /**
     * effectiveMaxSummaryTokens falls back to floor(reserveTokens * 0.8) when null.
     */
    public function testEffectiveMaxSummaryTokensFallback(): void
    {
        $config = new CompactionConfig(reserveTokens: 20000, maxSummaryTokens: null);

        self::assertSame(16000, $config->effectiveMaxSummaryTokens());
    }

    /**
     * resolveModelReference returns null when model is null (session fallback).
     */
    public function testResolveModelReferenceNull(): void
    {
        $config = new CompactionConfig(model: null);

        self::assertNull($config->resolveModelReference());
    }

    /**
     * resolveModelReference parses a valid provider/model string.
     */
    public function testResolveModelReferenceValid(): void
    {
        $config = new CompactionConfig(model: 'llama_cpp/flash');

        $ref = $config->resolveModelReference();
        self::assertNotNull($ref);
        self::assertSame('llama_cpp', $ref->providerId);
        self::assertSame('flash', $ref->modelName);
    }

    /**
     * resolveModelReference throws on malformed model string.
     */
    public function testResolveModelReferenceInvalid(): void
    {
        $config = new CompactionConfig(model: 'notavalidref');

        $this->expectException(\InvalidArgumentException::class);
        $config->resolveModelReference();
    }

    /**
     * tryResolveModelReference returns null on malformed model string.
     */
    public function testTryResolveModelReferenceInvalid(): void
    {
        $config = new CompactionConfig(model: 'notavalidref');

        self::assertNull($config->tryResolveModelReference());
    }

    /**
     * tryResolveModelReference returns AiModelReference on valid input.
     */
    public function testTryResolveModelReferenceValid(): void
    {
        $config = new CompactionConfig(model: 'zai/glm-5.1');

        $ref = $config->tryResolveModelReference();
        self::assertNotNull($ref);
        self::assertSame('zai', $ref->providerId);
        self::assertSame('glm-5.1', $ref->modelName);
    }

    /**
     * fromAppConfig extracts the compaction config from AppConfig.
     */
    public function testFromAppConfig(): void
    {
        $appConfig = new \Ineersa\CodingAgent\Config\AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'cyberpunk'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            compaction: new CompactionConfig(reserveTokens: 30000, keepRecentTokens: 40000),
        );

        $config = CompactionConfig::fromAppConfig($appConfig);

        self::assertSame(30000, $config->reserveTokens);
        self::assertSame(40000, $config->keepRecentTokens);
    }
}
