<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ZaiToolStreamFeatureShaper;
use PHPUnit\Framework\TestCase;

final class ProviderCompatibilityRequestShaperTest extends TestCase
{
    public function testPassesProviderOptionsThroughWhenNoCompatibilityFeatureMatches(): void
    {
        $pipeline = new ProviderCompatibilityRequestShaper([new ZaiToolStreamFeatureShaper()]);
        $model = new ResolvedModel('some-model');

        $result = $pipeline->shape($model, ['key' => 'value'], ['stream' => true]);

        $this->assertSame('some-model', $result['model']);
        $this->assertSame(['key' => 'value'], $result['input']);
        $this->assertSame(['stream' => true], $result['options']);
    }

    public function testAppliesOnlyFeaturesDeclaredOnResolvedModel(): void
    {
        $pipeline = new ProviderCompatibilityRequestShaper([
            new ZaiToolStreamFeatureShaper(),
            new ReasoningOptionsFeatureShaper(),
        ]);
        $model = new ResolvedModel(
            model: 'glm-5.1',
            compatFeatures: [
                ZaiToolStreamFeatureShaper::FEATURE,
                ReasoningOptionsFeatureShaper::FEATURE,
            ],
            reasoningOptions: ['thinking' => ['type' => 'enabled', 'clear_thinking' => false]],
        );

        $result = $pipeline->shape($model, [], ['stream' => true]);

        $this->assertSame([
            'stream' => true,
            'tool_stream' => true,
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
        ], $result['options']);
    }
}
