<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\DTO\RuntimeConfigDTO;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: runtime.llm_concurrency is a typed positive-integer setting
 * (default 10), distinct from tools.execution.max_parallelism.
 */
#[CoversClass(RuntimeConfigDTO::class)]
final class RuntimeConfigDTOTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $defaultsDir;
    private AppConfigLoader $loader;
    private AppResourceLocator $resources;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('hatfield_runtime_llm_concurrency');
        $this->homeDir = $this->tmpDir.'/home';
        $this->defaultsDir = $this->tmpDir.'/config';
        mkdir($this->homeDir.'/.hatfield', 0755, true);
        mkdir($this->defaultsDir, 0755, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        $pathResolver = new \Ineersa\CodingAgent\Config\SettingsPathResolver(
            appRoot: '/app',
            homeDir: $this->homeDir,
        );
        $this->loader = new AppConfigLoader($pathResolver);
        $this->resources = new AppResourceLocator($this->tmpDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testDefaultLlmConcurrencyIsTenAndDistinctFromToolParallelism(): void
    {
        $this->writeDefaults($this->baseDefaults([
            'tools' => [
                'execution' => [
                    'default_mode' => 'sequential',
                    'max_parallelism' => 4,
                ],
            ],
        ]));

        $config = $this->buildConfig();

        $this->assertSame(RuntimeConfigDTO::DEFAULT_LLM_CONCURRENCY, $config->runtime->llmConcurrency);
        $this->assertSame(10, $config->runtime->llmConcurrency);
        $this->assertSame(4, $config->tools->execution->maxParallelism);
    }

    #[DataProvider('validConcurrencyProvider')]
    public function testConfiguredLlmConcurrencyIsAccepted(int $value): void
    {
        $this->writeDefaults($this->baseDefaults([
            'runtime' => ['llm_concurrency' => $value],
        ]));

        $config = $this->buildConfig();
        $this->assertSame($value, $config->runtime->llmConcurrency);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validConcurrencyProvider(): iterable
    {
        yield 'one' => [1];
        yield 'six' => [6];
        yield 'ten' => [10];
        yield 'large' => [64];
    }

    #[DataProvider('invalidConcurrencyProvider')]
    public function testInvalidLlmConcurrencyIsRejected(mixed $value): void
    {
        $this->writeDefaults($this->baseDefaults([
            'runtime' => ['llm_concurrency' => $value],
        ]));

        $this->expectException(ValidationFailedException::class);
        $this->buildConfig();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConcurrencyProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testNonIntegerLlmConcurrencyIsRejected(): void
    {
        $this->writeDefaults($this->baseDefaults([
            'runtime' => ['llm_concurrency' => 'six'],
        ]));

        $this->expectException(\Throwable::class);
        $this->buildConfig();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function baseDefaults(array $overrides = []): array
    {
        $base = [
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
                                'max_tokens' => 8192,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => false,
                                'cost' => [
                                    'input' => 0.0,
                                    'output' => 0.0,
                                    'cache_read' => 0.0,
                                    'cache_write' => 0.0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeDefaults(array $data): void
    {
        file_put_contents(
            $this->defaultsDir.'/hatfield.defaults.yaml',
            Yaml::dump($data),
        );
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

    private function createSerializer(): SerializerInterface
    {
        $reflectionExtractor = new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor();
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new \Symfony\Component\Serializer\Serializer(
            normalizers: [
                new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter()),
                    propertyAccessor: \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor(),
                    propertyTypeExtractor: $reflectionExtractor,
                ),
            ],
            encoders: [],
        );
    }

    private function createValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
