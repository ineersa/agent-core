<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Coordinator-level tests for ModelSelectionService.
 *
 * Tests the integration between ModelResolver + ModelSettingsPersister
 * through the ModelSelectionService facade: validation, persistence,
 * and favorites with in-process cache consistency.
 *
 * Pure resolution logic is tested in ModelResolverTest.
 */
class ModelSelectionServiceTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): \Ineersa\CodingAgent\Kernel
    {
        return new \Ineersa\CodingAgent\Kernel($options['environment'] ?? 'test', (bool) ($options['debug'] ?? false));
    }

    private string $tempDir;
    private string $homeDir;
    private ModelSelectionService $service;
    private SessionMetadataStore $sessionMetaStore;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    /** Session ID from auto-increment entity created in setUp. */
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-model-selection-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);

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

        $this->service = $this->buildService([]);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        self::ensureKernelShutdown();
        parent::tearDown();
        // Pop the exception handler that FrameworkBundle::boot() registered
        // during kernel boot/shutdown. Parent tearDown calls
        // ensureKernelShutdown() which may re-boot and re-register, so
        // this must run after parent::tearDown().
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

    private function buildService(array $aiData): ModelSelectionService
    {
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $resolver = new ModelResolver($appConfig, $this->sessionMetaStore);
        $persister = new ModelSettingsPersister($homeWriter, $this->sessionMetaStore);

        return new ModelSelectionService($appConfig, $resolver, $persister);
    }

    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = ['tui' => ['theme' => 'cyberpunk']];
        if ([] !== $aiData) {
            $raw['ai'] = $aiData;
        }

        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: TuiConfig::fromArray((array) ($raw['tui'] ?? [])),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    private function writeSessionMetadata(string $sessionId, array $meta): string
    {
        $id = (int) $sessionId;
        $entity = 0 !== $id
            ? $this->entityManager->find(HatfieldSession::class, $id)
            : null;

        if (null === $entity) {
            $entity = new HatfieldSession();
            $entity->cwd = $this->tempDir.'/project';
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        }

        if (isset($meta['model']) && \is_string($meta['model'])) {
            $entity->model = $meta['model'];
        }
        if (isset($meta['model_provider']) && \is_string($meta['model_provider'])) {
            $entity->modelProvider = $meta['model_provider'];
        }
        if (isset($meta['model_name']) && \is_string($meta['model_name'])) {
            $entity->modelName = $meta['model_name'];
        }
        if (isset($meta['reasoning']) && \is_string($meta['reasoning'])) {
            $entity->reasoning = $meta['reasoning'];
        }

        $this->entityManager->flush();

        return (string) $entity->id;
    }

    private function readSessionMetadata(string $sessionId): array
    {
        return $this->sessionMetaStore->readSessionMetadata($sessionId);
    }

    private function homeSettingsPath(): string
    {
        return $this->homeDir.'/.hatfield/settings.yaml';
    }

    private function standardAiData(): array
    {
        return [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
                    'completions_path' => '/chat/completions',
                    'models' => [
                        'deepseek-v4-pro' => [
                            'id' => 'deepseek-v4-pro',
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                'xhigh' => 'max',
                            ],
                        ],
                        'deepseek-v4-flash' => [
                            'id' => 'deepseek-v4-flash',
                            'name' => 'DeepSeek V4 Flash',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://192.168.2.38:8052/v1',
                    'models' => [
                        'flash' => [
                            'id' => 'flash',
                            'name' => 'Flash',
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text', 'image'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  Session metadata resolution (tier 2)
    // ──────────────────────────────────────────────

    public function testSessionMetadataWinsOverDefaultAndFirstAvailable(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash']);

        $result = $service->resolveInitialModel(null, $this->sessionId);

        self::assertNotNull($result);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('flash', $result->modelName);
    }

    public function testNewSessionDoesNotReadMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());

        // Empty session ID triggers fallthrough to default/first-available
        $result = $service->resolveInitialModel(null, '');

        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    public function testSessionMetadataWithCorruptModelIgnored(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'nonexistent/model']);

        $result = $service->resolveInitialModel(null, $this->sessionId);

        // Falls through to default model
        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    public function testSessionReasoningWinsOverDefault(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['reasoning' => 'high']);

        $result = $service->resolveInitialReasoning(null, $this->sessionId);

        self::assertSame('high', $result);
    }

    public function testGetCurrentModelResolvesFromSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash']);

        $result = $service->getCurrentModel($this->sessionId);

        self::assertNotNull($result);
        self::assertSame('llama_cpp/flash', $result->toString());
    }

    public function testGetCurrentReasoningResolvesFromSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['reasoning' => 'high']);

        $result = $service->getCurrentReasoning($this->sessionId);

        self::assertSame('high', $result);
    }

    public function testChangeReasoningPreservesSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash', 'reasoning' => 'high']);

        $service->changeReasoning('low', $this->sessionId);

        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('low', $meta['reasoning']);
        self::assertSame('llama_cpp/flash', $meta['model']);
    }

    // ──────────────────────────────────────────────
    //  Favorites
    // ──────────────────────────────────────────────

    public function testGetFavoriteModelsFiltersUnavailable(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'nonexistent/model'];
        $service = $this->buildService($aiData);

        $favs = $service->getFavoriteModels();

        self::assertCount(1, $favs);
        self::assertSame('deepseek/deepseek-v4-pro', $favs[0]);
    }

    // ──────────────────────────────────────────────
    //  Model persistence
    // ──────────────────────────────────────────────

    public function testChangeModelPersistsToHomeAndSession(): void
    {
        $service = $this->buildService($this->standardAiData());
        $ref = new AiModelReference('deepseek', 'deepseek-v4-flash');

        $service->changeModel($ref, $this->sessionId);

        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('deepseek/deepseek-v4-flash', $meta['model']);
        self::assertSame('deepseek', $meta['model_provider']);
        self::assertSame('deepseek-v4-flash', $meta['model_name']);
    }

    public function testChangeModelThrowsOnUnavailableModel(): void
    {
        $service = $this->buildService($this->standardAiData());
        $ref = new AiModelReference('mystery', 'ghost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $service->changeModel($ref, $this->sessionId);
    }

    // ──────────────────────────────────────────────
    //  Reasoning persistence
    // ──────────────────────────────────────────────

    public function testChangeReasoningPersistsToHomeAndSession(): void
    {
        $service = $this->buildService($this->standardAiData());

        $service->changeReasoning('xhigh', $this->sessionId);

        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('xhigh', $meta['reasoning']);
    }

    public function testChangeReasoningThrowsOnInvalidLevel(): void
    {
        $service = $this->buildService($this->standardAiData());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reasoning level');

        $service->changeReasoning('super-genius', $this->sessionId);
    }

    // ──────────────────────────────────────────────
    //  Favorites
    // ──────────────────────────────────────────────

    public function testGetFavoriteModelsReturnsConfiguredFavorites(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $favs = $service->getFavoriteModels();

        self::assertCount(2, $favs);
    }

    public function testToggleFavoriteAddAndRemoveWithImmediateVisibility(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        // Before: one favorite
        self::assertCount(1, $service->getFavoriteModels());
        self::assertTrue($service->isFavorite('deepseek/deepseek-v4-pro'));

        // Toggle add
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // After: two favorites visible immediately
        self::assertCount(2, $service->getFavoriteModels());
        self::assertTrue($service->isFavorite('llama_cpp/flash'));

        // Toggle remove
        $service->toggleFavorite(new AiModelReference('deepseek', 'deepseek-v4-pro'));

        // After: one favorite, gone immediately
        $favs = $service->getFavoriteModels();
        self::assertCount(1, $favs);
        self::assertFalse($service->isFavorite('deepseek/deepseek-v4-pro'));
    }

    public function testToggleFavoriteThrowsOnUnavailableModel(): void
    {
        $service = $this->buildService($this->standardAiData());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $service->toggleFavorite(new AiModelReference('mystery', 'ghost'));
    }

    // ──────────────────────────────────────────────
    //  Ordered models
    // ──────────────────────────────────────────────

    public function testGetOrderedModelsPutsFavoritesFirst(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $ordered = $service->getOrderedModels();

        self::assertCount(3, $ordered);
        self::assertSame('llama_cpp', $ordered[0]->providerId);
        self::assertSame('flash', $ordered[0]->modelName);
    }

    // ──────────────────────────────────────────────
    //  Cycling
    // ──────────────────────────────────────────────

    public function testCycleFavoriteModelReturnsNextAndWraps(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        // Current is default (deepseek/deepseek-v4-pro, first favorite) → next is second
        $next = $service->cycleFavoriteModel($this->sessionId);
        self::assertNotNull($next);
        self::assertSame('llama_cpp', $next->providerId);
        self::assertSame('flash', $next->modelName);

        // Current is now second favorite → wrap to first
        $next = $service->cycleFavoriteModel($this->sessionId);
        self::assertNotNull($next);
        self::assertSame('deepseek', $next->providerId);
        self::assertSame('deepseek-v4-pro', $next->modelName);
    }

    public function testCycleFavoriteModelReturnsNullWhenNoFavorites(): void
    {
        $service = $this->buildService($this->standardAiData());

        self::assertNull($service->cycleFavoriteModel($this->sessionId));
    }

    // ──────────────────────────────────────────────
    //  Reasoning-for-model cycling
    // ──────────────────────────────────────────────

    public function testCycleReasoningForCurrentModelSuccess(): void
    {
        $service = $this->buildService($this->standardAiData());

        $result = $service->cycleReasoningForCurrentModel($this->sessionId);

        self::assertNotNull($result);
        self::assertSame('high', $result);

        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('high', $meta['reasoning']);
    }

    public function testCycleReasoningForCurrentModelReturnsNullWhenUnsupported(): void
    {
        $service = $this->buildService($this->standardAiData());
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $result = $service->cycleReasoningForCurrentModel($this->sessionId);

        self::assertNull($result);
    }

    // ──────────────────────────────────────────────
    //  Persistence across restart (EDITOR / AI-14)
    // ──────────────────────────────────────────────

    public function testModelChangePersistsToHomeSettingsForRestart(): void
    {
        $aiData = $this->standardAiData();
        $service = $this->buildService($aiData);

        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        self::assertNotFalse($homeContent);
        self::assertStringContainsString('default_model: llama_cpp/flash', (string) $homeContent);
    }
}
