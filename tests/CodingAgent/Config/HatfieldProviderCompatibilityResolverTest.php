<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;
use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\HatfieldProviderCompatibilityResolver;
use PHPUnit\Framework\TestCase;

final class HatfieldProviderCompatibilityResolverTest extends TestCase
{
    private function makeCatalogWithProviders(array $providers): HatfieldModelCatalog
    {
        $aiConfig = new AiConfig(
            defaultModel: 'zai/glm-5.1',
            defaultReasoning: 'medium',
            providers: $providers,
        );

        return new HatfieldModelCatalog($aiConfig);
    }

    private function makeZaiProvider(bool $supportsDeveloperRole = false): AiProviderConfig
    {
        $model = new AiModelDefinition(
            id: 'glm-5.1',
            reasoning: true,
            thinkingLevelMap: [
                'minimal' => 'enabled',
                'low' => 'enabled',
                'medium' => 'enabled',
                'high' => 'enabled',
                'xhigh' => 'enabled',
            ],
            compatibility: new AiCompatibility(
                supportsDeveloperRole: $supportsDeveloperRole,
                supportsReasoningEffort: false,
                thinkingFormat: 'zai',
                zaiToolStream: true,
            ),
        );

        return new AiProviderConfig(
            id: 'zai',
            enabled: true,
            models: ['glm-5.1' => $model],
        );
    }

    private function makeOpenAIProvider(): AiProviderConfig
    {
        $model = new AiModelDefinition(
            id: 'gpt-5.1',
            reasoning: true,
            thinkingLevelMap: [
                'minimal' => 'minimal',
                'low' => 'low',
                'medium' => 'medium',
                'high' => 'high',
                'xhigh' => 'xhigh',
            ],
            compatibility: new AiCompatibility(
                supportsDeveloperRole: true,
                supportsReasoningEffort: true,
            ),
        );

        return new AiProviderConfig(
            id: 'openai',
            enabled: true,
            models: ['gpt-5.1' => $model],
        );
    }

    // ──────────────────────────────────────────────
    // Model resolution
    // ──────────────────────────────────────────────

    public function testResolveReturnsEmptyCompatForUnknownModel(): void
    {
        $catalog = $this->makeCatalogWithProviders(['zai' => $this->makeZaiProvider()]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('nonexistent-model');

        $this->assertSame([], $compat->options);
        $this->assertNull($compat->thinkingFormat);
        $this->assertTrue($compat->supportsReasoningEffort);
    }

    public function testResolveReturnsEmptyCompatForEmptyCatalog(): void
    {
        $catalog = $this->makeCatalogWithProviders([]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('glm-5.1');

        $this->assertSame([], $compat->options);
        $this->assertNull($compat->thinkingFormat);
        $this->assertTrue($compat->supportsReasoningEffort);
    }

    // ──────────────────────────────────────────────
    // z.ai compat
    // ──────────────────────────────────────────────

    public function testResolveZaiCompat(): void
    {
        $catalog = $this->makeCatalogWithProviders(['zai' => $this->makeZaiProvider()]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('glm-5.1');

        $this->assertTrue($compat->has(ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM));
        $this->assertFalse($compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT));
        $this->assertSame('zai', $compat->thinkingFormat);
        $this->assertFalse($compat->supportsReasoningEffort);
    }

    // ──────────────────────────────────────────────
    // OpenAI compat
    // ──────────────────────────────────────────────

    public function testResolveOpenAICompat(): void
    {
        $catalog = $this->makeCatalogWithProviders(['openai' => $this->makeOpenAIProvider()]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('gpt-5.1');

        $this->assertFalse($compat->has(ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM));
        $this->assertFalse($compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT));
        $this->assertNull($compat->thinkingFormat); // standard OpenAI
        $this->assertTrue($compat->supportsReasoningEffort);
    }

    // ──────────────────────────────────────────────
    // Provider-level compat fallback
    // ──────────────────────────────────────────────

    public function testUsesProviderLevelCompatWhenModelHasNone(): void
    {
        $model = new AiModelDefinition(
            id: 'glm-5.1',
            reasoning: true,
            thinkingLevelMap: ['medium' => 'enabled'],
            compatibility: null, // no model-level compat
        );

        $provider = new AiProviderConfig(
            id: 'zai',
            enabled: true,
            models: ['glm-5.1' => $model],
            compatibility: new AiCompatibility(
                supportsDeveloperRole: false,
                supportsReasoningEffort: false,
                thinkingFormat: 'zai',
                zaiToolStream: true,
            ),
        );

        $catalog = $this->makeCatalogWithProviders(['zai' => $provider]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('glm-5.1');

        $this->assertTrue($compat->has(ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM));
        $this->assertSame('zai', $compat->thinkingFormat);
        $this->assertFalse($compat->supportsReasoningEffort);
    }

    // ──────────────────────────────────────────────
    // DeepSeek compat
    // ──────────────────────────────────────────────

    public function testResolvesDeepseekCompat(): void
    {
        $model = new AiModelDefinition(
            id: 'deepseek-v4-pro',
            reasoning: true,
            thinkingLevelMap: ['medium' => 'high'],
            compatibility: new AiCompatibility(
                thinkingFormat: 'deepseek',
                requiresReasoningContentOnAssistantMessages: true,
            ),
        );

        $provider = new AiProviderConfig(
            id: 'deepseek',
            enabled: true,
            models: ['deepseek-v4-pro' => $model],
        );

        $catalog = $this->makeCatalogWithProviders(['deepseek' => $provider]);
        $resolver = new HatfieldProviderCompatibilityResolver($catalog);

        $compat = $resolver->resolve('deepseek-v4-pro');

        $this->assertTrue($compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT));
        $this->assertSame('deepseek', $compat->thinkingFormat);
    }

    public function testNonDeepseekModelDoesNotGetReasoningContentFlag(): void
    {
        $deepseekModel = new AiModelDefinition(
            id: 'deepseek-v4-pro',
            reasoning: true,
            thinkingLevelMap: ['medium' => 'high'],
            compatibility: new AiCompatibility(
                thinkingFormat: 'deepseek',
                requiresReasoningContentOnAssistantMessages: true,
            ),
        );

        $llamaModel = new AiModelDefinition(
            id: 'flash',
            reasoning: false,
            thinkingLevelMap: [],
            compatibility: null,
        );

        $catalog = $this->makeCatalogWithProviders([
            'deepseek' => new AiProviderConfig(
                id: 'deepseek',
                enabled: true,
                models: ['deepseek-v4-pro' => $deepseekModel],
            ),
            'llama_cpp' => new AiProviderConfig(
                id: 'llama_cpp',
                enabled: true,
                models: ['flash' => $llamaModel],
            ),
        ]);

        $resolver = new HatfieldProviderCompatibilityResolver($catalog);
        $compat = $resolver->resolve('flash');

        $this->assertFalse($compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT),
            'Non-DeepSeek model should not get reasoning_content flag');
    }
}
