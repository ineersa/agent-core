<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallResult;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyToolExecutorAdapter;
use PHPUnit\Framework\TestCase;

final class SymfonyToolExecutorAdapterTest extends TestCase
{
    public function testAdapterFallsBackWhenSymfonyToolboxIsUnavailable(): void
    {
        $adapter = new SymfonyToolExecutorAdapter(
            fallbackExecutor: new ToolExecutor('sequential', 30, 2),
            toolbox: null,
            beforeToolCallHooks: [],
            afterToolCallHooks: [],
        );

        $result = $adapter->execute(new ToolCall(
            toolCallId: 'call-1',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 0,
        ));

        self::assertTrue($result->isError);
        self::assertStringContainsString('not implemented yet', $result->content[0]['text']);
    }

    public function testAdapterExecutesThroughToolboxAndAppliesHooks(): void
    {
        $before = new class implements BeforeToolCallHookInterface {
            public function beforeToolCall(BeforeToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?BeforeToolCallResult
            {
                return BeforeToolCallResult::allow();
            }
        };

        $after = new class implements AfterToolCallHookInterface {
            public function afterToolCall(AfterToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?AfterToolCallResult
            {
                return AfterToolCallResult::withDetails(['hook' => 'applied'])->withIsError(false);
            }
        };

        $toolbox = new class {
            public function execute(object $toolCall): object
            {
                return new class {
                    public function getResult(): mixed
                    {
                        return ['status' => 'ok'];
                    }

                    public function getSources(): array
                    {
                        return ['source-a'];
                    }
                };
            }
        };

        if (!class_exists('Symfony\\AI\\Platform\\Result\\ToolCall')) {
            eval('namespace Symfony\\AI\\Platform\\Result; final class ToolCall { public function __construct(public string $id, public string $name, public array $arguments = []) {} }');
        }

        $adapter = new SymfonyToolExecutorAdapter(
            fallbackExecutor: new ToolExecutor('sequential', 30, 2),
            toolbox: $toolbox,
            beforeToolCallHooks: [$before],
            afterToolCallHooks: [$after],
        );

        $toolCall = new ToolCall(
            toolCallId: 'call-2',
            toolName: 'web_search',
            arguments: ['query' => 'agent core'],
            orderIndex: 0,
        );

        $result = $adapter->execute($toolCall);
        self::assertFalse($result->isError);
        self::assertSame(['hook' => 'applied'], $result->details);

        $payload = $adapter->toToolCallMessagePayload($toolCall, $result);
        self::assertSame('tool', $payload['role']);
        self::assertSame('call-2', $payload['tool_call']['id']);

        $update = $adapter->toProgressUpdate('call-2', 'web_search', 'half-way', 50);
        self::assertSame('tool_execution_update', $update['type']);
        self::assertSame(50, $update['progress']);
    }
}
