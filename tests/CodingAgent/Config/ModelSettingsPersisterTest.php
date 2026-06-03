<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for ModelSettingsPersister (write-only persistence).
 *
 * Requires kernel boot for SessionMetadataStore (final class, cannot be
 * mocked) and a real DB session entity.
 */
class ModelSettingsPersisterTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): \Ineersa\CodingAgent\Kernel
    {
        return new \Ineersa\CodingAgent\Kernel($options['environment'] ?? 'test', (bool) ($options['debug'] ?? false));
    }

    private string $tempDir;
    private string $homeDir;
    private ModelSettingsPersister $persister;
    private SessionMetadataStore $sessionMetaStore;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-persister-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);

        // Create initial home settings file so HomeSettingsWriter can read it
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            lockFactory: new LockFactory(new FlockStore()),
            entityManager: $this->entityManager,
        );
        $this->sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->sessionId = (string) $entity->id;

        $this->persister = new ModelSettingsPersister($homeWriter, $this->sessionMetaStore);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        self::ensureKernelShutdown();
        parent::tearDown();
        // Pop the exception handler that FrameworkBundle::boot() registered
        restore_exception_handler();
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

    // ──────────────────────────────────────────────
    //  Model persistence
    // ──────────────────────────────────────────────

    public function testPersistModelWritesToHomeAndSession(): void
    {
        $this->persister->persistModel('deepseek/deepseek-v4-flash', 'deepseek', 'deepseek-v4-flash', $this->sessionId);

        // Session metadata
        $meta = $this->sessionMetaStore->readSessionMetadata($this->sessionId);
        self::assertSame('deepseek/deepseek-v4-flash', $meta['model']);
        self::assertSame('deepseek', $meta['model_provider']);
        self::assertSame('deepseek-v4-flash', $meta['model_name']);

        // Home settings YAML
        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        self::assertNotFalse($homeContent);
        self::assertStringContainsString('default_model: deepseek/deepseek-v4-flash', (string) $homeContent);
    }

    // ──────────────────────────────────────────────
    //  Reasoning persistence
    // ──────────────────────────────────────────────

    public function testPersistReasoningWithValidLevelPersists(): void
    {
        $this->persister->persistReasoning('xhigh', $this->sessionId);

        // Session metadata
        $meta = $this->sessionMetaStore->readSessionMetadata($this->sessionId);
        self::assertSame('xhigh', $meta['reasoning']);

        // Home settings YAML
        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        self::assertNotFalse($homeContent);
        self::assertStringContainsString('default_reasoning: xhigh', (string) $homeContent);
    }

    public function testPersistReasoningWithInvalidLevelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reasoning level');

        $this->persister->persistReasoning('super-genius', $this->sessionId);
    }

    // ──────────────────────────────────────────────
    //  Favorites persistence
    // ──────────────────────────────────────────────

    public function testPersistFavoriteModelsWritesToHome(): void
    {
        $this->persister->persistFavoriteModels(['deepseek/deepseek-v4-pro', 'llama_cpp/flash']);

        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        self::assertNotFalse($homeContent);

        $parsed = Yaml::parse((string) $homeContent);
        self::assertIsArray($parsed);
        self::assertArrayHasKey('ai', $parsed);
        self::assertArrayHasKey('favorite_models', $parsed['ai']);
        self::assertSame(['deepseek/deepseek-v4-pro', 'llama_cpp/flash'], $parsed['ai']['favorite_models']);
    }
}
