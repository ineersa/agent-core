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
 * Tests AppConfig hydration via the production fromContainer() factory
 * through a controlled AppConfigLoader: ai.default_model validation at
 * boot time and fail-fast handling of malformed prompts/agents/forks
 * sections (detailed per-field cases live in the DTO tests).
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

        // Base defaults drive testing; per-test sections are overlaid via defaultsWith().
        $this->defaultsWith([]);
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
    //  Dangling default_model — falls back
    // ──────────────────────────────────────────────

    public function testDanglingDefaultModelFallsBackToFirstAvailable(): void
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

        $config = $this->buildConfig();

        $this->assertSame('openai/gpt-5', $config->staleDefaultModel);
        $this->assertSame('deepseek/deepseek-v4-pro', $config->ai?->defaultModel);
        $this->assertNotNull($config->catalog);
        $this->assertTrue($config->catalog->isAvailable('deepseek/deepseek-v4-pro'));
    }

    // ──────────────────────────────────────────────
    //  Dangling default_model with no providers at all
    // ──────────────────────────────────────────────

    public function testDanglingDefaultModelWhenNoProvidersConfiguredBoots(): void
    {
        $this->writeDefaults([
            'tui' => ['theme' => 'cyberpunk', 'theme_paths' => ['/app/config/themes']],
            'sessions' => ['path' => '.hatfield/sessions'],
            'logging' => ['path' => '.hatfield/logs', 'level' => 'info', 'max_files' => 14],
            'ai' => [
                'default_model' => 'openai/gpt-5',
            ],
        ]);

        $config = $this->buildConfig();

        // No fallback available — keep configured value; AgentCommand gate owns the fail-fast.
        $this->assertSame('openai/gpt-5', $config->staleDefaultModel);
        $this->assertSame('openai/gpt-5', $config->ai?->defaultModel);
    }

    public function testAvailableDefaultModelLeavesStaleNull(): void
    {
        $config = $this->buildConfig();

        $this->assertNull($config->staleDefaultModel);
        $this->assertSame('deepseek/deepseek-v4-pro', $config->ai?->defaultModel);
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
    //  Target sections (prompts / agents / forks)
    // ──────────────────────────────────────────────
    //
    // Explicit malformed values fail configuration load; omission keeps
    // the documented defaults. Detailed per-field failure cases live in
    // PromptsConfigTest / AgentsConfigTest.

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

    public function testTargetSectionsHydrateValidValues(): void
    {
        $this->defaultsWith([
            'prompts' => ['a.md', 'b.md'],
            'agents' => [
                'enabled' => false,
                'max_agents' => 6,
                'paths' => ['custom'],
                'subagent_excluded_tools' => ['settings'],
            ],
            'forks' => ['model' => 'deepseek/deepseek-v4-pro', 'thinking_level' => 'high'],
        ]);

        $config = $this->buildConfig();

        $this->assertCount(2, $config->prompts->paths);
        $this->assertStringEndsWith('a.md', $config->prompts->paths[0]);
        $this->assertFalse($config->agents->enabled);
        $this->assertSame(6, $config->agents->maxAgents);
        $this->assertCount(1, $config->agents->paths);
        $this->assertStringEndsWith('custom', $config->agents->paths[0]);
        $this->assertSame(['settings'], $config->agents->subagentExcludedTools);
        $this->assertSame('deepseek/deepseek-v4-pro', $config->forks->model);
        $this->assertSame('high', $config->forks->thinkingLevel);
    }

    public function testEmptySectionArraysRemainValidDefaults(): void
    {
        // Empty YAML mapping and empty list both decode to []; shape is
        // indistinguishable, so empty [] stays a valid empty/default section.
        $this->defaultsWith([
            'prompts' => [],
            'agents' => ['paths' => []],
            'forks' => [],
        ]);

        $config = $this->buildConfig();

        $this->assertSame([], $config->prompts->paths);
        $this->assertTrue($config->agents->enabled);
        $this->assertSame([], $config->agents->paths);
        $this->assertNull($config->forks->model);
        $this->assertNull($config->forks->thinkingLevel);
    }

    public function testForksNullAndBlankUnsetValuesLoadAsNull(): void
    {
        $this->defaultsWith([
            'forks' => ['model' => null, 'thinking_level' => '  '],
        ]);

        $config = $this->buildConfig();

        $this->assertNull($config->forks->model);
        $this->assertNull($config->forks->thinkingLevel);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedTargetSectionCases(): iterable
    {
        yield 'prompts wrong type' => [['prompts' => 'not-a-list'], 'Invalid value for prompts: expected list of strings, got string'];
        yield 'prompts non-string entry' => [['prompts' => ['ok.md', 123]], 'Invalid value for prompts[1]'];
        yield 'prompts associative map' => [['prompts' => ['a.md' => 'x']], 'Invalid value for prompts: expected list of strings, got associative array'];
        yield 'prompts explicit null' => [['prompts' => null], 'Invalid value for prompts: expected list of strings, got null'];
        yield 'agents wrong type' => [['agents' => 5], 'Invalid value for agents: expected mapping, got int'];
        yield 'agents sequential list' => [['agents' => ['a', 'b']], 'Invalid value for agents: expected mapping, got list'];
        yield 'agents explicit null' => [['agents' => null], 'Invalid value for agents: expected mapping, got null'];
        yield 'agents unknown key' => [['agents' => ['bogus' => 1]], 'Invalid key for agents: "bogus" is not supported'];
        yield 'agents.paths non-string entry' => [['agents' => ['paths' => ['ok', 5]]], 'Invalid value for agents.paths[1]'];
        yield 'agents.paths associative map' => [['agents' => ['paths' => ['a' => 'x.md']]], 'Invalid value for agents.paths: expected list of strings, got associative array'];
        yield 'agents.extensions unknown key' => [['agents' => ['extensions' => ['bogus' => 1]]], 'Invalid key for agents.extensions: "bogus" is not supported'];
        yield 'agents.extensions explicit null' => [['agents' => ['extensions' => null]], 'Invalid value for agents.extensions: expected mapping, got null'];
        yield 'forks wrong type' => [['forks' => 5], 'Invalid value for forks: expected mapping, got int'];
        yield 'forks sequential list' => [['forks' => ['a', 'b']], 'Invalid value for forks: expected mapping, got list'];
        yield 'forks explicit null' => [['forks' => null], 'Invalid value for forks: expected mapping, got null'];
        yield 'forks unknown key' => [['forks' => ['bogus' => 1]], 'Invalid key for forks: "bogus" is not supported'];
        yield 'forks.model wrong type' => [['forks' => ['model' => 5]], 'Invalid value for forks.model'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTargetSectionCases')]
    public function testTargetSectionMalformedInputFails(array $overrides, string $messageFragment): void
    {
        $this->defaultsWith($overrides);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messageFragment);

        $this->buildConfig();
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
        $this->writeDefaults(array_merge(self::baseDefaults(), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseDefaults(): array
    {
        return [
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
        ];
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
