<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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

        $this->assertTrue($config->autoEnabled);
        $this->assertSame(120000, $config->compactAfterTokens);
        $this->assertSame(20000, $config->keepRecentTokens);
        $this->assertNull($config->model);
        $this->assertNull($config->thinkingLevel);
        $this->assertSame([], $config->providerOverrides);
        $this->assertSame([], $config->modelOverrides);
    }

    /**
     * resolveModelReference returns null when model is null (session fallback).
     */
    public function testResolveModelReferenceNull(): void
    {
        $config = new CompactionConfig(model: null);

        $this->assertNull($config->resolveModelReference());
    }

    /**
     * resolveModelReference parses a valid provider/model string.
     */
    public function testResolveModelReferenceValid(): void
    {
        $config = new CompactionConfig(model: 'llama_cpp/flash');

        $ref = $config->resolveModelReference();
        $this->assertNotNull($ref);
        $this->assertSame('llama_cpp', $ref->providerId);
        $this->assertSame('flash', $ref->modelName);
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

        $this->assertTrue($runtime->autoEnabled);
        $this->assertSame(120000, $runtime->compactAfterTokens);
        $this->assertSame(20000, $runtime->keepRecentTokens);
        $this->assertSame('llama_cpp/flash', $runtime->model);
        $this->assertSame('medium', $runtime->thinkingLevel);
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

        $this->assertSame(80000, $runtime->compactAfterTokens);
        $this->assertSame('openai/gpt-4.1-mini', $runtime->model);
        $this->assertSame('low', $runtime->thinkingLevel);
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
        $this->assertSame(140000, $runtime->compactAfterTokens);
        $this->assertSame('off', $runtime->thinkingLevel);
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
            $resolver = new SettingsResolver($pathResolver);
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

            $appConfig = AppConfig::fromContainer($resolver, $resources, $serializer, $projectDir);
            $compaction = $appConfig->compaction;

            // Global settings survived denormalization.
            $this->assertTrue($compaction->autoEnabled);
            $this->assertSame(120000, $compaction->compactAfterTokens);
            $this->assertSame('llama_cpp/flash', $compaction->model);
            $this->assertSame('low', $compaction->thinkingLevel);

            // Provider overrides survived denormalization.
            $this->assertArrayHasKey('openai', $compaction->providerOverrides);
            $this->assertSame(80000, $compaction->providerOverrides['openai']['compact_after_tokens']);
            $this->assertSame('off', $compaction->providerOverrides['openai']['thinking_level']);

            // Model overrides survived denormalization.
            $this->assertArrayHasKey('openai/gpt-4.1', $compaction->modelOverrides);
            $this->assertSame(140000, $compaction->modelOverrides['openai/gpt-4.1']['compact_after_tokens']);
            $this->assertSame('high', $compaction->modelOverrides['openai/gpt-4.1']['thinking_level']);

            // Runtime resolution uses the denormalized overrides.
            $runtime = $compaction->resolveRuntimeSettings('openai/gpt-4.1');
            $this->assertSame(140000, $runtime->compactAfterTokens);
            $this->assertSame('high', $runtime->thinkingLevel);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
    }

    /**
     * Thesis: extractProviderId() returns empty string for bare model
     * references without a provider/ delimiter, preventing accidental
     * matching against provider override keys.
     *
     * A model like 'noproviderslash' must not match a provider override
     * keyed by 'noproviderslash' — overrides require the canonical
     * provider/model shape.
     */
    public function testBareModelStringDoesNotMatchProviderOverride(): void
    {
        $config = new CompactionConfig(
            providerOverrides: ['bare' => ['compact_after_tokens' => 1]],
        );

        $runtime = $config->resolveRuntimeSettings('noproviderslash');
        $this->assertSame(CompactionConfig::DEFAULT_COMPACT_AFTER_TOKENS, $runtime->compactAfterTokens);
    }
}
