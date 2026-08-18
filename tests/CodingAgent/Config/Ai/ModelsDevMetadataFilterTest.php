<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCost;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\ModelsDevMetadataFilter;
use PHPUnit\Framework\TestCase;

final class ModelsDevMetadataFilterTest extends TestCase
{
    public function testFilterProvidersKeepsOnlyMappedIds(): void
    {
        $filtered = ModelsDevMetadataFilter::filterProviders([
            'zai' => ['id' => 'zai', 'api' => 'https://evil.example/v1', 'models' => []],
            'anthropic' => ['id' => 'anthropic', 'models' => []],
            'xai' => ['id' => 'xai', 'models' => []],
        ]);

        $this->assertSame(['zai', 'xai'], array_keys($filtered));
        $this->assertArrayNotHasKey('anthropic', $filtered);
    }

    public function testExtractModelMetadataAllowlistAndMapping(): void
    {
        $meta = ModelsDevMetadataFilter::extractModelMetadata([
            'limit' => ['context' => 1000000, 'output' => 384000],
            'modalities' => ['input' => ['text', 'audio', 'image']],
            'reasoning' => true,
            'tool_call' => true,
            // models.dev cost is USD per 1M tokens — same unit as AiCost.
            'cost' => ['input' => 0.14, 'output' => 0.28, 'cache_read' => 0.0028],
            'api' => 'https://evil.example',
            'base_url' => 'https://evil.example',
            'name' => 'ignored-by-allowlist',
        ]);

        $this->assertSame([
            'context_window' => 1000000,
            'max_tokens' => 384000,
            'input' => ['text', 'image'],
            'reasoning' => true,
            'tool_calling' => true,
            'cost' => ['input' => 0.14, 'output' => 0.28, 'cache_read' => 0.0028],
        ], $meta);
        $this->assertArrayNotHasKey('api', $meta);
        $this->assertArrayNotHasKey('base_url', $meta);
        $this->assertArrayNotHasKey('name', $meta);
    }

    public function testHostileUpstreamConnectionFieldsCannotReachAiProviderConfig(): void
    {
        $catalog = [
            'zai' => [
                'label' => 'Z.ai',
                'kind' => 'apikey',
                'type' => 'generic',
                'base_url' => 'https://api.z.ai/api/coding/paas/v4',
                'api' => 'openai-completions',
                'completions_path' => '/chat/completions',
                'auth_command' => null,
                'models' => [
                    'glm-5.3' => [
                        'name' => 'GLM 5.3',
                        'context_window' => 1000000,
                        'max_tokens' => 131072,
                        'input' => ['text'],
                        'tool_calling' => true,
                        'reasoning' => true,
                        'thinking_level_map' => ['minimal' => 'enabled'],
                        'cost' => ['input' => 0.0, 'output' => 0.0],
                    ],
                ],
            ],
        ];

        $upstream = [
            'zai' => [
                'api' => 'https://attacker.example/v1',
                'doc' => 'https://attacker.example/docs',
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 999, 'output' => 111],
                        'cost' => ['input' => 1.5, 'output' => 2.5],
                        'api' => 'https://attacker.example/model',
                        'base_url' => 'https://attacker.example',
                    ],
                    'glm-9.9' => [
                        'limit' => ['context' => 1],
                    ],
                ],
            ],
        ];

        $result = ModelsDevMetadataFilter::refreshCatalogProviders($catalog, $upstream);
        $provider = AiProviderConfig::fromArray($result['providers']['zai'], 'zai');

        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $provider->baseUrl);
        $this->assertSame('openai-completions', $provider->api);
        $this->assertSame('/chat/completions', $provider->completionsPath);
        $this->assertSame(['glm-5.3'], array_keys($provider->models));
        $this->assertSame(999, $provider->models['glm-5.3']->contextWindow);
        $this->assertSame(111, $provider->models['glm-5.3']->maxTokens);
        $this->assertSame(['minimal' => 'enabled'], $provider->models['glm-5.3']->thinkingLevelMap);
        $this->assertSame(['glm-9.9'], $result['discovery']['zai']);
    }

    public function testCostUnitsMatchAiCostPerMillionConvention(): void
    {
        // Documented contract: models.dev cost.* and AiCost are both USD / 1M tokens.
        $meta = ModelsDevMetadataFilter::extractModelMetadata([
            'cost' => ['input' => 0.14, 'output' => 0.28],
        ]);
        $cost = AiCost::fromArray($meta['cost']);
        $this->assertSame(0.14, $cost->input);
        $this->assertSame(0.28, $cost->output);

        $model = AiModelDefinition::fromArray([
            'cost' => $meta['cost'],
        ], 'deepseek-v4-flash');
        $this->assertNotNull($model->cost);
        $this->assertSame(0.14, $model->cost->input);
    }
}
