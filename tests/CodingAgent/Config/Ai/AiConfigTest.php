<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiCost;
use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use PHPUnit\Framework\TestCase;

class AiConfigTest extends TestCase
{
    private function minimalConfig(): array
    {
        return [
            'tui' => ['theme' => 'cyberpunk'],
            'sessions' => ['path' => '.hatfield/sessions'],
        ];
    }

    public function testAiSectionAbsentYieldsNull(): void
    {
        $config = AppConfig::fromArray($this->minimalConfig());
        self::assertNull($config->ai);
    }

    public function testAiSectionEmptyYieldsDefault(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [];

        $config = AppConfig::fromArray($data);
        self::assertNotNull($config->ai);
        self::assertNull($config->ai->defaultModel);
        self::assertNull($config->ai->defaultReasoning);
        self::assertCount(0, $config->ai->providers);
    }

    public function testDefaultModelParsing(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
        ];

        $config = AppConfig::fromArray($data);
        self::assertSame('deepseek/deepseek-v4-pro', $config->ai->defaultModel);
        self::assertSame('medium', $config->ai->defaultReasoning);
    }

    public function testFullProviderParsingDeepseek(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
                    'api' => 'openai-completions',
                    'api_key' => 'env:DEEPSEEK_API_KEY',
                    'completions_path' => '/chat/completions',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'models' => [
                        'deepseek-v4-pro' => [
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 1000000,
                            'max_tokens' => 384000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'high',
                                'low' => 'high',
                                'medium' => 'high',
                                'high' => 'high',
                                'xhigh' => 'max',
                            ],
                            'cost' => [
                                'input' => 0.435,
                                'output' => 0.87,
                                'cache_read' => 0.003625,
                                'cache_write' => 0.0,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $config = AppConfig::fromArray($data);
        $ai = $config->ai;
        self::assertNotNull($ai);
        self::assertCount(1, $ai->providers);

        $provider = $ai->providers['deepseek'] ?? null;
        self::assertNotNull($provider);
        self::assertSame('deepseek', $provider->id);
        self::assertSame('generic', $provider->type);
        self::assertTrue($provider->enabled);
        self::assertSame('https://api.deepseek.com', $provider->baseUrl);
        self::assertSame('openai-completions', $provider->api);
        self::assertSame('env:DEEPSEEK_API_KEY', $provider->apiKey);
        self::assertSame('/chat/completions', $provider->completionsPath);
        self::assertTrue($provider->supportsCompletions);
        self::assertFalse($provider->supportsEmbeddings);
        self::assertNull($provider->compatibility);

        $model = $provider->models['deepseek-v4-pro'] ?? null;
        self::assertNotNull($model);
        self::assertSame('deepseek-v4-pro', $model->id);
        self::assertSame('DeepSeek V4 Pro', $model->name);
        self::assertSame(1000000, $model->contextWindow);
        self::assertSame(384000, $model->maxTokens);
        self::assertSame(['text'], $model->input);
        self::assertTrue($model->toolCalling);
        self::assertTrue($model->reasoning);
        self::assertSame(['minimal' => 'high', 'low' => 'high', 'medium' => 'high', 'high' => 'high', 'xhigh' => 'max'], $model->thinkingLevelMap);
        self::assertNotNull($model->cost);
        self::assertSame(0.435, $model->cost->input);
        self::assertSame(0.87, $model->cost->output);
        self::assertSame(0.003625, $model->cost->cacheRead);
        self::assertSame(0.0, $model->cost->cacheWrite);
        self::assertNull($model->compatibility);
    }

    public function testZaiProviderParsingWithCompatibility(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'default_model' => 'zai/glm-5.1',
            'providers' => [
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai/api/coding/paas/v4',
                    'api' => 'openai-completions',
                    'api_key' => 'env:ZAI_API_KEY',
                    'completions_path' => '/chat/completions',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'compatibility' => [
                        'supports_developer_role' => false,
                        'supports_reasoning_effort' => false,
                        'thinking_format' => 'zai',
                    ],
                    'models' => [
                        'glm-5.1' => [
                            'name' => 'GLM 5.1',
                            'context_window' => 200000,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'enabled',
                                'low' => 'enabled',
                                'medium' => 'enabled',
                                'high' => 'enabled',
                                'xhigh' => 'enabled',
                            ],
                            'compatibility' => ['zai_tool_stream' => true],
                            'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                        ],
                    ],
                ],
            ],
        ];

        $config = AppConfig::fromArray($data);
        $ai = $config->ai;
        self::assertNotNull($ai);

        $provider = $ai->providers['zai'] ?? null;
        self::assertNotNull($provider);
        self::assertNotNull($provider->compatibility);
        self::assertFalse($provider->compatibility->supportsDeveloperRole);
        self::assertFalse($provider->compatibility->supportsReasoningEffort);
        self::assertSame('zai', $provider->compatibility->thinkingFormat);
        self::assertFalse($provider->compatibility->zaiToolStream);

        $model = $provider->models['glm-5.1'] ?? null;
        self::assertNotNull($model);
        self::assertNotNull($model->compatibility);
        self::assertTrue($model->compatibility->zaiToolStream);
        self::assertNull($model->compatibility->thinkingFormat, 'model-level compatibility does not repeat provider thinking_format');
        self::assertSame(0.0, $model->cost->input);
    }

    public function testLlamaCppProviderWithoutReasoning(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'providers' => [
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://192.168.2.38:8052/v1',
                    'api' => 'openai-completions',
                    'api_key' => 'dummy',
                    'completions_path' => '/chat/completions',
                    'embeddings_path' => '/embeddings',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'models' => [
                        'flash' => [
                            'name' => 'flash',
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text', 'image'],
                            'tool_calling' => true,
                            'reasoning' => false,
                            'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                        ],
                    ],
                ],
            ],
        ];

        $config = AppConfig::fromArray($data);
        $provider = $config->ai->providers['llama_cpp'] ?? null;
        self::assertNotNull($provider);
        self::assertSame('http://192.168.2.38:8052/v1', $provider->baseUrl);
        self::assertSame('dummy', $provider->apiKey);

        $model = $provider->models['flash'] ?? null;
        self::assertNotNull($model);
        self::assertFalse($model->reasoning);
        self::assertSame([], $model->thinkingLevelMap);
        self::assertSame(['text', 'image'], $model->input);
        self::assertSame(200000, $model->contextWindow);
        self::assertSame(65536, $model->maxTokens);
        self::assertTrue($model->toolCalling);
    }

    public function testDisabledProviderIsParsedButNotAvailable(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => false,
                    'base_url' => 'https://api.deepseek.com',
                    'api' => 'openai-completions',
                    'models' => [
                        'deepseek-v4-pro' => [
                            'name' => 'DeepSeek V4 Pro',
                        ],
                    ],
                ],
            ],
        ];

        $config = AppConfig::fromArray($data);
        $provider = $config->ai->providers['deepseek'] ?? null;
        self::assertNotNull($provider);
        self::assertFalse($provider->enabled);

        $catalog = new HatfieldModelCatalog($config->ai);
        self::assertFalse($catalog->isAvailable('deepseek/deepseek-v4-pro'));
    }

    public function testProviderKeyMustBeNonEmptyString(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'providers' => [
                0 => ['type' => 'generic', 'base_url' => 'https://example.com'],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider key must be a non-empty string');

        AppConfig::fromArray($data);
    }

    public function testModelKeyMustBeNonEmptyString(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'providers' => [
                'test' => [
                    'type' => 'generic',
                    'base_url' => 'https://example.com',
                    'models' => [
                        '' => ['name' => 'empty'],
                    ],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model key must be a non-empty string');

        AppConfig::fromArray($data);
    }

    public function testBackwardsCompatibleConfigStillLoads(): void
    {
        $config = AppConfig::fromArray(['tui' => ['theme' => 'cyberpunk']]);
        self::assertSame('cyberpunk', $config->tui->theme);
        self::assertNull($config->ai);
        self::assertSame([], $config->sessions);
    }

    public function testCostDefaultsToZero(): void
    {
        $cost = AiCost::fromArray([]);
        self::assertSame(0.0, $cost->input);
        self::assertSame(0.0, $cost->output);
        self::assertSame(0.0, $cost->cacheRead);
        self::assertSame(0.0, $cost->cacheWrite);
    }

    public function testCompatibilityFromEmptyArray(): void
    {
        $compatibility = AiCompatibility::fromArray([]);
        self::assertFalse($compatibility->supportsDeveloperRole);
        self::assertTrue($compatibility->supportsReasoningEffort);
        self::assertNull($compatibility->thinkingFormat);
        self::assertFalse($compatibility->zaiToolStream);
    }

    public function testCompatibilityFromNonBoolValuesFallsBackToDefault(): void
    {
        $compatibility = AiCompatibility::fromArray([
            'supports_developer_role' => 'not-a-bool',
            'supports_reasoning_effort' => 0,
            'thinking_format' => 'zai',
        ]);

        // Non-boolean values fall back to constructor defaults (false, true)
        self::assertFalse($compatibility->supportsDeveloperRole);
        self::assertTrue($compatibility->supportsReasoningEffort);
        self::assertSame('zai', $compatibility->thinkingFormat);
    }

    public function testRawSettingsPreservedInRaw(): void
    {
        $data = [
            'tui' => ['theme' => 'cyberpunk'],
            'ai' => ['default_model' => 'deepseek/deepseek-v4-pro'],
            'custom_future_key' => ['nested' => true],
        ];

        $config = AppConfig::fromArray($data);
        self::assertNotNull($config->ai);
        self::assertArrayHasKey('custom_future_key', $config->raw);
        self::assertTrue($config->raw['custom_future_key']['nested']);
    }
}
