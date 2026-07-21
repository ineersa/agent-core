<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
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

        $this->assertNotNull($result);
        $this->assertSame('deepseek', $result->providerId);
        $this->assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testDefaultModelWins(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialModel(null, '');

        $this->assertNotNull($result);
        $this->assertSame('deepseek', $result->providerId);
        $this->assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testFirstAvailableWhenNoDefault(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_model']);
        $resolver = $this->createResolver($aiData);

        $result = $resolver->resolveInitialModel(null, '');

        $this->assertNotNull($result);
    }

    public function testReturnsNullWhenNoModelsConfigured(): void
    {
        $resolver = $this->createResolver([]);

        $result = $resolver->resolveInitialModel(null, '');

        $this->assertNull($result);
    }

    public function testExplicitUnavailableFallsToDefault(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialModel('unknown/model', '');

        $this->assertNotNull($result);
        $this->assertSame('deepseek', $result->providerId);
        $this->assertSame('deepseek-v4-pro', $result->modelName);
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution
    // ──────────────────────────────────────────────

    public function testExplicitReasoningWins(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialReasoning('xhigh', '');

        $this->assertSame('xhigh', $result);
    }

    public function testDefaultReasoningUsed(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolveInitialReasoning(null, '');

        $this->assertSame('medium', $result);
    }

    public function testReasoningFallsBackToMedium(): void
    {
        $aiData = $this->standardAiData();
        unset($aiData['default_reasoning']);
        $resolver = $this->createResolver($aiData);

        $result = $resolver->resolveInitialReasoning(null, '');

        $this->assertSame('medium', $result);
    }

    // ──────────────────────────────────────────────
    //  Catalog helpers
    // ──────────────────────────────────────────────

    public function testGetAvailableModelsReturnsAll(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $models = $resolver->getAvailableModels();

        $this->assertCount(3, $models);
    }

    public function testGetCurrentModelDelegatesToResolve(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->getCurrentModel('');

        $this->assertNotNull($result);
        $this->assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    // ──────────────────────────────────────────────
    //  Thinking levels
    // ──────────────────────────────────────────────

    public function testSupportsThinkingLevelsReturnsTrueForReasoningModel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->assertTrue($resolver->supportsThinkingLevelsForSession(''));
    }

    public function testSupportsThinkingLevelsReturnsFalseForNonReasoningModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        $this->assertFalse($resolver->supportsThinkingLevelsForSession(''));
    }

    // ──────────────────────────────────────────────
    //  Supported reasoning levels
    // ──────────────────────────────────────────────

    public function testGetSupportedReasoningLevelsReturnsModelLevels(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $levels = $resolver->getSupportedReasoningLevels('');

        $this->assertContains('off', $levels);
        $this->assertContains('minimal', $levels);
        $this->assertContains('high', $levels);
        $this->assertSame('off', $levels[0]);
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

        $this->assertContains('off', $levels);
        $this->assertContains('minimal', $levels);
        $this->assertContains('medium', $levels);
        $this->assertContains('high', $levels);
        $this->assertNotContains('xhigh', $levels);
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

        $this->assertFalse($resolver->supportsThinkingLevelsForSession(''));
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

        $this->assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetSupportedReasoningLevelsReturnsOnlyOffForNonReasoningModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        $levels = $resolver->getSupportedReasoningLevels('');

        $this->assertSame(['off'], $levels);
    }

    public function testGetSupportedReasoningLevelsReturnsGlobalLevelsWhenNoModel(): void
    {
        $resolver = $this->createResolver([]);

        $levels = $resolver->getSupportedReasoningLevels('');

        $this->assertSame(ModelResolver::LEVELS, $levels);
    }

    // ──────────────────────────────────────────────
    //  Display reasoning
    // ──────────────────────────────────────────────

    public function testGetDisplayReasoningReturnsCurrentForThinkingModel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->assertSame('medium', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffForNonThinkingModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['default_model'] = 'llama_cpp/flash';
        $resolver = $this->createResolver($aiData);

        $this->assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffWhenNoCatalog(): void
    {
        $resolver = $this->createResolver([]);

        $this->assertSame('off', $resolver->getDisplayReasoning(''));
    }

    public function testGetDisplayReasoningReturnsOffForUnknownModel(): void
    {
        // AI config with only a non-existent default model and no providers
        // so no model can be resolved — display reasoning falls back to 'off'.
        $resolver = $this->createResolver(['default_model' => 'unknown/model']);

        $this->assertSame('off', $resolver->getDisplayReasoning(''));
    }

    // ──────────────────────────────────────────────
    //  Clamp reasoning level
    // ──────────────────────────────────────────────

    public function testClampReasoningLevelReturnsLevelWhenSupported(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $model = new AiModelReference('deepseek', 'deepseek-v4-pro');

        $this->assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
        $this->assertSame('off', $resolver->clampReasoningLevel('off', $model));
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
        $this->assertSame('high', $resolver->clampReasoningLevel('xhigh', $model));
        // off is always preserved
        $this->assertSame('off', $resolver->clampReasoningLevel('off', $model));
        // supported levels are preserved
        $this->assertSame('low', $resolver->clampReasoningLevel('low', $model));
    }

    public function testClampReasoningLevelReturnsLevelWhenNoMap(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $model = new AiModelReference('llama_cpp', 'flash');

        // llama_cpp/flash has no thinking_level_map — level passes through
        $this->assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
    }

    public function testClampReasoningLevelReturnsLevelWhenNoCatalog(): void
    {
        $resolver = $this->createResolver([]);
        $model = new AiModelReference('unknown', 'unknown');

        $this->assertSame('xhigh', $resolver->clampReasoningLevel('xhigh', $model));
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
        $this->assertSame('high', $resolver->getDisplayReasoning(''));
    }

    // ──────────────────────────────────────────────
    //  Cycle reasoning
    // ──────────────────────────────────────────────

    public function testCycleReasoningReturnsNextLevel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->assertSame('high', $resolver->cycleReasoning('medium'));
        $this->assertSame('max', $resolver->cycleReasoning('xhigh'));
        $this->assertSame('off', $resolver->cycleReasoning('max'));
        $this->assertSame('minimal', $resolver->cycleReasoning('off'));
    }

    public function testCycleReasoningStartsFromBeginningForUnknownLevel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->assertSame('off', $resolver->cycleReasoning('unknown'));
    }

    public function testGetSupportedReasoningLevelsIncludesMaxWhenModelMapHasMax(): void
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-5.6-luna',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'base_url' => 'https://chatgpt.com/backend-api',
                    'models' => [
                        'gpt-5.6-luna' => [
                            'id' => 'gpt-5.6-luna',
                            'name' => 'GPT-5.6 Luna',
                            'context_window' => 372000,
                            'max_tokens' => 128000,
                            'input' => ['text', 'image'],
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'low',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                'xhigh' => 'xhigh',
                                'max' => 'max',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);

        $levels = $resolver->getSupportedReasoningLevels('');

        $this->assertContains('max', $levels);
        $this->assertContains('xhigh', $levels);
    }

    public function testClampReasoningLevelReturnsMaxWhenInMap(): void
    {
        $aiData = [
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'base_url' => 'https://chatgpt.com/backend-api',
                    'models' => [
                        'gpt-5.6-luna' => [
                            'id' => 'gpt-5.6-luna',
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'high' => 'high',
                                'xhigh' => 'xhigh',
                                'max' => 'max',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);
        $model = new AiModelReference('openai-codex', 'gpt-5.6-luna');

        $this->assertSame('max', $resolver->clampReasoningLevel('max', $model));
        // unsupported level clamps to highest map key (max)
        $this->assertSame('max', $resolver->clampReasoningLevel('bogus', $model));
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
