<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiCost;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\TestCase;

class AiConfigTest extends TestCase
{
    public function testAiSectionAbsentYieldsNull(): void
    {
        $config = $this->createAppConfig($this->minimalConfig());
        $this->assertNull($config->ai);
    }

    public function testAiSectionEmptyYieldsDefault(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [];

        $config = $this->createAppConfig($data);
        $this->assertNotNull($config->ai);
        $this->assertNull($config->ai->defaultModel);
        $this->assertNull($config->ai->defaultReasoning);
        $this->assertCount(0, $config->ai->providers);
    }

    public function testDefaultModelParsing(): void
    {
        $data = $this->minimalConfig();
        $data['ai'] = [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
        ];

        $config = $this->createAppConfig($data);
        $this->assertSame('deepseek/deepseek-v4-pro', $config->ai->defaultModel);
        $this->assertSame('medium', $config->ai->defaultReasoning);
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

        $config = $this->createAppConfig($data);
        $ai = $config->ai;
        $this->assertNotNull($ai);
        $this->assertCount(1, $ai->providers);

        $provider = $ai->providers['deepseek'] ?? null;
        $this->assertNotNull($provider);
        $this->assertSame('deepseek', $provider->id);
        $this->assertSame('generic', $provider->type);
        $this->assertTrue($provider->enabled);
        $this->assertSame('https://api.deepseek.com', $provider->baseUrl);
        $this->assertSame('openai-completions', $provider->api);
        $this->assertSame('env:DEEPSEEK_API_KEY', $provider->apiKey);
        $this->assertSame('/chat/completions', $provider->completionsPath);
        $this->assertTrue($provider->supportsCompletions);
        $this->assertFalse($provider->supportsEmbeddings);
        $this->assertNull($provider->compatibility);

        $model = $provider->models['deepseek-v4-pro'] ?? null;
        $this->assertNotNull($model);
        $this->assertSame('deepseek-v4-pro', $model->id);
        $this->assertSame('DeepSeek V4 Pro', $model->name);
        $this->assertSame(1000000, $model->contextWindow);
        $this->assertSame(384000, $model->maxTokens);
        $this->assertSame(['text'], $model->input);
        $this->assertTrue($model->toolCalling);
        $this->assertTrue($model->reasoning);
        $this->assertSame(['minimal' => 'high', 'low' => 'high', 'medium' => 'high', 'high' => 'high', 'xhigh' => 'max'], $model->thinkingLevelMap);
        $this->assertNotNull($model->cost);
        $this->assertSame(0.435, $model->cost->input);
        $this->assertSame(0.87, $model->cost->output);
        $this->assertSame(0.003625, $model->cost->cacheRead);
        $this->assertSame(0.0, $model->cost->cacheWrite);
        $this->assertNull($model->compatibility);
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

        $config = $this->createAppConfig($data);
        $ai = $config->ai;
        $this->assertNotNull($ai);

        $provider = $ai->providers['zai'] ?? null;
        $this->assertNotNull($provider);
        $this->assertNotNull($provider->compatibility);
        $this->assertFalse($provider->compatibility->supportsDeveloperRole);
        $this->assertFalse($provider->compatibility->supportsReasoningEffort);
        $this->assertSame('zai', $provider->compatibility->thinkingFormat);
        $this->assertFalse($provider->compatibility->zaiToolStream);

        $model = $provider->models['glm-5.1'] ?? null;
        $this->assertNotNull($model);
        $this->assertNotNull($model->compatibility);
        $this->assertTrue($model->compatibility->zaiToolStream);
        $this->assertNull($model->compatibility->thinkingFormat, 'model-level compatibility does not repeat provider thinking_format');
        $this->assertSame(0.0, $model->cost->input);
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

        $config = $this->createAppConfig($data);
        $provider = $config->ai->providers['llama_cpp'] ?? null;
        $this->assertNotNull($provider);
        $this->assertSame('http://192.168.2.38:8052/v1', $provider->baseUrl);
        $this->assertSame('dummy', $provider->apiKey);

        $model = $provider->models['flash'] ?? null;
        $this->assertNotNull($model);
        $this->assertFalse($model->reasoning);
        $this->assertSame([], $model->thinkingLevelMap);
        $this->assertSame(['text', 'image'], $model->input);
        $this->assertSame(200000, $model->contextWindow);
        $this->assertSame(65536, $model->maxTokens);
        $this->assertTrue($model->toolCalling);
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

        $config = $this->createAppConfig($data);
        $provider = $config->ai->providers['deepseek'] ?? null;
        $this->assertNotNull($provider);
        $this->assertFalse($provider->enabled);

        $catalog = new HatfieldModelCatalog($config->ai);
        $this->assertFalse($catalog->isAvailable('deepseek/deepseek-v4-pro'));
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

        $this->createAppConfig($data);
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

        $this->createAppConfig($data);
    }

    public function testBackwardsCompatibleConfigStillLoads(): void
    {
        $config = $this->createAppConfig(['tui' => ['theme' => 'cyberpunk']]);
        $this->assertSame('cyberpunk', $config->tui->theme);
        $this->assertNull($config->ai);
        $this->assertInstanceOf(SessionsConfig::class, $config->sessions);
        $this->assertSame('.hatfield/sessions', $config->sessions->path);
    }

    public function testCostDefaultsToZero(): void
    {
        $cost = AiCost::fromArray([]);
        $this->assertSame(0.0, $cost->input);
        $this->assertSame(0.0, $cost->output);
        $this->assertSame(0.0, $cost->cacheRead);
        $this->assertSame(0.0, $cost->cacheWrite);
    }

    public function testCompatibilityFromEmptyArray(): void
    {
        $compatibility = AiCompatibility::fromArray([]);
        $this->assertFalse($compatibility->supportsDeveloperRole);
        $this->assertTrue($compatibility->supportsReasoningEffort);
        $this->assertNull($compatibility->thinkingFormat);
        $this->assertFalse($compatibility->zaiToolStream);
    }

    public function testCompatibilityFromNonBoolValuesFallsBackToDefault(): void
    {
        $compatibility = AiCompatibility::fromArray([
            'supports_developer_role' => 'not-a-bool',
            'supports_reasoning_effort' => 0,
            'thinking_format' => 'zai',
        ]);

        // Non-boolean values fall back to constructor defaults (false, true)
        $this->assertFalse($compatibility->supportsDeveloperRole);
        $this->assertTrue($compatibility->supportsReasoningEffort);
        $this->assertSame('zai', $compatibility->thinkingFormat);
    }

    public function testRawSettingsPreservedInRaw(): void
    {
        $data = [
            'tui' => ['theme' => 'cyberpunk'],
            'ai' => ['default_model' => 'deepseek/deepseek-v4-pro'],
            'custom_future_key' => ['nested' => true],
        ];

        $config = $this->createAppConfig($data);
        $this->assertNotNull($config->ai);
        $this->assertArrayHasKey('custom_future_key', $config->raw);
        $this->assertTrue($config->raw['custom_future_key']['nested']);
    }

    private function minimalConfig(): array
    {
        return [
            'tui' => ['theme' => 'cyberpunk'],
            'sessions' => ['path' => '.hatfield/sessions'],
        ];
    }

    /**
     * Construct an AppConfig from a pre-built config array using the
     * public value-object constructor — no Reflection or test-only
     * production code.
     */
    private function createAppConfig(array $data): AppConfig
    {
        $ai = AiConfig::optionalFromArray($data);

        $sessionsData = (array) ($data['sessions'] ?? []);

        return new AppConfig(
            tui: TuiConfig::fromArray((array) ($data['tui'] ?? [])),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(
                path: (string) ($sessionsData['path'] ?? '.hatfield/sessions'),
            ),
            ai: $ai,
            raw: $data,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }
}
