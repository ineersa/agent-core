<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;
use Ineersa\AgentCore\Contract\ProviderCompatibilityResolverInterface;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use PHPUnit\Framework\TestCase;

final class ProviderCompatibilityRequestShaperTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Internal key stripping
    // ──────────────────────────────────────────────

    public function testStripsInternalOptionKeys(): void
    {
        $resolver = new class implements ProviderCompatibilityResolverInterface {
            public function resolve(string $model): ProviderCompatibility
            {
                return new ProviderCompatibility();
            }
        };

        $shaper = new ProviderCompatibilityRequestShaper($resolver, []);

        $result = $shaper->shape('test-model', [], [
            ProviderRequestOptionKeys::REASONING => 'medium',
            'stream' => true,
        ]);

        $this->assertArrayNotHasKey(ProviderRequestOptionKeys::REASONING, $result['options']);
        $this->assertArrayHasKey('stream', $result['options']);
    }

    // ──────────────────────────────────────────────
    // Feature shaper iteration
    // ──────────────────────────────────────────────

    public function testIteratesMatchingFeatureShapers(): void
    {
        $resolver = new class implements ProviderCompatibilityResolverInterface {
            public function resolve(string $model): ProviderCompatibility
            {
                return new ProviderCompatibility(
                    options: [ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM],
                );
            }
        };

        $shaper = new ZaiToolStreamShaper();
        $pipeline = new ProviderCompatibilityRequestShaper($resolver, [$shaper]);

        $result = $pipeline->shape('glm-5.1', [], []);

        $this->assertArrayHasKey('tool_stream', $result['options']);
        $this->assertTrue($result['options']['tool_stream']);
    }

    public function testSkipsNonMatchingFeatureShapers(): void
    {
        $resolver = new class implements ProviderCompatibilityResolverInterface {
            public function resolve(string $model): ProviderCompatibility
            {
                return new ProviderCompatibility(); // no flags
            }
        };

        $shaper = new ZaiToolStreamShaper();
        $pipeline = new ProviderCompatibilityRequestShaper($resolver, [$shaper]);

        $result = $pipeline->shape('some-model', [], []);

        $this->assertArrayNotHasKey('tool_stream', $result['options']);
    }

    public function testMultipleFeatureShapersChain(): void
    {
        $resolver = new class implements ProviderCompatibilityResolverInterface {
            public function resolve(string $model): ProviderCompatibility
            {
                return new ProviderCompatibility(
                    options: [
                        ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM,
                        ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT,
                    ],
                );
            }
        };

        // Shaper that adds option A
        $shaperA = new ZaiToolStreamShaper();

        // Shaper that adds option B
        $shaperB = new class implements ProviderCompatibilityFeatureShaperInterface {
            public function supports(ProviderCompatibility $compat): bool
            {
                return $compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT);
            }

            public function shape(
                string $model,
                array $input,
                array $options,
                ProviderCompatibility $compat,
            ): ?ProviderRequest {
                return new ProviderRequest(options: array_merge($options, ['reasoning_content_injected' => true]));
            }
        };

        $pipeline = new ProviderCompatibilityRequestShaper($resolver, [$shaperA, $shaperB]);

        $result = $pipeline->shape('test-model', [], []);

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
        $resolver = new class implements ProviderCompatibilityResolverInterface {
            public function resolve(string $model): ProviderCompatibility
            {
                return new ProviderCompatibility();
            }
        };

        $shaper = new ProviderCompatibilityRequestShaper($resolver, []);

        $result = $shaper->shape('test-model', ['key' => 'value'], []);

        $this->assertSame('test-model', $result['model']);
        $this->assertSame(['key' => 'value'], $result['input']);
        $this->assertIsArray($result['options']);
    }
}

/**
 * Minimal implementation for testing — mirrors the real ZaiToolStreamCompatShaper.
 */
final readonly class ZaiToolStreamShaper implements ProviderCompatibilityFeatureShaperInterface
{
    public function supports(ProviderCompatibility $compat): bool
    {
        return $compat->has(ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        ProviderCompatibility $compat,
    ): ?ProviderRequest {
        return new ProviderRequest(options: array_merge($options, ['tool_stream' => true]));
    }
}
