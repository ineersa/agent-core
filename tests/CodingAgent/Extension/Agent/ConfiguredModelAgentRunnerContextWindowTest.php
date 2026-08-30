<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Thesis: public agent()->contextWindow() returns catalog context_window only —
 * never invents a default, never falls back across models/providers.
 * Null catalog (no AI settings) is also null, not a construction TypeError.
 */
final class ConfiguredModelAgentRunnerContextWindowTest extends TestCase
{
    #[Test]
    public function returnsNullWhenCatalogUnavailable(): void
    {
        $platform = $this->createStub(PlatformInterface::class);
        $runner = new ConfiguredModelAgentRunner(
            $platform,
            null,
            new NullLogger(),
            $this->createStub(ToolCallArgumentResolverInterface::class),
            $this->createStub(ModelResolverInterface::class),
            new ProviderRequestPreparer(),
        );

        $this->assertNull($runner->contextWindow('llama_cpp/flash'));
        $this->assertNull($runner->contextWindow('not-a-valid-ref'));
    }

    #[Test]
    public function returnsCatalogContextWindowAndNullForMissingMetadata(): void
    {
        $catalog = new HatfieldModelCatalog(AiConfig::fromArray([
            'providers' => [
                'llama_cpp' => [
                    'type' => 'llama_cpp',
                    'enabled' => true,
                    'base_url' => 'http://127.0.0.1:9052',
                    'models' => [
                        'flash' => [
                            'name' => 'Flash',
                            'context_window' => 131072,
                        ],
                        'no_window' => [
                            'name' => 'No Window',
                        ],
                    ],
                ],
            ],
        ]));

        $platform = $this->createStub(PlatformInterface::class);
        $runner = new ConfiguredModelAgentRunner(
            $platform,
            $catalog,
            new NullLogger(),
            $this->createStub(ToolCallArgumentResolverInterface::class),
            $this->createStub(ModelResolverInterface::class),
            new ProviderRequestPreparer(),
        );

        $this->assertSame(131072, $runner->contextWindow('llama_cpp/flash'));
        $this->assertNull($runner->contextWindow('llama_cpp/no_window'));
        $this->assertNull($runner->contextWindow('llama_cpp/missing'));
        $this->assertNull($runner->contextWindow('not-a-valid-ref'));
        $this->assertNull($runner->contextWindow('unknown/provider-model'));
    }
}
