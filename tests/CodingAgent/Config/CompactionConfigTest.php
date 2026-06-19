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

        self::assertTrue($config->autoEnabled);
        self::assertSame(120000, $config->compactAfterTokens);
        self::assertSame(20000, $config->keepRecentTokens);
        self::assertNull($config->model);
        self::assertNull($config->thinkingLevel);
        self::assertSame([], $config->providerOverrides);
        self::assertSame([], $config->modelOverrides);
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
     * fromAppConfig extracts the compaction config from AppConfig.
     */
    public function testFromAppConfig(): void
    {
        $appConfig = new \Ineersa\CodingAgent\Config\AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'cyberpunk'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            compaction: new CompactionConfig(compactAfterTokens: 80000, keepRecentTokens: 40000),
        );

        $config = CompactionConfig::fromAppConfig($appConfig);

        self::assertSame(80000, $config->compactAfterTokens);
        self::assertSame(40000, $config->keepRecentTokens);
    }

    /**
     * resolveRuntimeSettings returns global values when no overrides apply.
     */
    public function testResolveRuntimeSettingsNoOverrides(): void
    {
        $config = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 120000,
            keepRecentTokens: 20000,
            model: 'llama_cpp/flash',
            thinkingLevel: 'medium',
        );

        $runtime = $config->resolveRuntimeSettings('openai/gpt-4.1');

        self::assertTrue($runtime->autoEnabled);
        self::assertSame(120000, $runtime->compactAfterTokens);
        self::assertSame(20000, $runtime->keepRecentTokens);
        self::assertSame('llama_cpp/flash', $runtime->model);
        self::assertSame('medium', $runtime->thinkingLevel);
    }

    /**
     * Provider overrides apply when active model matches a provider.
     */
    public function testResolveRuntimeSettingsProviderOverride(): void
    {
        $config = new CompactionConfig(
            compactAfterTokens: 120000,
            model: null,
            thinkingLevel: null,
            providerOverrides: [
                'openai' => [
                    'compact_after_tokens' => 80000,
                    'model' => 'openai/gpt-4.1-mini',
                    'thinking_level' => 'low',
                ],
            ],
        );

        $runtime = $config->resolveRuntimeSettings('openai/gpt-4.1');

        self::assertSame(80000, $runtime->compactAfterTokens);
        self::assertSame('openai/gpt-4.1-mini', $runtime->model);
        self::assertSame('low', $runtime->thinkingLevel);
    }

    /**
     * Model overrides win over provider overrides.
     */
    public function testResolveRuntimeSettingsModelOverrideWins(): void
    {
        $config = new CompactionConfig(
            compactAfterTokens: 120000,
            model: null,
            providerOverrides: [
                'openai' => [
                    'compact_after_tokens' => 80000,
                    'thinking_level' => 'low',
                ],
            ],
            modelOverrides: [
                'openai/gpt-4.1' => [
                    'compact_after_tokens' => 140000,
                    'thinking_level' => 'off',
                ],
            ],
        );

        $runtime = $config->resolveRuntimeSettings('openai/gpt-4.1');

        // Model override wins over provider.
        self::assertSame(140000, $runtime->compactAfterTokens);
        self::assertSame('off', $runtime->thinkingLevel);
    }
}
