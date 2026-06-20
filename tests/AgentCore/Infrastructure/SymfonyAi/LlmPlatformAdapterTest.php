<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter
 */
final class LlmPlatformAdapterTest extends TestCase
{
    public function testExtraOptionsAreForwardedAndNoToolsFlagWins(): void
    {
        $options = $this->buildInputOptions(new ModelInvocationRequest(
            model: 'test/provider',
            input: new ModelInvocationInput(
                runId: 'run-1',
                turnNo: 7,
                toolsRef: 'toolset:run-1:turn-7',
            ),
            options: new ModelInvocationOptions(
                extraOptions: [
                    'thinking_level' => 'low',
                    'tools' => ['should-not-survive'],
                    'temperature' => 0.2,
                ],
                toolsEnabled: false,
            ),
        ));

        self::assertSame('toolset:run-1:turn-7', $options['tools_ref']);
        self::assertSame(7, $options['turn_no']);
        self::assertSame('run-1', $options['run_id']);
        self::assertSame('low', $options['thinking_level']);
        self::assertSame(0.2, $options['temperature']);
        self::assertSame([], $options['tools'], 'toolsEnabled:false must override any tools key from generic extra options.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputOptions(ModelInvocationRequest $request): array
    {
        $reflection = new ReflectionClass(LlmPlatformAdapter::class);
        $adapter = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildInputOptions');

        /** @var array<string, mixed> $options */
        $options = $method->invoke($adapter, $request);

        return $options;
    }
}
