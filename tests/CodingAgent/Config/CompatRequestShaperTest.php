<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompatRequestShaper;
use Ineersa\CodingAgent\Config\ConfigProvider;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\TestCase;

final class CompatRequestShaperTest extends TestCase
{
    /**
     * Build an AppConfig with the given AI providers and return an object
     * that satisfies the shape CompatRequestShaper needs (resolve()→AppConfig).
     *
     * AppConfigResolver is final and cannot be mocked, so we use an
     * anonymous class.
     */
    private function resolverWithProviders(array $providers): object
    {
        $aiConfig = new AiConfig(
            defaultModel: 'zai/glm-5.1',
            defaultReasoning: 'medium',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        return new /** @internal */ class($appConfig) implements ConfigProvider {
            public function __construct(private readonly AppConfig $config) {}
            public function resolve(string $_ = ''): AppConfig { return $this->config; }
        };
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
    // z.ai: enable_thinking emission
    // ──────────────────────────────────────────────

    public function testZaiEnablesThinkingWhenReasoningIsNonOff(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'glm-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'medium'],
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayHasKey('enable_thinking', $result->options);
        $this->assertTrue($result->options['enable_thinking']);
    }

    public function testZaiDoesNotEnableThinkingWhenReasoningIsOff(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'glm-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'off'],
        );

        // enable_thinking should NOT be present; only the developer-role flag remains
        $suppressKey = CompatRequestShaper::SUPPRESS_DEVELOPER_ROLE_KEY;
        if (null !== $result && null !== $result->options) {
            $this->assertArrayNotHasKey('enable_thinking', $result->options);
            $this->assertArrayHasKey($suppressKey, $result->options);
        } else {
            // null result means nothing changed — fine if no compat triggered
            $this->assertNull($result);
        }
    }

    // ──────────────────────────────────────────────
    // z.ai: reasoning_effort suppression
    // ──────────────────────────────────────────────

    public function testZaiNeverIncludesReasoningEffort(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'glm-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'high'],
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayNotHasKey('reasoning_effort', $result->options);
        $this->assertArrayHasKey('enable_thinking', $result->options);
    }

    // ──────────────────────────────────────────────
    // OpenAI: reasoning_effort is included
    // ──────────────────────────────────────────────

    public function testOpenAIIncludesReasoningEffort(): void
    {
        $resolver = $this->resolverWithProviders(['openai' => $this->makeOpenAIProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'gpt-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'high'],
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayHasKey('reasoning_effort', $result->options);
        $this->assertSame('high', $result->options['reasoning_effort']);
    }

    // ──────────────────────────────────────────────
    // Developer-role suppression flag
    // ──────────────────────────────────────────────

    public function testSetsSuppressDeveloperRoleFlagWhenProviderDisablesIt(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider(false)]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest('glm-5.1', [], []);

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayHasKey(CompatRequestShaper::SUPPRESS_DEVELOPER_ROLE_KEY, $result->options);
        $this->assertTrue($result->options[CompatRequestShaper::SUPPRESS_DEVELOPER_ROLE_KEY]);
    }

    public function testDoesNotSetSuppressDeveloperRoleFlagWhenProviderSupportsIt(): void
    {
        $resolver = $this->resolverWithProviders(['openai' => $this->makeOpenAIProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest('gpt-5.1', [], []);

        // No reasoning key, no compat quirks → nothing changed
        if (null !== $result && null !== $result->options) {
            $this->assertArrayNotHasKey(CompatRequestShaper::SUPPRESS_DEVELOPER_ROLE_KEY, $result->options);
        } else {
            $this->assertNull($result, 'Expected no ProviderRequest when nothing changed.');
        }
    }

    // ──────────────────────────────────────────────
    // No-op edge cases
    // ──────────────────────────────────────────────

    public function testReturnsNullWhenModelNotFoundInCatalog(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest('nonexistent-model', [], []);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenNoAiConfig(): void
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            catalog: null,
        );

        $resolver = new /** @internal */ class($appConfig) implements ConfigProvider {
            public function __construct(private readonly AppConfig $config) {}
            public function resolve(string $_ = ''): AppConfig { return $this->config; }
        };

        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest('glm-5.1', [], []);

        $this->assertNull($result);
    }

    public function testStripsInternalReasoningKeyFromOptions(): void
    {
        $resolver = $this->resolverWithProviders(['zai' => $this->makeZaiProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'glm-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'medium'],
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayNotHasKey(CompatRequestShaper::REASONING_KEY, $result->options);
    }

    public function testReturnsNullWhenNoOptionsChanged(): void
    {
        // OpenAI model with no reasoning key and no compat quirks → nothing changes
        $resolver = $this->resolverWithProviders(['openai' => $this->makeOpenAIProvider()]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest('gpt-5.1', [], []);

        $this->assertNull($result);
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
            ),
        );

        $resolver = $this->resolverWithProviders(['zai' => $provider]);
        $shaper = new CompatRequestShaper($resolver);

        $result = $shaper->beforeProviderRequest(
            'glm-5.1',
            [],
            [CompatRequestShaper::REASONING_KEY => 'medium'],
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->options);
        $this->assertArrayHasKey('enable_thinking', $result->options);
        $this->assertArrayHasKey(CompatRequestShaper::SUPPRESS_DEVELOPER_ROLE_KEY, $result->options);
    }
}
