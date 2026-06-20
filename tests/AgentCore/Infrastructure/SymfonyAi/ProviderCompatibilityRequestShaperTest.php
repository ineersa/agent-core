<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningContentFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ZaiToolStreamFeatureShaper;
use PHPUnit\Framework\TestCase;

final class ProviderCompatibilityRequestShaperTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Internal key stripping
    // ──────────────────────────────────────────────

    public function testStripsInternalOptionKeys(): void
    {
        $shaper = new ProviderCompatibilityRequestShaper([]);

        $result = $shaper->shape('test-model', [], [
            ProviderRequestOptionKeys::REASONING => 'medium',
            ProviderRequestOptionKeys::REASONING_OPTIONS => ['enable_thinking' => true],
            'stream' => true,
        ]);

        $this->assertArrayNotHasKey(ProviderRequestOptionKeys::REASONING, $result['options']);
        $this->assertArrayNotHasKey(ProviderRequestOptionKeys::REASONING_OPTIONS, $result['options']);
        $this->assertArrayHasKey('stream', $result['options']);
    }

    public function testStripsCompatFeaturesKey(): void
    {
        $shaper = new ProviderCompatibilityRequestShaper([]);

        $result = $shaper->shape('test-model', [], [
            ProviderRequestOptionKeys::COMPAT_FEATURES => [ZaiToolStreamFeatureShaper::FEATURE],
        ]);

        $this->assertArrayNotHasKey(ProviderRequestOptionKeys::COMPAT_FEATURES, $result['options']);
    }

    // ──────────────────────────────────────────────
    // Feature shaper iteration
    // ──────────────────────────────────────────────

    public function testIteratesMatchingFeatureShapers(): void
    {
        $shaper = new ZaiToolStreamFeatureShaperTest();
        $pipeline = new ProviderCompatibilityRequestShaper([$shaper]);

        $result = $pipeline->shape('glm-5.1', [], [
            ProviderRequestOptionKeys::COMPAT_FEATURES => [ZaiToolStreamFeatureShaper::FEATURE],
        ]);

        $this->assertArrayHasKey('tool_stream', $result['options']);
        $this->assertTrue($result['options']['tool_stream']);
    }

    public function testSkipsNonMatchingFeatureShapers(): void
    {
        $shaper = new ZaiToolStreamFeatureShaperTest();
        $pipeline = new ProviderCompatibilityRequestShaper([$shaper]);

        $result = $pipeline->shape('some-model', [], [
            ProviderRequestOptionKeys::COMPAT_FEATURES => [], // no flags
        ]);

        $this->assertArrayNotHasKey('tool_stream', $result['options']);
    }

    public function testMultipleFeatureShapersChain(): void
    {
        $shaperA = new ZaiToolStreamFeatureShaperTest();

        $shaperB = new class implements ProviderCompatibilityFeatureShaperInterface {
            public function supports(array $compatFeatures): bool
            {
                return \in_array(ReasoningContentFeatureShaper::FEATURE, $compatFeatures, true);
            }

            public function shape(
                string $model,
                array $input,
                array $options,
                array $compatFeatures,
            ): ?ProviderRequest {
                return new ProviderRequest(options: array_merge($options, ['reasoning_content_injected' => true]));
            }
        };

        $pipeline = new ProviderCompatibilityRequestShaper([$shaperA, $shaperB]);

        $result = $pipeline->shape('test-model', [], [
            ProviderRequestOptionKeys::COMPAT_FEATURES => [
                ZaiToolStreamFeatureShaper::FEATURE,
                ReasoningContentFeatureShaper::FEATURE,
            ],
        ]);

        $this->assertArrayHasKey('tool_stream', $result['options']);
        $this->assertTrue($result['options']['tool_stream']);
        $this->assertArrayHasKey('reasoning_content_injected', $result['options']);
        $this->assertTrue($result['options']['reasoning_content_injected']);
    }

    // ──────────────────────────────────────────────
    // Completeness: model/input/options returned
    // ──────────────────────────────────────────────

    public function testReturnsModelInputOptionsTriple(): void
    {
        $shaper = new ProviderCompatibilityRequestShaper([]);

        $result = $shaper->shape('test-model', ['key' => 'value'], []);

        $this->assertSame('test-model', $result['model']);
        $this->assertSame(['key' => 'value'], $result['input']);
        $this->assertIsArray($result['options']);
    }

    // ──────────────────────────────────────────────
    // Reasoning options merging
    // ──────────────────────────────────────────────

    public function testReasoningOptionsPassedToShapersButStrippedAfter(): void
    {
        // Simulate what ReasoningOptionsFeatureShaper does: read _hatfield_reasoning_options, merge, strip
        $reasoningShaper = new class implements ProviderCompatibilityFeatureShaperInterface {
            public function supports(array $compatFeatures): bool
            {
                return \in_array(ReasoningOptionsFeatureShaper::FEATURE, $compatFeatures, true);
            }

            public function shape(
                string $model,
                array $input,
                array $options,
                array $compatFeatures,
            ): ?ProviderRequest {
                $ro = $options[ProviderRequestOptionKeys::REASONING_OPTIONS] ?? null;
                if (!\is_array($ro) || [] === $ro) {
                    return null;
                }

                $newOptions = $options;
                unset($newOptions[ProviderRequestOptionKeys::REASONING_OPTIONS]);

                return new ProviderRequest(options: array_merge($newOptions, $ro));
            }
        };

        $pipeline = new ProviderCompatibilityRequestShaper([$reasoningShaper]);

        $result = $pipeline->shape('glm-5.1', [], [
            ProviderRequestOptionKeys::COMPAT_FEATURES => [ReasoningOptionsFeatureShaper::FEATURE],
            ProviderRequestOptionKeys::REASONING_OPTIONS => ['enable_thinking' => true],
        ]);

        $this->assertArrayHasKey('enable_thinking', $result['options']);
        $this->assertTrue($result['options']['enable_thinking']);
        $this->assertArrayNotHasKey(ProviderRequestOptionKeys::REASONING_OPTIONS, $result['options']);
    }
}

/**
 * Minimal test double for ZaiToolStreamFeatureShaper.
 */
final readonly class ZaiToolStreamFeatureShaperTest implements ProviderCompatibilityFeatureShaperInterface
{
    public const string FEATURE = ZaiToolStreamFeatureShaper::FEATURE;

    public function supports(array $compatFeatures): bool
    {
        return \in_array(self::FEATURE, $compatFeatures, true);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        array $compatFeatures,
    ): ?ProviderRequest {
        return new ProviderRequest(options: array_merge($options, ['tool_stream' => true]));
    }
}
