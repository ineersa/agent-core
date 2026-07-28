<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Thesis: AgentCallRequestDTO.thinkingLevel is forwarded as thinking_level in platform options.
 */
final class ConfiguredModelAgentRunnerThinkingLevelTest extends TestCase
{
    #[Test]
    public function runInjectsNonEmptyThinkingLevelIntoPlatformOptions(): void
    {
        $captured = null;
        $platform = $this->capturingPlatform($captured);

        $runner = new ConfiguredModelAgentRunner($platform, null, new NullLogger());
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
            thinkingLevel: 'high',
        ));

        $this->assertIsArray($captured);
        $this->assertSame('high', $captured['thinking_level'] ?? null);
        $this->assertTrue($captured['stream'] ?? false);
    }

    #[Test]
    public function runOmitsBlankThinkingLevel(): void
    {
        $captured = null;
        $platform = $this->capturingPlatform($captured);

        $runner = new ConfiguredModelAgentRunner($platform, null, new NullLogger());
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
            thinkingLevel: '   ',
        ));

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('thinking_level', $captured);
    }

    /**
     * @param array<string, mixed>|null $captured
     */
    private function capturingPlatform(?array &$captured): PlatformInterface
    {
        return new class($captured) implements PlatformInterface {
            /** @param array<string, mixed>|null $captured */
            public function __construct(private ?array &$captured)
            {
            }

            public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
            {
                $this->captured = $options;
                $result = new TextResult('ok');
                $raw = new InMemoryRawResult(['text' => 'ok'], [], (object) ['text' => 'ok']);

                return new DeferredResult(new PlainConverter($result), $raw, $options);
            }

            public function getModelCatalog(): ModelCatalogInterface
            {
                return new FallbackModelCatalog();
            }
        };
    }
}
