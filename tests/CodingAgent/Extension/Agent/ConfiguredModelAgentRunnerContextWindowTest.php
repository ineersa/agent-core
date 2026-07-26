<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Thesis: public agent()->contextWindow() returns catalog context_window only —
 * never invents a default, never falls back across models/providers.
 */
final class ConfiguredModelAgentRunnerContextWindowTest extends IsolatedKernelTestCase
{
    #[Test]
    public function containerWiresProductionRunnerWithCatalogResolution(): void
    {
        /** @var AgentRunnerInterface $runner */
        $runner = self::getContainer()->get(AgentRunnerInterface::class);

        $this->assertInstanceOf(ConfiguredModelAgentRunner::class, $runner);
        // Missing catalog entry is null (no invented default). Present models return
        // whatever the loaded catalog stores (nullable int from settings).
        $this->assertNull($runner->contextWindow('missing/provider-model'));
        $window = $runner->contextWindow('llama_cpp_test/test');
        $this->assertTrue(null === $window || (\is_int($window) && $window > 0));
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
        $runner = new ConfiguredModelAgentRunner($platform, $catalog, new NullLogger());

        $this->assertSame(131072, $runner->contextWindow('llama_cpp/flash'));
        $this->assertNull($runner->contextWindow('llama_cpp/no_window'));
        $this->assertNull($runner->contextWindow('llama_cpp/missing'));
        $this->assertNull($runner->contextWindow('not-a-valid-ref'));
        $this->assertNull($runner->contextWindow('unknown/provider-model'));
    }
}
