<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ModelSelectionServiceTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private ModelSelectionService $service;
    private SessionMetadataStore $sessionMetaStore;

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
        $this->sessionMetaStore = new SessionMetadataStore();
        $this->sessionMetaStore->setSessionsBasePath($this->tempDir.'/project/.hatfield/sessions');

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
            sessions: (array) ($raw['sessions'] ?? []),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    /**
     * Write session metadata YAML.
     */
    private function writeSessionMetadata(string $sessionId, array $meta): void
    {
        $dir = $this->tempDir.'/project/.hatfield/sessions/'.$sessionId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/metadata.yaml', Yaml::dump($meta, 4, 2));
    }

    /**
     * Read session metadata YAML.
     */
    private function readSessionMetadata(string $sessionId): array
    {
        $path = $this->tempDir.'/project/.hatfield/sessions/'.$sessionId.'/metadata.yaml';

        if (!is_readable($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : [];
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
        $this->writeSessionMetadata('abc123', ['model' => 'llama_cpp/flash']);

        $result = $service->resolveInitialModel('deepseek/deepseek-v4-pro', 'abc123');

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testSessionMetadataWinsOverDefaultAndFirstAvailable(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata('abc123', ['model' => 'llama_cpp/flash']);

        $result = $service->resolveInitialModel(null, 'abc123');

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
        $this->writeSessionMetadata('abc123', ['reasoning' => 'low']);

        $result = $service->resolveInitialReasoning('xhigh', 'abc123');

        self::assertSame('xhigh', $result);
    }

    public function testSessionReasoningWinsOverDefault(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata('abc123', ['reasoning' => 'xhigh']);

        $result = $service->resolveInitialReasoning(null, 'abc123');

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

        $service->changeModel($ref, 'abc123');

        // Check session metadata
        $meta = $this->readSessionMetadata('abc123');
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

        $service->changeModel($ref, 'abc123');
    }

    // ──────────────────────────────────────────────
    //  Reasoning persistence
    // ──────────────────────────────────────────────

    public function testChangeReasoningPersistsToHomeAndSession(): void
    {
        $service = $this->buildService($this->standardAiData());

        $service->changeReasoning('xhigh', 'abc123');

        // Check session metadata
        $meta = $this->readSessionMetadata('abc123');
        self::assertSame('xhigh', $meta['reasoning']);
    }

    public function testChangeReasoningThrowsOnInvalidLevel(): void
    {
        $service = $this->buildService($this->standardAiData());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reasoning level');

        $service->changeReasoning('super-genius', 'abc123');
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
        $this->writeSessionMetadata('abc123', ['model' => 'garbage/invalid']);

        $result = $service->resolveInitialModel(null, 'abc123');

        // Should fall through to default
        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testChangeReasoningPreservesSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        // Pre-populate session metadata
        $this->writeSessionMetadata('abc123', [
            'session_id' => 'abc123',
            'run_id' => 'abc123',
            'cwd' => '/some/path',
            'model' => 'deepseek/deepseek-v4-pro',
        ]);

        $service->changeReasoning('high', 'abc123');

        $meta = $this->readSessionMetadata('abc123');
        self::assertSame('high', $meta['reasoning']);
        self::assertSame('abc123', $meta['session_id']);
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
        $next = $service->cycleFavoriteModel('abc123');

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

        $next = $service->cycleFavoriteModel('abc123');

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
        $service->changeModel(new AiModelReference('llama_cpp', 'flash'), 'abc123');

        $next = $service->cycleFavoriteModel('abc123');

        self::assertNotNull($next);
        // Should wrap to first
        self::assertSame('deepseek', $next->providerId);
        self::assertSame('deepseek-v4-pro', $next->modelName);
    }

    public function testCycleFavoriteModelReturnsNullWhenNoFavorites(): void
    {
        $service = $this->buildService($this->standardAiData());

        $next = $service->cycleFavoriteModel('abc123');

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
        $this->writeSessionMetadata('abc123', ['model' => 'llama_cpp/flash']);

        $current = $service->getCurrentModel('abc123');

        self::assertNotNull($current);
        self::assertSame('llama_cpp', $current->providerId);
        self::assertSame('flash', $current->modelName);
    }

    public function testGetCurrentReasoningResolvesFromSessionMetadata(): void
    {
        $service = $this->buildService($this->standardAiData());
        $this->writeSessionMetadata('abc123', ['reasoning' => 'xhigh']);

        $current = $service->getCurrentReasoning('abc123');

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
}
