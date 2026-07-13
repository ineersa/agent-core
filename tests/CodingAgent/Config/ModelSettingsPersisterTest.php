<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for ModelSettingsPersister (write-only persistence).
 *
 * Requires kernel boot for SessionMetadataStore (final class, cannot be
 * mocked) and a real DB session entity.  Uses {@see IsolatedKernelTestCase}
 * for per-class kernel boot; per-method temp directories isolate settings
 * files without requiring a fresh kernel per test.
 */
class ModelSettingsPersisterTest extends IsolatedKernelTestCase
{
    private string $tempDir;
    private string $homeDir;
    private ModelSettingsPersister $persister;
    private SessionMetadataStore $sessionMetaStore;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('hatfield-persister', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);

        // Create initial home settings file so HomeSettingsWriter can read it
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

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
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown(); // clears EM via IsolatedKernelTestCase
    }

    // ──────────────────────────────────────────────
    //  Model persistence
    // ──────────────────────────────────────────────

    public function testPersistModelWritesToHomeAndSession(): void
    {
        $this->persister->persistModel('deepseek/deepseek-v4-flash', 'deepseek', 'deepseek-v4-flash', $this->sessionId);

        // Session metadata
        $session = $this->sessionMetaStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('deepseek/deepseek-v4-flash', $session->model);
        $this->assertSame('deepseek', $session->modelProvider);
        $this->assertSame('deepseek-v4-flash', $session->modelName);

        // Home settings YAML
        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertNotFalse($homeContent);
        $this->assertStringContainsString('default_model: deepseek/deepseek-v4-flash', (string) $homeContent);
    }

    // ──────────────────────────────────────────────
    //  Reasoning persistence
    // ──────────────────────────────────────────────

    public function testPersistReasoningWithValidLevelPersists(): void
    {
        $this->persister->persistReasoning('xhigh', $this->sessionId);

        // Session metadata
        $session = $this->sessionMetaStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('xhigh', $session->reasoning);

        // Home settings YAML
        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertNotFalse($homeContent);
        $this->assertStringContainsString('default_reasoning: xhigh', (string) $homeContent);
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
        $this->assertNotFalse($homeContent);

        $parsed = Yaml::parse((string) $homeContent);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('ai', $parsed);
        $this->assertArrayHasKey('favorite_models', $parsed['ai']);
        $this->assertSame(['deepseek/deepseek-v4-pro', 'llama_cpp/flash'], $parsed['ai']['favorite_models']);
    }
}
