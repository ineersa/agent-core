<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;

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
        $this->tmpDir = sys_get_temp_dir().'/hatfield_appcfg_'.bin2hex(random_bytes(8));
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
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  Valid default_model
    // ──────────────────────────────────────────────

    public function testValidDefaultModelBoots(): void
    {
        $config = $this->buildConfig();

        self::assertNotNull($config->ai);
        self::assertSame('deepseek/deepseek-v4-pro', $config->ai->defaultModel);
        self::assertNotNull($config->catalog);
        self::assertTrue($config->catalog->isAvailable('deepseek/deepseek-v4-pro'));
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

        self::assertNotNull($config->ai);
        self::assertNull($config->ai->defaultModel);
        self::assertNotNull($config->catalog);
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
            $this->loader,
            $this->resources,
            $this->createSerializer(),
            $this->tmpDir,
        );
    }

    private function createSerializer(): SerializerInterface
    {
        $reflectionExtractor = new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor();

        $denormalizers = [
            // ObjectNormalizer with ReflectionExtractor for reading typed public
            // properties of config DTOs (TuiConfig, LoggingConfig, etc.).
            new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer(
                classMetadataFactory: null,
                nameConverter: null,
                propertyAccessor: \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor(),
                propertyTypeExtractor: $reflectionExtractor,
            ),
        ];

        return new \Symfony\Component\Serializer\Serializer(
            normalizers: $denormalizers,
            encoders: [],
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
