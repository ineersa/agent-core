<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;

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

    /**
     * Thesis: Symfony Serializer denormalization preserves provider_overrides
     * and model_overrides through the production AppConfig::fromContainer()
     * path (same ObjectNormalizer+ReflectionExtractor stack as boot time).
     *
     * Without this, config files with overrides would silently produce
     * empty arrays instead of the populated override maps.
     */
    public function testDenormalizationPreservesOverrides(): void
    {
        $projectDir = TestDirectoryIsolation::createProjectTempDir('compaction_cfg_serializer');

        try {
            $homeDir = $projectDir.'/home';
            $configDir = $projectDir.'/config';

            TestDirectoryIsolation::ensureDirectory($homeDir);
            TestDirectoryIsolation::ensureDirectory($homeDir.'/.hatfield');
            TestDirectoryIsolation::ensureDirectory($configDir);

            // Minimal home settings (no compaction section — defaults drive).
            file_put_contents($homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

            // Defaults with compaction provider/model overrides.
            $defaults = [
                'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
                'sessions' => ['path' => '.hatfield/sessions'],
                'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
                'compaction' => [
                    'auto_enabled' => true,
                    'compact_after_tokens' => 120000,
                    'keep_recent_tokens' => 20000,
                    'model' => 'llama_cpp/flash',
                    'thinking_level' => 'low',
                    'provider_overrides' => [
                        'openai' => [
                            'compact_after_tokens' => 80000,
                            'thinking_level' => 'off',
                        ],
                    ],
                    'model_overrides' => [
                        'openai/gpt-4.1' => [
                            'compact_after_tokens' => 140000,
                            'thinking_level' => 'high',
                        ],
                    ],
                ],
            ];

            file_put_contents($configDir.'/hatfield.defaults.yaml', Yaml::dump($defaults));

            $pathResolver = new SettingsPathResolver('/app', $homeDir);
            $loader = new AppConfigLoader($pathResolver);
            $resources = new AppResourceLocator($projectDir);

            // Serializer setup mirrors the production FrameworkBundle wiring:
            // ClassMetadataFactory + AttributeLoader reads #[SerializedName] so
            // snake_case YAML keys map to camelCase constructor parameters.
            $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
            $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
            $reflectionExtractor = new ReflectionExtractor();
            $serializer = new Serializer(
                normalizers: [
                    new ObjectNormalizer(
                        classMetadataFactory: $classMetadataFactory,
                        nameConverter: $nameConverter,
                        propertyAccessor: \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor(),
                        propertyTypeExtractor: $reflectionExtractor,
                    ),
                ],
                encoders: [],
            );

            $appConfig = AppConfig::fromContainer($loader, $resources, $serializer, $projectDir);
            $compaction = CompactionConfig::fromAppConfig($appConfig);

            // Global settings survived denormalization.
            self::assertTrue($compaction->autoEnabled);
            self::assertSame(120000, $compaction->compactAfterTokens);
            self::assertSame('llama_cpp/flash', $compaction->model);
            self::assertSame('low', $compaction->thinkingLevel);

            // Provider overrides survived denormalization.
            self::assertArrayHasKey('openai', $compaction->providerOverrides);
            self::assertSame(80000, $compaction->providerOverrides['openai']['compact_after_tokens']);
            self::assertSame('off', $compaction->providerOverrides['openai']['thinking_level']);

            // Model overrides survived denormalization.
            self::assertArrayHasKey('openai/gpt-4.1', $compaction->modelOverrides);
            self::assertSame(140000, $compaction->modelOverrides['openai/gpt-4.1']['compact_after_tokens']);
            self::assertSame('high', $compaction->modelOverrides['openai/gpt-4.1']['thinking_level']);

            // Runtime resolution uses the denormalized overrides.
            $runtime = $compaction->resolveRuntimeSettings('openai/gpt-4.1');
            self::assertSame(140000, $runtime->compactAfterTokens);
            self::assertSame('high', $runtime->thinkingLevel);

            // extractProviderId(): bare strings return empty (no accidental matching).
            $config = new CompactionConfig(providerOverrides: ['bare' => ['compact_after_tokens' => 1]]);
            $runtimeBare = $config->resolveRuntimeSettings('noproviderslash');
            self::assertSame(CompactionConfig::DEFAULT_COMPACT_AFTER_TOKENS, $runtimeBare->compactAfterTokens);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
    }
}
