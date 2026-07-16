<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Tests that AppConfig rejects invalid ai.default_model at boot time.
 *
 * Uses the production fromContainer() factory through a controlled
 * SettingsResolver so only the AI config section changes across tests.
 */
class AppConfigTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private SettingsResolver $resolver;
    private AppResourceLocator $resources;
    private string $defaultsDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('hatfield_appcfg');
        $this->homeDir = $this->tmpDir.'/home';
        $this->defaultsDir = $this->tmpDir.'/config';

        mkdir($this->homeDir, 0755, true);
        mkdir($this->homeDir.'/.hatfield', 0755, true);
        mkdir($this->defaultsDir, 0755, true);

        // Home settings with no AI section — defaults file drives testing.
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        $pathResolver = new \Ineersa\CodingAgent\Config\SettingsPathResolver(
            appRoot: '/app',
            homeDir: $this->homeDir,
        );
        $this->resolver = new SettingsResolver($pathResolver);
        $this->resources = new AppResourceLocator($this->tmpDir);

        // Write a base defaults file that will be overwritten per test.
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'deepseek/deepseek-v4-pro',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'models' => [
                            'deepseek-v4-pro' => [
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 131072,
                                'max_tokens' => 131072,
                                'input' => ['text'],
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  Valid default_model
    // ──────────────────────────────────────────────

    public function testValidDefaultModelBoots(): void
    {
        $config = $this->buildConfig();

        $this->assertNotNull($config->ai);
        $this->assertSame('deepseek/deepseek-v4-pro', $config->ai->defaultModel);
        $this->assertNotNull($config->catalog);
        $this->assertTrue($config->catalog->isAvailable('deepseek/deepseek-v4-pro'));
    }

    // ──────────────────────────────────────────────
    //  No default_model — loads cleanly
    // ──────────────────────────────────────────────

    public function testNoDefaultModelBoots(): void
    {
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'models' => [
                            'deepseek-v4-pro' => [
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 131072,
                                'max_tokens' => 131072,
                                'input' => ['text'],
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $config = $this->buildConfig();

        $this->assertNotNull($config->ai);
        $this->assertNull($config->ai->defaultModel);
        $this->assertNotNull($config->catalog);
    }

    // ──────────────────────────────────────────────
    //  Malformed default_model — throws
    // ──────────────────────────────────────────────

    public function testMalformedDefaultModelThrows(): void
    {
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'not-a-valid-format',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'models' => [
                            'deepseek-v4-pro' => [
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 131072,
                                'max_tokens' => 131072,
                                'input' => ['text'],
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid');

        $this->buildConfig();
    }

    // ──────────────────────────────────────────────
    //  Dangling default_model — throws
    // ──────────────────────────────────────────────

    public function testDanglingDefaultModelThrows(): void
    {
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'openai/gpt-5',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'models' => [
                            'deepseek-v4-pro' => [
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 131072,
                                'max_tokens' => 131072,
                                'input' => ['text'],
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not available');

        $this->buildConfig();
    }

    // ──────────────────────────────────────────────
    //  Dangling default_model with no providers at all
    // ──────────────────────────────────────────────

    public function testDanglingDefaultModelWhenNoProvidersConfiguredThrows(): void
    {
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'openai/gpt-5',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No enabled providers or models');

        $this->buildConfig();
    }

    // ──────────────────────────────────────────────
    //  tui.transcript config hydration
    // ──────────────────────────────────────────────

    public function testTranscriptConfigDefaults(): void
    {
        $config = $this->buildConfig();

        // theme_paths uses #[SerializedName('theme_paths')]; this assertion
        // proves the test serializer correctly reads SerializedName attributes.
        $this->assertSame(['/app/config/themes'], $config->tui->themePaths);

        $transcript = $config->tui->transcript;
        $this->assertTrue($transcript->thinking->visible);
        $this->assertSame('dim_italic', $transcript->thinking->style);
        $this->assertFalse($transcript->previews->expandedByDefault);
        $this->assertSame(8, $transcript->previews->toolResultLines);
        $this->assertSame(20, $transcript->previews->diffLines);
    }

    public function testTranscriptConfigHydratesFromYaml(): void
    {
        $this->writeDefaults([
            'tui' => [
                'theme' => 'cyberpunk',
                'theme_paths' => ['/app/config/themes'],
                'transcript' => [
                    'thinking' => [
                        'visible' => false,
                        'style' => 'dim',
                    ],
                    'previews' => [
                        'expanded_by_default' => true,
                        'tool_result_lines' => 12,
                        'diff_lines' => 30,
                    ],
                ],
            ],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'deepseek/deepseek-v4-pro',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'models' => [
                            'deepseek-v4-pro' => [
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 131072,
                                'max_tokens' => 131072,
                                'input' => ['text'],
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $config = $this->buildConfig();

        $transcript = $config->tui->transcript;
        $this->assertFalse($transcript->thinking->visible);
        $this->assertSame('dim', $transcript->thinking->style);
        $this->assertTrue($transcript->previews->expandedByDefault);
        $this->assertSame(12, $transcript->previews->toolResultLines);
        $this->assertSame(30, $transcript->previews->diffLines);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function writeDefaults(array $data): void
    {
        file_put_contents(
            $this->defaultsDir.'/hatfield.defaults.yaml',
            \Symfony\Component\Yaml\Yaml::dump($data),
        );
    }

    private function buildConfig(): AppConfig
    {
        return AppConfig::fromContainer(
            $this->resolver,
            $this->resources,
            $this->createSerializer(),
            $this->tmpDir,
        );
    }

    private function createSerializer(): SerializerInterface
    {
        $reflectionExtractor = new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor();
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        $denormalizers = [
            // ObjectNormalizer with ClassMetadataFactory + MetadataAwareNameConverter
            // reads #[SerializedName] attributes from config DTOs.
            new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyAccessor: \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor(),
                propertyTypeExtractor: $reflectionExtractor,
            ),
        ];

        return new \Symfony\Component\Serializer\Serializer(
            normalizers: $denormalizers,
            encoders: [],
        );
    }
}
