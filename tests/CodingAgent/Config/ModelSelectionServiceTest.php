<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tests\TestCase\EntityManagerHelper;
use PHPUnit\Framework\TestCase;

class ModelSelectionServiceTest extends TestCase
{
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

        // Create an empty home settings file so HomeSettingsWriter can read/write it
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->entityManager = EntityManagerHelper::createInMemorySqlite();
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

        // Create a session entity for test metadata (auto-increment ID).
        // No public_id column — the integer primary key cast to string
        // is the session identifier.
        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->sessionId = (string) $entity->id;

        // Create a default AppConfig (no AI section — tests will call buildService() explicitly)
        $this->service = $this->buildService([]);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
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

    /**
     * Build a ModelSelectionService with a specific AI config.
     *
     * @param array<string, mixed> $aiData AI config section (or empty for no AI)
     */
    private function buildService(array $aiData): ModelSelectionService
    {
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);

        return new ModelSelectionService($appConfig, $homeWriter, $this->sessionMetaStore);
    }

    /**
     * Create an AppConfig from raw config data with the given AI section.
     * Uses the public value-object constructor — no test-only production code.
     */
    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = [
            'tui' => ['theme' => 'cyberpunk'],
        ];
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

    /**
     * Create a session entity with auto-increment ID and apply metadata.
     *
     * No public_id column — the integer primary key is the canonical
     * identifier and its string form is the external session ID.
     * Returns the session ID as a numeric string for use in assertions
     * and metadata lookups.
     *
     * If $sessionId is a numeric string of an existing entity, updates it.
     * If empty or non-numeric, creates a new entity to obtain an ID.
     */
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
        if (isset($meta['prompt']) && \is_string($meta['prompt'])) {
            $entity->prompt = $meta['prompt'];
        }

        $this->entityManager->flush();

        return (string) $entity->id;
    }

    /**
     * Read session metadata from the database.
     */
    private function readSessionMetadata(string $sessionId): array
    {
        return $this->sessionMetaStore->readSessionMetadata($sessionId);
    }

    private function homeSettingsPath(): string
    {
        return $this->homeDir.'/.hatfield/settings.yaml';
    }

    // ──────────────────────────────────────────────
    //  Helpers for standard AI configs
    // ──────────────────────────────────────────────

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
    //  Model resolution priority chain
    // ──────────────────────────────────────────────

    public function testExplicitModelWinsOverAllOtherPriorities(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash']);

        $result = $service->resolveInitialModel('deepseek/deepseek-v4-pro', $this->sessionId);

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testSessionMetadataWinsOverDefaultAndFirstAvailable(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash']);

        $result = $service->resolveInitialModel(null, $this->sessionId);

        self::assertNotNull($result);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('flash', $result->modelName);
    }

    public function testDefaultModelWinsOverFirstAvailable(): void
    {
        $service = $this->buildService($this->standardAiData());

        $result = $service->resolveInitialModel(null, '');

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testFirstAvailableModelUsedWhenNoDefault(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_model']);
        $service = $this->buildService($aiData);

        $result = $service->resolveInitialModel(null, '');

        // First available is the first model in the first enabled provider
        self::assertNotNull($result);
    }

    public function testReturnsNullWhenNoModelsConfigured(): void
    {
        $service = $this->buildService([]);

        $result = $service->resolveInitialModel(null, '');

        self::assertNull($result);
    }

    public function testExplicitModelIgnoredWhenUnavailable(): void
    {
        $service = $this->buildService($this->standardAiData());

        $result = $service->resolveInitialModel('unknown/model', '');

        // Falls through to default (deepseek/deepseek-v4-pro)
        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testNewSessionDoesNotReadMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());

        $result = $service->resolveInitialModel(null, '');

        // Should use default, not try to read metadata
        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution priority chain
    // ──────────────────────────────────────────────

    public function testExplicitReasoningWinsOverMetadataAndDefault(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['reasoning' => 'low']);

        $result = $service->resolveInitialReasoning('xhigh', $this->sessionId);

        self::assertSame('xhigh', $result);
    }

    public function testSessionReasoningWinsOverDefault(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['reasoning' => 'xhigh']);

        $result = $service->resolveInitialReasoning(null, $this->sessionId);

        self::assertSame('xhigh', $result);
    }

    public function testDefaultReasoningUsedWhenNoExplicitOrSession(): void
    {
        $service = $this->buildService($this->standardAiData());

        $result = $service->resolveInitialReasoning(null, '');

        self::assertSame('medium', $result);
    }

    public function testReasoningFallsBackToMedium(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_reasoning']);
        $service = $this->buildService($aiData);

        $result = $service->resolveInitialReasoning(null, '');

        self::assertSame('medium', $result);
    }

    // ──────────────────────────────────────────────
    //  Model persistence
    // ──────────────────────────────────────────────

    public function testChangeModelPersistsToHomeAndSession(): void
    {
        $service = $this->buildService($this->standardAiData());
        $ref = new AiModelReference('deepseek', 'deepseek-v4-flash');

        $service->changeModel($ref, $this->sessionId);

        // Check session metadata
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

        // Check session metadata
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
    //  getAvailableModels
    // ──────────────────────────────────────────────

    public function testGetAvailableModelsReturnsAllEnabledProviderModels(): void
    {
        $service = $this->buildService($this->standardAiData());

        $models = $service->getAvailableModels();

        self::assertCount(3, $models);
    }

    // ──────────────────────────────────────────────
    //  Edge cases
    // ──────────────────────────────────────────────

    public function testSessionMetadataWithCorruptModelIgnored(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Write session metadata with a model that doesn't exist
        $this->writeSessionMetadata($this->sessionId, ['model' => 'garbage/invalid']);

        $result = $service->resolveInitialModel(null, $this->sessionId);

        // Should fall through to default
        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testChangeReasoningPreservesSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Pre-populate session metadata
        $this->writeSessionMetadata($this->sessionId, [
            'session_id' => $this->sessionId,
            'run_id' => $this->sessionId,
            'cwd' => '/some/path',
            'model' => 'deepseek/deepseek-v4-pro',
        ]);

        $service->changeReasoning('high', $this->sessionId);

        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('high', $meta['reasoning']);
        self::assertSame($this->sessionId, $meta['session_id']);
        self::assertSame('deepseek/deepseek-v4-pro', $meta['model']);
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
        self::assertSame('deepseek/deepseek-v4-pro', $favs[0]);
        self::assertSame('llama_cpp/flash', $favs[1]);
    }

    public function testGetFavoriteModelsFiltersUnavailable(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'nonexistent/ghost'];
        $service = $this->buildService($aiData);

        $favs = $service->getFavoriteModels();

        self::assertCount(1, $favs);
        self::assertSame('deepseek/deepseek-v4-pro', $favs[0]);
    }

    public function testGetOrderedModelsPutsFavoritesFirst(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $ordered = $service->getOrderedModels();

        self::assertCount(3, $ordered);
        // First should be the favorite
        self::assertSame('llama_cpp', $ordered[0]->providerId);
        self::assertSame('flash', $ordered[0]->modelName);
    }

    public function testIsFavoriteReturnsTrueForFavoritedModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        self::assertTrue($service->isFavorite('deepseek/deepseek-v4-pro'));
        self::assertFalse($service->isFavorite('llama_cpp/flash'));
    }

    public function testToggleFavoriteAddsModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // Verify persisted to home settings
        $homeContent = file_get_contents($this->homeSettingsPath());
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $homeContent);
        self::assertStringContainsString('llama_cpp/flash', $homeContent);
    }

    public function testToggleFavoriteRemovesModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $service->toggleFavorite(new AiModelReference('deepseek', 'deepseek-v4-pro'));

        $homeContent = file_get_contents($this->homeSettingsPath());
        self::assertStringNotContainsString('deepseek/deepseek-v4-pro', $homeContent);
        self::assertStringContainsString('llama_cpp/flash', $homeContent);
    }

    public function testToggleFavoriteThrowsOnUnavailableModel(): void
    {
        $service = $this->buildService($this->standardAiData());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $service->toggleFavorite(new AiModelReference('mystery', 'ghost'));
    }

    // ──────────────────────────────────────────────
    //  Immediate favorite visibility (regression: cache consistency)
    // ──────────────────────────────────────────────

    public function testToggleFavoriteAddIsImmediatelyVisibleInGetFavoriteModels(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        // Before: only one favorite
        self::assertCount(1, $service->getFavoriteModels());

        // Toggle add
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // After: both favorites visible without rebuilding AppConfig
        $favs = $service->getFavoriteModels();
        self::assertCount(2, $favs);
        self::assertContains('llama_cpp/flash', $favs);
        self::assertContains('deepseek/deepseek-v4-pro', $favs);
    }

    public function testToggleFavoriteAddIsImmediatelyVisibleInIsFavorite(): void
    {
        $service = $this->buildService($this->standardAiData());

        // Before: not a favorite
        self::assertFalse($service->isFavorite('llama_cpp/flash'));

        // Toggle add
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // After: isFavorite returns true immediately
        self::assertTrue($service->isFavorite('llama_cpp/flash'));
    }

    public function testToggleFavoriteAddIsImmediatelyVisibleInGetOrderedModels(): void
    {
        $service = $this->buildService($this->standardAiData());

        // Toggle add a model that was previously not a favorite
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        $ordered = $service->getOrderedModels();

        self::assertCount(3, $ordered);
        // The newly favorited model should be first
        self::assertSame('llama_cpp', $ordered[0]->providerId);
        self::assertSame('flash', $ordered[0]->modelName);
    }

    public function testToggleFavoriteAddIsImmediatelyVisibleInCycleFavoriteModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        // Add a second favorite
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // Current model is default: deepseek/deepseek-v4-pro (first favorite)
        // Cycling should go to the newly added second favorite
        $next = $service->cycleFavoriteModel($this->sessionId);

        self::assertNotNull($next);
        self::assertSame('llama_cpp', $next->providerId);
        self::assertSame('flash', $next->modelName);
    }

    public function testToggleFavoriteRemoveIsImmediatelyVisible(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        // Before: two favorites
        self::assertCount(2, $service->getFavoriteModels());
        self::assertTrue($service->isFavorite('llama_cpp/flash'));

        // Toggle remove
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        // After: only one favorite, removed one gone immediately
        $favs = $service->getFavoriteModels();
        self::assertCount(1, $favs);
        self::assertNotContains('llama_cpp/flash', $favs);
        self::assertFalse($service->isFavorite('llama_cpp/flash'));
    }

    public function testToggleFavoriteRemoveAffectsOrderedModelsImmediately(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['llama_cpp/flash', 'deepseek/deepseek-v4-pro'];
        $service = $this->buildService($aiData);

        // Remove the first favorite
        $service->toggleFavorite(new AiModelReference('llama_cpp', 'flash'));

        $ordered = $service->getOrderedModels();

        // The remaining favorite should now be first
        self::assertCount(3, $ordered);
        self::assertSame('deepseek', $ordered[0]->providerId);
        self::assertSame('deepseek-v4-pro', $ordered[0]->modelName);
    }

    // ──────────────────────────────────────────────
    //  Cycling
    // ──────────────────────────────────────────────

    public function testCycleFavoriteModelReturnsNextFavorite(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $next = $service->cycleFavoriteModel($this->sessionId);

        self::assertNotNull($next);
        // Current defaults to default_model (deepseek/deepseek-v4-pro), which is first in favorites
        // So next should be the second favorite
        self::assertSame('llama_cpp', $next->providerId);
        self::assertSame('flash', $next->modelName);
    }

    public function testCycleFavoriteModelWrapsAround(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        // First set current to the last favorite
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $next = $service->cycleFavoriteModel($this->sessionId);

        self::assertNotNull($next);
        // Should wrap to first
        self::assertSame('deepseek', $next->providerId);
        self::assertSame('deepseek-v4-pro', $next->modelName);
    }

    public function testCycleFavoriteModelReturnsNullWhenNoFavorites(): void
    {
        $service = $this->buildService($this->standardAiData());

        $next = $service->cycleFavoriteModel($this->sessionId);

        self::assertNull($next);
    }

    public function testCycleReasoningReturnsNextLevel(): void
    {
        $service = $this->buildService($this->standardAiData());

        self::assertSame('high', $service->cycleReasoning('medium'));
        self::assertSame('xhigh', $service->cycleReasoning('high'));
        self::assertSame('off', $service->cycleReasoning('xhigh'));
        self::assertSame('minimal', $service->cycleReasoning('off'));
    }

    public function testCycleReasoningWrapsToBeginning(): void
    {
        $service = $this->buildService($this->standardAiData());

        self::assertSame('off', $service->cycleReasoning('xhigh'));
    }

    public function testCycleReasoningStartsFromBeginningForUnknownLevel(): void
    {
        $service = $this->buildService($this->standardAiData());

        self::assertSame('off', $service->cycleReasoning('unknown'));
    }

    public function testGetCurrentModelResolvesFromSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['model' => 'llama_cpp/flash']);

        $current = $service->getCurrentModel($this->sessionId);

        self::assertNotNull($current);
        self::assertSame('llama_cpp', $current->providerId);
        self::assertSame('flash', $current->modelName);
    }

    public function testGetCurrentReasoningResolvesFromSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata($this->sessionId, ['reasoning' => 'xhigh']);

        $current = $service->getCurrentReasoning($this->sessionId);

        self::assertSame('xhigh', $current);
    }

    public function testAiConfigParsesFavoriteModels(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $service = $this->buildService($aiData);

        $favs = $service->getFavoriteModels();

        self::assertCount(2, $favs);
        self::assertSame('deepseek/deepseek-v4-pro', $favs[0]);
        self::assertSame('llama_cpp/flash', $favs[1]);
    }

    // ──────────────────────────────────────────────
    //  Persistence across restart (EDITOR / AI-14)
    // ──────────────────────────────────────────────

    /**
     * After changing the model via changeModel(), the home settings file
     * should be updated.  Re-creating the service from the persisted home
     * file must resolve the new model — even when a project settings file
     * exists (but does NOT override default_model).
     *
     * This simulates the restart scenario: user changes model, exits the
     * TUI, and starts a new session.
     */
    public function testModelChangePersistsToHomeSettingsForRestart(): void
    {
        // Arrange: service with standard AI config
        $aiData = $this->standardAiData();
        $service = $this->buildService($aiData);

        // Act: change model to a different one
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        // Assert: home settings file was updated
        $homeContent = file_get_contents($this->homeDir.'/.hatfield/settings.yaml');
        self::assertNotFalse($homeContent);
        self::assertStringContainsString('default_model: llama_cpp/flash', (string) $homeContent);

        // Simulate restart: create a fresh service from the persisted home data.
        // Use the same AppConfig creation path as buildService() but with
        // aiData that does NOT specify default_model (simulating project file
        // without it, which is the fixed state after this changeset).
        $aiDataWithoutDefault = $this->standardAiData();
        unset($aiDataWithoutDefault['default_model'], $aiDataWithoutDefault['default_reasoning']);
        $newService = $this->buildService($aiDataWithoutDefault);

        // The resolved model should be the one persisted to home settings
        $resolved = $newService->getCurrentModel($this->sessionId);
        self::assertNotNull($resolved);
        self::assertSame('llama_cpp', $resolved->providerId);
        self::assertSame('flash', $resolved->modelName);
    }

    /**
     * When project settings do NOT specify default_model, the home
     * settings value should win without being overridden.
     */
    public function testHomeDefaultModelWinsWhenProjectDoesNotOverride(): void
    {
        // Build with default_model only in the aiData (not via home file yet)
        $aiData = $this->standardAiData();
        unset($aiData['default_model']);
        $service = $this->buildService($aiData);

        // Persist to home via changeModel
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        // Re-create service with aiData that has NO default_model
        // (simulating project without default_model override)
        $newService = $this->buildService($aiData);

        $resolved = $newService->getCurrentModel($this->sessionId);
        self::assertNotNull($resolved);
        self::assertSame('llama_cpp', $resolved->providerId);
        self::assertSame('flash', $resolved->modelName);
    }

    // ──────────────────────────────────────────────
    //  Thinking levels support (AI-14)
    // ──────────────────────────────────────────────

    public function testSupportsThinkingLevelsForSessionReturnsTrueForReasoningModel(): void
    {
        $service = $this->buildService($this->standardAiData());

        // deepseek/deepseek-v4-pro has reasoning=true and provider supportsThinkingLevels=true (default)
        self::assertTrue($service->supportsThinkingLevelsForSession($this->sessionId));
    }

    public function testSupportsThinkingLevelsForSessionReturnsFalseForNonReasoningModel(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Switch to llama_cpp/flash which has reasoning=false
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        self::assertFalse($service->supportsThinkingLevelsForSession($this->sessionId));
    }

    public function testSupportsThinkingLevelsForSessionReturnsFalseWhenProviderDisabled(): void
    {
        $aiData = $this->standardAiData();
        // llama_cpp with supports_thinking_levels explicitly false
        $aiData['providers']['llama_cpp']['supports_thinking_levels'] = false;
        $service = $this->buildService($aiData);
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        self::assertFalse($service->supportsThinkingLevelsForSession($this->sessionId));
    }

    public function testCycleReasoningForCurrentModelSuccess(): void
    {
        $service = $this->buildService($this->standardAiData());

        // deepseek/deepseek-v4-pro supports reasoning, current is 'medium'
        $result = $service->cycleReasoningForCurrentModel($this->sessionId);

        self::assertNotNull($result);
        self::assertSame('high', $result);

        // Verify persistence
        $meta = $this->readSessionMetadata($this->sessionId);
        self::assertSame('high', $meta['reasoning']);
    }

    public function testCycleReasoningForCurrentModelReturnsNullWhenUnsupported(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Switch to a model that doesn't support reasoning
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $result = $service->cycleReasoningForCurrentModel($this->sessionId);

        self::assertNull($result);
    }

    public function testCycleReasoningForCurrentModelReturnsNullWhenProviderThinkingLevelsDisabled(): void
    {
        $aiData = $this->standardAiData();
        $aiData['providers']['llama_cpp']['supports_thinking_levels'] = false;
        $service = $this->buildService($aiData);
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $result = $service->cycleReasoningForCurrentModel($this->sessionId);

        self::assertNull($result);
    }

    // ──────────────────────────────────────────────
    //  Display reasoning (footer color reset)
    // ──────────────────────────────────────────────

    public function testGetDisplayReasoningReturnsCurrentForThinkingModel(): void
    {
        $service = $this->buildService($this->standardAiData());
        // deepseek/deepseek-v4-pro supports reasoning, current defaults to medium
        self::assertSame('medium', $service->getDisplayReasoning($this->sessionId));

        $service->changeReasoning('xhigh', $this->sessionId);
        self::assertSame('xhigh', $service->getDisplayReasoning($this->sessionId));
    }

    public function testGetDisplayReasoningReturnsOffForNonThinkingModel(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Switch to a model that doesn't support reasoning, but set reasoning high first
        $service->changeReasoning('high', $this->sessionId);
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        // Display reasoning should be 'off' even though session metadata still has 'high'
        self::assertSame('off', $service->getDisplayReasoning($this->sessionId));

        // Persisted reasoning still high (cycleReasoning would re-enable it on a thinking model)
        self::assertSame('high', $service->getCurrentReasoning($this->sessionId));
    }

    public function testGetDisplayReasoningReturnsOffWhenProviderThinkingLevelsDisabled(): void
    {
        $aiData = $this->standardAiData();
        $aiData['providers']['llama_cpp']['supports_thinking_levels'] = false;
        $service = $this->buildService($aiData);
        $service->changeReasoning('xhigh', $this->sessionId);
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        self::assertSame('off', $service->getDisplayReasoning($this->sessionId));
    }

    public function testGetDisplayReasoningReturnsOffWhenNoCatalog(): void
    {
        $service = $this->buildService([]);

        self::assertSame('off', $service->getDisplayReasoning($this->sessionId));
    }

    public function testGetDisplayReasoningReturnsOffForUnknownModel(): void
    {
        // Switch to a non-thinking model, then set reasoning high
        $service = $this->buildService($this->standardAiData());
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);
        $service->changeReasoning('high', $this->sessionId);

        // Switching back from llama (non-thinking) to deepseek (thinking) should
        // restore the persisted 'high' display reasoning
        $service->changeModel(new AiModelReference('deepseek', 'deepseek-v4-pro'), $this->sessionId);
        self::assertSame('high', $service->getDisplayReasoning($this->sessionId));

        // Switching back to non-thinking model resets to off
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);
        self::assertSame('off', $service->getDisplayReasoning($this->sessionId));
    }

    // ──────────────────────────────────────────────
    //  Supported reasoning levels (per-model cycling)
    // ──────────────────────────────────────────────

    public function testGetSupportedReasoningLevelsReturnsModelLevels(): void
    {
        // deepseek/deepseek-v4-pro has 5 thinking levels + off = 6
        $service = $this->buildService($this->standardAiData());

        $levels = $service->getSupportedReasoningLevels($this->sessionId);

        self::assertContains('off', $levels);
        self::assertContains('minimal', $levels);
        self::assertContains('low', $levels);
        self::assertContains('medium', $levels);
        self::assertContains('high', $levels);
        self::assertContains('xhigh', $levels);
        // off must be first
        self::assertSame('off', $levels[0]);
    }

    public function testGetSupportedReasoningLevelsReturnsOnlyOffForNonReasoningModel(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Switch to llama_cpp/flash (reasoning: false, empty thinkingLevelMap)
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), $this->sessionId);

        $levels = $service->getSupportedReasoningLevels($this->sessionId);

        // Empty thinkingLevelMap → only 'off'
        self::assertSame(['off'], $levels);
    }

    public function testGetSupportedReasoningLevelsReturnsGlobalLevelsWhenNoModel(): void
    {
        $service = $this->buildService([]);

        $levels = $service->getSupportedReasoningLevels($this->sessionId);

        // No catalog → fall back to global LEVELS
        self::assertSame(ModelSelectionService::LEVELS, $levels);
    }

    public function testCycleReasoningForCurrentModelDoesNotExposeXhighForZaiStyleModel(): void
    {
        // A z.ai-style model that omits xhigh from its thinking_level_map
        $aiData = $this->standardAiData();
        $aiData['providers']['zai'] = [
            'type' => 'generic',
            'enabled' => true,
            'base_url' => 'https://api.z.ai/api/coding/paas/v4',
            'api' => 'openai-completions',
            'api_key' => 'test-key',
            'completions_path' => '/chat/completions',
            'supports_completions' => true,
            'supports_embeddings' => false,
            'models' => [
                'glm-5.1' => [
                    'name' => 'GLM 5.1',
                    'context_window' => 200000,
                    'max_tokens' => 131072,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => true,
                    // Z.ai has no xhigh — only off, minimal, low, medium, high
                    'thinking_level_map' => [
                        'minimal' => 'enabled',
                        'low' => 'enabled',
                        'medium' => 'enabled',
                        'high' => 'enabled',
                    ],
                    'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                ],
            ],
        ];
        $service = $this->buildService($aiData);

        // Change model to z.ai glm-5.1 and set reasoning to 'high'
        $service->changeModel(new AiModelReference('zai', 'glm-5.1'), $this->sessionId);
        $service->changeReasoning('high', $this->sessionId);

        // Cycling should wrap to 'off' (not 'xhigh')
        $result = $service->cycleReasoningForCurrentModel($this->sessionId);
        self::assertSame('off', $result);

        // The next cycle after off goes to minimal
        $result = $service->cycleReasoningForCurrentModel($this->sessionId);
        self::assertSame('minimal', $result);
    }
}
