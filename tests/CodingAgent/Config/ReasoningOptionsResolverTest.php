<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\ReasoningOptionsResolver;
use PHPUnit\Framework\TestCase;

class ReasoningOptionsResolverTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────

    private function resolverForProviders(array $providers): ReasoningOptionsResolver
    {
        $aiConfig = new AiConfig(
            defaultModel: 'test/test-model',
            defaultReasoning: 'medium',
            providers: $providers,
        );

        return new ReasoningOptionsResolver(new HatfieldModelCatalog($aiConfig));
    }

    private function modelRef(string $providerId, string $modelName): AiModelReference
    {
        return new AiModelReference($providerId, $modelName);
    }

    private function model(array $overrides = []): AiModelDefinition
    {
        return new AiModelDefinition(
            id: $overrides['id'] ?? 'test-model',
            reasoning: $overrides['reasoning'] ?? true,
            thinkingLevelMap: $overrides['thinkingLevelMap'] ?? [
                'minimal' => 'low',
                'low' => 'medium',
                'medium' => 'medium',
                'high' => 'high',
                'xhigh' => 'max',
            ],
            compatibility: $overrides['compatibility'] ?? null,
            name: $overrides['name'] ?? null,
            contextWindow: $overrides['contextWindow'] ?? null,
            maxTokens: $overrides['maxTokens'] ?? null,
            input: $overrides['input'] ?? [],
            toolCalling: $overrides['toolCalling'] ?? false,
        );
    }

    private function provider(string $id, AiModelDefinition $model, ?AiCompatibility $compat = null): AiProviderConfig
    {
        return new AiProviderConfig(
            id: $id,
            enabled: true,
            baseUrl: 'https://example.com',
            compatibility: $compat,
            models: [$model->id => $model],
        );
    }

    // ── Off / invalid levels ──────────────────────────────────────────────

    public function testOffLevelReturnsEmpty(): void
    {
        $provider = $this->provider('test', $this->model([
            'reasoning' => true,
            'thinkingLevelMap' => ['medium' => 'medium'],
        ]));

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'off');

        self::assertSame([], $result);
    }

    public function testUnknownLevelReturnsEmpty(): void
    {
        $provider = $this->provider('test', $this->model([
            'reasoning' => true,
            'thinkingLevelMap' => ['medium' => 'medium'],
        ]));

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'super-extreme');

        self::assertSame([], $result);
    }

    // ── Non-reasoning models ──────────────────────────────────────────────

    public function testNonReasoningModelReturnsEmpty(): void
    {
        $provider = $this->provider('llama_cpp', $this->model([
            'id' => 'flash',
            'reasoning' => false,
            'thinkingLevelMap' => [],
        ]));

        $resolver = $this->resolverForProviders(['llama_cpp' => $provider]);
        $result = $resolver->resolve($this->modelRef('llama_cpp', 'flash'), 'high');

        self::assertSame([], $result);
    }

    public function testNonNullReasoningModelReturnsOptions(): void
    {
        $provider = $this->provider('deepseek', $this->model([
            'id' => 'deepseek-v4-pro',
            'reasoning' => true,
            'thinkingLevelMap' => [
                'minimal' => 'high',
                'low' => 'high',
                'medium' => 'high',
                'high' => 'high',
                'xhigh' => 'max',
            ],
        ]));

        $resolver = $this->resolverForProviders(['deepseek' => $provider]);
        $result = $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'medium');

        self::assertSame(['reasoning_effort' => 'high'], $result);
    }

    // ── Missing / empty / null map ────────────────────────────────────────

    public function testEmptyThinkingLevelMapReturnsEmpty(): void
    {
        $provider = $this->provider('test', $this->model([
            'reasoning' => true,
            'thinkingLevelMap' => [],
        ]));

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'high');

        self::assertSame([], $result);
    }

    public function testLevelNotInMapReturnsEmpty(): void
    {
        $provider = $this->provider('test', $this->model([
            'reasoning' => true,
            'thinkingLevelMap' => ['low' => 'low'],
        ]));

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'high');

        self::assertSame([], $result);
    }

    public function testNullMapValueReturnsEmpty(): void
    {
        $provider = $this->provider('test', $this->model([
            'reasoning' => true,
            'thinkingLevelMap' => ['medium' => null],
        ]));

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'medium');

        self::assertSame([], $result);
    }

    // ── Missing model ─────────────────────────────────────────────────────

    public function testUnknownModelReturnsEmpty(): void
    {
        $resolver = $this->resolverForProviders([]);
        $result = $resolver->resolve(new AiModelReference('nobody', 'nothing'), 'high');

        self::assertSame([], $result);
    }

    public function testDisabledProviderReturnsEmpty(): void
    {
        $provider = new AiProviderConfig(
            id: 'deepseek',
            enabled: false,
            baseUrl: 'https://api.deepseek.com',
            models: [
                'deepseek-v4-pro' => $this->model([
                    'id' => 'deepseek-v4-pro',
                    'reasoning' => true,
                    'thinkingLevelMap' => ['medium' => 'high'],
                ]),
            ],
        );

        $resolver = $this->resolverForProviders(['deepseek' => $provider]);
        $result = $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'medium');

        self::assertSame([], $result);
    }

    // ── Default OpenAI-style: reasoning_effort ────────────────────────────

    public function testOpenAiCompatibleEmitsReasoningEffort(): void
    {
        $provider = $this->provider('deepseek', $this->model([
            'id' => 'deepseek-v4-pro',
            'reasoning' => true,
            'thinkingLevelMap' => [
                'minimal' => 'high',
                'low' => 'high',
                'medium' => 'high',
                'high' => 'high',
                'xhigh' => 'max',
            ],
        ]));

        $resolver = $this->resolverForProviders(['deepseek' => $provider]);

        self::assertSame(['reasoning_effort' => 'high'], $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'minimal'));
        self::assertSame(['reasoning_effort' => 'high'], $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'low'));
        self::assertSame(['reasoning_effort' => 'high'], $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'medium'));
        self::assertSame(['reasoning_effort' => 'high'], $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'high'));
        self::assertSame(['reasoning_effort' => 'max'], $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'xhigh'));
    }

    // ── z.ai: enable_thinking ─────────────────────────────────────────────

    public function testZaiEmitsEnableThinkingForAllNonOffLevels(): void
    {
        $provider = $this->provider(
            'zai',
            $this->model([
                'id' => 'glm-5.1',
                'reasoning' => true,
                'thinkingLevelMap' => [
                    'minimal' => 'enabled',
                    'low' => 'enabled',
                    'medium' => 'enabled',
                    'high' => 'enabled',
                    'xhigh' => 'enabled',
                ],
            ]),
            new AiCompatibility(
                supportsDeveloperRole: false,
                supportsReasoningEffort: false,
                thinkingFormat: 'zai',
            ),
        );

        $resolver = $this->resolverForProviders(['zai' => $provider]);

        $expected = ['enable_thinking' => true];

        self::assertSame($expected, $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'minimal'));
        self::assertSame($expected, $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'low'));
        self::assertSame($expected, $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'medium'));
        self::assertSame($expected, $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'high'));
        self::assertSame($expected, $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'xhigh'));
    }

    public function testZaiOffLevelReturnsEmpty(): void
    {
        $provider = $this->provider(
            'zai',
            $this->model([
                'id' => 'glm-5.1',
                'reasoning' => true,
                'thinkingLevelMap' => ['medium' => 'enabled'],
            ]),
            new AiCompatibility(supportsReasoningEffort: false, thinkingFormat: 'zai'),
        );

        $resolver = $this->resolverForProviders(['zai' => $provider]);
        $result = $resolver->resolve($this->modelRef('zai', 'glm-5.1'), 'off');

        self::assertSame([], $result);
    }

    // ── Codex: reasoning.effort (Responses API) ─────────────────────────

    public function testCodexEmitsReasoningEffortFormat(): void
    {
        $provider = $this->provider(
            'openai-codex',
            $this->model([
                'id' => 'gpt-5.5',
                'reasoning' => true,
                'thinkingLevelMap' => [
                    'minimal' => 'low',
                    'low' => 'low',
                    'medium' => 'medium',
                    'high' => 'high',
                    'xhigh' => 'xhigh',
                ],
            ]),
            new AiCompatibility(
                supportsDeveloperRole: false,
                supportsReasoningEffort: false,
                thinkingFormat: 'codex',
            ),
        );

        $resolver = $this->resolverForProviders(['openai-codex' => $provider]);

        self::assertSame(
            ['reasoning' => ['effort' => 'medium', 'summary' => 'auto']],
            $resolver->resolve($this->modelRef('openai-codex', 'gpt-5.5'), 'medium'),
        );

        self::assertSame(
            ['reasoning' => ['effort' => 'xhigh', 'summary' => 'auto']],
            $resolver->resolve($this->modelRef('openai-codex', 'gpt-5.5'), 'xhigh'),
        );
    }

    public function testCodexOffLevelReturnsEmpty(): void
    {
        $provider = $this->provider(
            'openai-codex',
            $this->model([
                'id' => 'gpt-5.5',
                'reasoning' => true,
                'thinkingLevelMap' => ['medium' => 'medium'],
            ]),
            new AiCompatibility(supportsReasoningEffort: false, thinkingFormat: 'codex'),
        );

        $resolver = $this->resolverForProviders(['openai-codex' => $provider]);
        $result = $resolver->resolve($this->modelRef('openai-codex', 'gpt-5.5'), 'off');

        self::assertSame([], $result);
    }

    // ── reasoning_effort is omitted when unsupported ──────────────────────

    public function testReasoningEffortOmittedWhenUnsupported(): void
    {
        $provider = $this->provider(
            'test',
            $this->model([
                'reasoning' => true,
                'thinkingLevelMap' => ['medium' => 'high'],
            ]),
            new AiCompatibility(supportsReasoningEffort: false),
        );

        $resolver = $this->resolverForProviders(['test' => $provider]);
        $result = $resolver->resolve($this->modelRef('test', 'test-model'), 'medium');

        // No supported mechanism (neither zai nor reasoning_effort)
        self::assertSame([], $result);
    }

    // ── Model-level thinkingFormat override ───────────────────────────────

    public function testModelLevelThinkingFormatOverridesProvider(): void
    {
        $model = $this->model([
            'id' => 'special',
            'reasoning' => true,
            'thinkingLevelMap' => ['medium' => 'enabled'],
        ]);

        // Model-level compat overrides provider thinkingFormat
        $provider = new AiProviderConfig(
            id: 'test',
            enabled: true,
            baseUrl: 'https://example.com',
            compatibility: new AiCompatibility(
                supportsReasoningEffort: false,
                thinkingFormat: null, // no thinking format at provider
            ),
            models: ['special' => $model],
        );

        // Use reflection or an anonymous class to inject model-level compat
        // We construct a different model with enough compat
        $modelWithZai = new AiModelDefinition(
            id: 'special',
            reasoning: true,
            thinkingLevelMap: ['medium' => 'enabled'],
            compatibility: new AiCompatibility(thinkingFormat: 'zai'),
        );

        $providerWithModelZai = new AiProviderConfig(
            id: 'test',
            enabled: true,
            baseUrl: 'https://example.com',
            compatibility: new AiCompatibility(supportsReasoningEffort: false, thinkingFormat: null),
            models: ['special' => $modelWithZai],
        );

        $resolver = $this->resolverForProviders(['test' => $providerWithModelZai]);
        $result = $resolver->resolve($this->modelRef('test', 'special'), 'medium');

        // Model says zai → enable_thinking
        self::assertSame(['enable_thinking' => true], $result);
    }

    // ── llama.cpp flash (reasoning: false) ────────────────────────────────

    public function testLlamaCppFlashProducesNoOptions(): void
    {
        $provider = $this->provider('llama_cpp', $this->model([
            'id' => 'flash',
            'reasoning' => false,
            'thinkingLevelMap' => [],
        ]));

        $resolver = $this->resolverForProviders(['llama_cpp' => $provider]);

        self::assertSame([], $resolver->resolve($this->modelRef('llama_cpp', 'flash'), 'off'));
        self::assertSame([], $resolver->resolve($this->modelRef('llama_cpp', 'flash'), 'medium'));
        self::assertSame([], $resolver->resolve($this->modelRef('llama_cpp', 'flash'), 'xhigh'));
    }

    // ── Case insensitivity ────────────────────────────────────────────────

    public function testLevelIsCaseInsensitive(): void
    {
        $provider = $this->provider('deepseek', $this->model([
            'id' => 'deepseek-v4-pro',
            'reasoning' => true,
            'thinkingLevelMap' => ['medium' => 'high'],
        ]));

        $resolver = $this->resolverForProviders(['deepseek' => $provider]);

        self::assertSame(
            ['reasoning_effort' => 'high'],
            $resolver->resolve($this->modelRef('deepseek', 'deepseek-v4-pro'), 'MEDIUM'),
        );
    }
}
