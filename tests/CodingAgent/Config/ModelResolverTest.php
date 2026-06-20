<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for ModelResolver (read-only resolution).
 *
 * Session metadata integration is tested in ModelSelectionServiceTest.
 * These tests pass empty sessionId so the resolver never queries the DB.
 */
class ModelResolverTest extends TestCase
{
    // ──────────────────────────────────────────────
    //  Model resolution priority chain
    // ──────────────────────────────────────────────

    public function testExplicitModelWins(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialModel('deepseek/deepseek-v4-pro', '');

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testDefaultModelWins(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialModel(null, '');

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testFirstAvailableWhenNoDefault(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_model']);
        $resolver = $this->createResolver($aiData);

        $result = $resolver->resolveInitialModel(null, '');

        self::assertNotNull($result);
    }

    public function testReturnsNullWhenNoModelsConfigured(): void
    {
        $resolver = $this->createResolver([]);

        $result = $resolver->resolveInitialModel(null, '');

        self::assertNull($result);
    }

    public function testExplicitUnavailableFallsToDefault(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialModel('unknown/model', '');

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution
    // ──────────────────────────────────────────────

    public function testExplicitReasoningWins(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialReasoning('xhigh', '');

        self::assertSame('xhigh', $result);
    }

    public function testDefaultReasoningUsed(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialReasoning(null, '');

        self::assertSame('medium', $result);
    }

    public function testReasoningFallsBackToMedium(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_reasoning']);
        $resolver = $this->createResolver($aiData);

        $result = $resolver->resolveInitialReasoning(null, '');

        self::assertSame('medium', $result);
    }

    // ──────────────────────────────────────────────
    //  Catalog helpers
    // ──────────────────────────────────────────────

    public function testGetAvailableModelsReturnsAll(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $models = $resolver->getAvailableModels();

        self::assertCount(3, $models);
    }

    public function testGetCurrentModelDelegatesToResolve(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->getCurrentModel('');

        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    // ──────────────────────────────────────────────
    //  Thinking levels
    // ──────────────────────────────────────────────

    public function testSupportsThinkingLevelsReturnsTrueForReasoningModel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        self::assertTrue($resolver->supportsThinkingLevelsForSession(''));
    }

    public function testSupportsThinkingLevelsReturnsFalseForNonReasoningModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        self::assertFalse($resolver->supportsThinkingLevelsForSession(''));
    }

    // ──────────────────────────────────────────────
    //  Supported reasoning levels
    // ──────────────────────────────────────────────

    public function testGetSupportedReasoningLevelsReturnsModelLevels(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $levels = $resolver->getSupportedReasoningLevels('');

        self::assertContains('off', $levels);
        self::assertContains('minimal', $levels);
        self::assertContains('high', $levels);
        self::assertSame('off', $levels[0]);
    }

    public function testGetSupportedReasoningLevelsDoesNotExposeXhighForZaiStyleModel(): void
    {
        $aiData = [
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai',
                    'models' => [
                        'glm-5.1' => [
                            'id' => 'glm-5.1',
                            'name' => 'GLM 5.1',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                // No xhigh entry — model does not support it
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);

        $levels = $resolver->getSupportedReasoningLevels('');

        self::assertContains('off', $levels);
        self::assertContains('minimal', $levels);
        self::assertContains('medium', $levels);
        self::assertContains('high', $levels);
        self::assertNotContains('xhigh', $levels);
    }

    public function testSupportsThinkingLevelsReturnsFalseWhenProviderDisabled(): void
    {
        $aiData = [
            'default_model' => 'zai/glm-5.1',
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'supports_thinking_levels' => false,
                    'base_url' => 'https://api.z.ai',
                    'models' => [
                        'glm-5.1' => [
                            'id' => 'glm-5.1',
                            'name' => 'GLM 5.1',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);

        self::assertFalse($resolver->supportsThinkingLevelsForSession(''));
    }

    public function testGetDisplayReasoningReturnsOffWhenProviderThinkingLevelsDisabled(): void
    {
        $aiData = [
            'default_model' => 'zai/glm-5.1',
            'default_reasoning' => 'high',
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'supports_thinking_levels' => false,
                    'base_url' => 'https://api.z.ai',
                    'models' => [
                        'glm-5.1' => [
                            'id' => 'glm-5.1',
                            'name' => 'GLM 5.1',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);

        self::assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetSupportedReasoningLevelsReturnsOnlyOffForNonReasoningModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        $levels = $resolver->getSupportedReasoningLevels('');

        self::assertSame(['off'], $levels);
    }

    public function testGetSupportedReasoningLevelsReturnsGlobalLevelsWhenNoModel(): void
    {
        $resolver = $this->createResolver([]);

        $levels = $resolver->getSupportedReasoningLevels('');

        self::assertSame(ModelResolver::LEVELS, $levels);
    }

    // ──────────────────────────────────────────────
    //  Display reasoning
    // ──────────────────────────────────────────────

    public function testGetDisplayReasoningReturnsCurrentForThinkingModel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        self::assertSame('medium', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffForNonThinkingModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        self::assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffWhenNoCatalog(): void
    {
        $resolver = $this->createResolver([]);

        self::assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffForUnknownModel(): void
    {
        // AI config with only a non-existent default model and no providers
        // so no model can be resolved — display reasoning falls back to 'off'.
        $resolver = $this->createResolver(['default_model' => 'unknown/model']);

        self::assertSame('off', $resolver->getDisplayReasoning(''));
    }

    // ──────────────────────────────────────────────
    //  Clamp reasoning level
    // ──────────────────────────────────────────────

    public function testClampReasoningLevelReturnsLevelWhenSupported(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $model = new AiModelReference('deepseek', 'deepseek-v4-pro');

        self::assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
        self::assertSame('off', $resolver->clampReasoningLevel('off', $model));
    }

    public function testClampReasoningLevelReturnsHighestSupportedWhenNotInMap(): void
    {
        // Build a z.ai-style model that supports high but not xhigh
        $aiData = [
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai',
                    'models' => [
                        'glm-5.1' => [
                            'id' => 'glm-5.1',
                            'name' => 'GLM 5.1',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                // No xhigh entry — model does not support it
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);
        $model = new AiModelReference('zai', 'glm-5.1');

        // xhigh not in map → clamp to highest = high
        self::assertSame('high', $resolver->clampReasoningLevel('xhigh', $model));
        // off is always preserved
        self::assertSame('off', $resolver->clampReasoningLevel('off', $model));
        // supported levels are preserved
        self::assertSame('low', $resolver->clampReasoningLevel('low', $model));
    }

    public function testClampReasoningLevelReturnsLevelWhenNoMap(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $model = new AiModelReference('llama_cpp', 'flash');

        // llama_cpp/flash has no thinking_level_map — level passes through
        self::assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
    }

    public function testClampReasoningLevelReturnsLevelWhenNoCatalog(): void
    {
        $resolver = $this->createResolver([]);
        $model = new AiModelReference('unknown', 'unknown');

        self::assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
    }

    public function testGetDisplayReasoningClampsXhighToHighForZaiStyleModel(): void
    {
        // Setup: z.ai is default.  default_reasoning is xhigh but
        // the z.ai model's thinking_level_map only goes up to high.
        // getDisplayReasoning must clamp xhigh → high.
        $aiData = [
            'default_model' => 'zai/glm-5.1',
            'default_reasoning' => 'xhigh',
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai',
                    'models' => [
                        'glm-5.1' => [
                            'id' => 'glm-5.1',
                            'name' => 'GLM 5.1',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                // No xhigh entry
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);

        // default_reasoning is xhigh, model only supports up to high → clamp
        self::assertSame('high', $resolver->getDisplayReasoning(''));
    }

    // ──────────────────────────────────────────────
    //  Cycle reasoning
    // ──────────────────────────────────────────────

    public function testCycleReasoningReturnsNextLevel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        self::assertSame('high', $resolver->cycleReasoning('medium'));
        self::assertSame('off', $resolver->cycleReasoning('xhigh'));
        self::assertSame('minimal', $resolver->cycleReasoning('off'));
    }

    public function testCycleReasoningStartsFromBeginningForUnknownLevel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        self::assertSame('off', $resolver->cycleReasoning('unknown'));
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private function createResolver(array $aiData): ModelResolver
    {
        $appConfig = $this->makeAppConfig($aiData);

        // SessionMetadataStore is not used when sessionId is empty,
        // but the resolver requires it in its constructor.
        // Create a real one with minimal real dependencies.
        $sessionMetaStore = $this->createSessionMetaStore();

        return new ModelResolver($appConfig, $sessionMetaStore);
    }

    private function createSessionMetaStore(): SessionMetadataStore
    {
        // HatfieldSessionStore is final — cannot be mocked.
        // Create it via reflection for the SessionMetadataStore constructor.
        $hatfieldSessionStore = (new \ReflectionClass(HatfieldSessionStore::class))
            ->newInstanceWithoutConstructor();

        return new SessionMetadataStore($hatfieldSessionStore);
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
            cwd: '/tmp',
        );
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
}
