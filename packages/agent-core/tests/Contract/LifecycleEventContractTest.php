<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Contract;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Event\Lifecycle\AgentEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\AgentStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageUpdateEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionUpdateEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\TurnEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\TurnStartEvent;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleEventContractTest extends TestCase
{
    #[DataProvider('validFlowProvider')]
    public function testLifecycleOrderForMainFlows(array $events): void
    {
        self::assertSame([], CoreLifecycleEventType::validateOrder($events));
    }

    /**
     * @return array<string, array{0: list<RunEvent>}>
     */
    public static function validFlowProvider(): array
    {
        $runId = 'run-stage-01';

        return [
            'prompt' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'user']),
                new MessageEndEvent($runId, 4, 1, ['message_role' => 'user']),
                new MessageStartEvent($runId, 5, 1, ['message_role' => 'assistant']),
                new MessageUpdateEvent($runId, 6, 1, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 7, 1, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 8, 1),
                new AgentEndEvent($runId, 9, 1),
            ]],
            'continue' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'assistant']),
                new MessageUpdateEvent($runId, 4, 1, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 5, 1, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 6, 1),
                new AgentEndEvent($runId, 7, 1),
            ]],
            'tools' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'assistant']),
                new MessageUpdateEvent($runId, 4, 1, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 5, 1, ['message_role' => 'assistant', 'has_tool_calls' => true]),
                new ToolExecutionStartEvent($runId, 6, 1, ['tool_call_id' => 'tool-1', 'order_index' => 0]),
                new ToolExecutionUpdateEvent($runId, 7, 1, ['tool_call_id' => 'tool-1']),
                new ToolExecutionEndEvent($runId, 8, 1, ['tool_call_id' => 'tool-1', 'order_index' => 0]),
                new MessageStartEvent($runId, 9, 1, ['message_role' => 'tool']),
                new MessageEndEvent($runId, 10, 1, ['message_role' => 'tool']),
                new ToolExecutionStartEvent($runId, 11, 1, ['tool_call_id' => 'tool-2', 'order_index' => 1]),
                new ToolExecutionEndEvent($runId, 12, 1, ['tool_call_id' => 'tool-2', 'order_index' => 1]),
                new MessageStartEvent($runId, 13, 1, ['message_role' => 'tool']),
                new MessageEndEvent($runId, 14, 1, ['message_role' => 'tool']),
                new TurnEndEvent($runId, 15, 1),
                new AgentEndEvent($runId, 16, 1),
            ]],
            'steering' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'user']),
                new MessageEndEvent($runId, 4, 1, ['message_role' => 'user']),
                new MessageStartEvent($runId, 5, 1, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 6, 1, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 7, 1),
                new TurnStartEvent($runId, 8, 2),
                new MessageStartEvent($runId, 9, 2, ['message_role' => 'user']),
                new MessageEndEvent($runId, 10, 2, ['message_role' => 'user']),
                new MessageStartEvent($runId, 11, 2, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 12, 2, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 13, 2),
                new AgentEndEvent($runId, 14, 2),
            ]],
            'follow_up' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 4, 1, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 5, 1),
                new TurnStartEvent($runId, 6, 2),
                new MessageStartEvent($runId, 7, 2, ['message_role' => 'user']),
                new MessageEndEvent($runId, 8, 2, ['message_role' => 'user']),
                new MessageStartEvent($runId, 9, 2, ['message_role' => 'assistant']),
                new MessageEndEvent($runId, 10, 2, ['message_role' => 'assistant', 'has_tool_calls' => false]),
                new TurnEndEvent($runId, 11, 2),
                new AgentEndEvent($runId, 12, 2),
            ]],
            'cancel' => [[
                new AgentStartEvent($runId, 1, 0),
                new TurnStartEvent($runId, 2, 1),
                new MessageStartEvent($runId, 3, 1, ['message_role' => 'user']),
                new MessageEndEvent($runId, 4, 1, ['message_role' => 'user']),
                new TurnEndEvent($runId, 5, 1),
                new AgentEndEvent($runId, 6, 1),
            ]],
        ];
    }

    public function testExtensionEventCanBeInsertedAtBoundaryPoint(): void
    {
        $runId = 'run-stage-01';

        $events = [
            new AgentStartEvent($runId, 1, 0),
            new TurnStartEvent($runId, 2, 1),
            new MessageStartEvent($runId, 3, 1, ['message_role' => 'assistant']),
            new MessageEndEvent($runId, 4, 1, ['message_role' => 'assistant', 'has_tool_calls' => false]),
            new RunEvent($runId, 5, 1, 'ext_compaction_start', ['strategy' => 'summary']),
            new TurnEndEvent($runId, 6, 1),
            new AgentEndEvent($runId, 7, 1),
        ];

        self::assertSame([], CoreLifecycleEventType::validateOrder($events));
    }

    public function testExtensionEventCannotCrossAssistantToolBarrier(): void
    {
        $runId = 'run-stage-01';

        $events = [
            new AgentStartEvent($runId, 1, 0),
            new TurnStartEvent($runId, 2, 1),
            new MessageStartEvent($runId, 3, 1, ['message_role' => 'assistant']),
            new MessageEndEvent($runId, 4, 1, ['message_role' => 'assistant', 'has_tool_calls' => true]),
            new RunEvent($runId, 5, 1, 'ext_compaction_start', ['strategy' => 'summary']),
            new ToolExecutionStartEvent($runId, 6, 1, ['tool_call_id' => 'tool-1', 'order_index' => 0]),
            new ToolExecutionEndEvent($runId, 7, 1, ['tool_call_id' => 'tool-1', 'order_index' => 0]),
            new TurnEndEvent($runId, 8, 1),
            new AgentEndEvent($runId, 9, 1),
        ];

        $violations = CoreLifecycleEventType::validateOrder($events);

        self::assertNotEmpty($violations);
        self::assertStringContainsString(
            'cannot be emitted between assistant "message_end" and tool preflight start',
            implode("\n", $violations),
        );
    }
}
