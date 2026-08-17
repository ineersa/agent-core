<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests that AppConfig rejects invalid ai.default_model at boot time.
 *
 * Uses the production fromContainer() factory through a controlled
 * AppConfigLoader so only the AI config section changes across tests.
 */
class AppConfigTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private AppConfigLoader $loader;
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
        $this->loader = new AppConfigLoader($pathResolver);
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
    //  Target section fail-fast hydration (prompts / agents / forks)
    // ──────────────────────────────────────────────
    //
    // Explicit malformed values must fail configuration load instead of
    // being silently dropped or replaced by a default. Omission keeps the
    // documented defaults.

    public function testTargetSectionsDefaultWhenOmitted(): void
    {
        $config = $this->buildConfig();

        $this->assertSame([], $config->prompts->paths);
        $this->assertTrue($config->agents->enabled);
        $this->assertSame(4, $config->agents->maxAgents);
        $this->assertSame([], $config->agents->paths);
        $this->assertNull($config->forks->model);
        $this->assertNull($config->forks->thinkingLevel);
    }

    public function testPromptsSectionWrongTypeFails(): void
    {
        $this->defaultsWith(['prompts' => 'not-a-list']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for prompts');

        $this->buildConfig();
    }

    public function testPromptsNonStringEntryFails(): void
    {
        $this->defaultsWith(['prompts' => ['ok.md', 123]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for prompts');

        $this->buildConfig();
    }

    public function testPromptsBlankEntryFails(): void
    {
        $this->defaultsWith(['prompts' => ['ok.md', '  ']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for prompts');

        $this->buildConfig();
    }

    public function testPromptsValidListHydrates(): void
    {
        $this->defaultsWith(['prompts' => ['a.md', 'b.md']]);

        $config = $this->buildConfig();

        $this->assertCount(2, $config->prompts->paths);
        $this->assertStringEndsWith('a.md', $config->prompts->paths[0]);
        $this->assertStringEndsWith('b.md', $config->prompts->paths[1]);
    }

    public function testAgentsSectionScalarFails(): void
    {
        $this->defaultsWith(['agents' => 5]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents');

        $this->buildConfig();
    }

    public function testAgentsEnabledWrongTypeFails(): void
    {
        $this->defaultsWith(['agents' => ['enabled' => 'yes']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.enabled');

        $this->buildConfig();
    }

    public function testAgentsPathsNonStringEntryFails(): void
    {
        $this->defaultsWith(['agents' => ['paths' => ['ok', 5]]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.paths');

        $this->buildConfig();
    }

    public function testAgentsMaxAgentsInvalidFails(): void
    {
        $this->defaultsWith(['agents' => ['max_agents' => 0]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.max_agents');

        $this->buildConfig();
    }

    public function testAgentsValidValuesHydrate(): void
    {
        $this->defaultsWith([
            'agents' => [
                'enabled' => false,
                'max_agents' => 6,
                'paths' => ['custom'],
                'subagent_excluded_tools' => ['settings'],
            ],
        ]);

        $config = $this->buildConfig();

        $this->assertFalse($config->agents->enabled);
        $this->assertSame(6, $config->agents->maxAgents);
        $this->assertCount(1, $config->agents->paths);
        $this->assertStringEndsWith('custom', $config->agents->paths[0]);
        $this->assertSame(['settings'], $config->agents->subagentExcludedTools);
    }

    public function testForksModelWrongTypeFails(): void
    {
        $this->defaultsWith(['forks' => ['model' => 5]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for forks.model');

        $this->buildConfig();
    }

    public function testForksValidValuesHydrate(): void
    {
        $this->defaultsWith([
            'forks' => [
                'model' => 'deepseek/deepseek-v4-pro',
                'thinking_level' => 'high',
            ],
        ]);

        $config = $this->buildConfig();

        $this->assertSame('deepseek/deepseek-v4-pro', $config->forks->model);
        $this->assertSame('high', $config->forks->thinkingLevel);
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

    /**
     * Write defaults with the base sections plus the given top-level
     * section overrides (prompts / agents / forks).
     *
     * @param array<string, mixed> $overrides
     */
    private function defaultsWith(array $overrides): void
    {
        $this->writeDefaults(array_merge([
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
        ], $overrides));
    }

    private function buildConfig(): AppConfig
    {
        return AppConfig::fromContainer(
            $this->loader,
            $this->resources,
            $this->createSerializer(),
            $this->tmpDir,
            $this->createValidator(),
        );
    }

    private function createValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
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
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter()),
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
