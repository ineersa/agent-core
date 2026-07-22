<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ExecuteShellToolCallWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecuteShellToolCallWorker::class)]
final class ExecuteShellToolCallWorkerTest extends TestCase
{
    /**
     * @var list<RunEvent>
     */
    private array $appendedEvents = [];

    protected function setUp(): void
    {
        $this->appendedEvents = [];
    }

    /**
     * Thesis: ExecuteShellToolCallWorker must write tool_execution_start,
     * tool_execution_end, and (standalone) AgentEnd in strict ascending
     * seq order from a single process, so EventStore ordering is
     * deterministic and LifecycleOrderValidator-conformant.
     *
     * Regression: async dispatch must not let AgentEnd race ahead of
     * tool_exec events (issue #183).
     */
    public function testStandaloneWritesEventsInOrder(): void
    {
        $eventStore = $this->createEventStore();
        $toolExecutor = $this->createToolExecutor('hello');

        $worker = new ExecuteShellToolCallWorker($toolExecutor, $eventStore);
        $worker(new ExecuteShellToolCall(
            runId: 'run-standalone',
            turnNo: 2,
            toolCallId: 'sh_tc_1',
            commandText: 'echo hello',
            standalone: true,
        ));

        $this->assertCount(3, $this->appendedEvents, 'Standalone shell must produce 3 events.');

        // Seq 1: tool_execution_start
        $this->assertSame(1, $this->appendedEvents[0]->seq);
        $this->assertSame(RunEventTypeEnum::ToolExecutionStart->value, $this->appendedEvents[0]->type);
        $this->assertSame(2, $this->appendedEvents[0]->turnNo);
        $this->assertSame('sh_tc_1', $this->appendedEvents[0]->payload['tool_call_id'] ?? null);

        // Seq 2: tool_execution_end
        $this->assertSame(2, $this->appendedEvents[1]->seq);
        $this->assertSame(RunEventTypeEnum::ToolExecutionEnd->value, $this->appendedEvents[1]->type);
        $this->assertSame(2, $this->appendedEvents[1]->turnNo);
        $this->assertSame('sh_tc_1', $this->appendedEvents[1]->payload['tool_call_id'] ?? null);
        $this->assertStringContainsString('hello', (string) ($this->appendedEvents[1]->payload['result'] ?? ''));

        // Seq 3: agent_end (final event, written only for standalone)
        $this->assertSame(3, $this->appendedEvents[2]->seq);
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $this->appendedEvents[2]->type);
        $this->assertSame('completed', $this->appendedEvents[2]->payload['reason'] ?? null);

        // Ascending seq order
        for ($i = 1; $i < \count($this->appendedEvents); ++$i) {
            $this->assertGreaterThan(
                $this->appendedEvents[$i - 1]->seq,
                $this->appendedEvents[$i]->seq,
                \sprintf('Event at index %d must have seq > previous', $i),
            );
        }

        // AgentEnd must be the final lifecycle event.
        $this->assertSame(
            RunEventTypeEnum::AgentEnd->value,
            $this->appendedEvents[array_key_last($this->appendedEvents)]->type,
            'AgentEnd must be the final event for standalone shell commands.',
        );
    }

    /**
     * Thesis: Non-standalone shell commands (subsequent !cmd during an
     * agent run) must NOT write AgentEnd.  The run is terminated by a
     * separate complete_run command or by the LLM turn's own RunCompleted.
     * Writing AgentEnd here would prematurely terminate the agent run.
     */
    public function testNonStandaloneDoesNotWriteAgentEnd(): void
    {
        $eventStore = $this->createEventStore();
        $toolExecutor = $this->createToolExecutor('result');

        $worker = new ExecuteShellToolCallWorker($toolExecutor, $eventStore);
        $worker(new ExecuteShellToolCall(
            runId: 'run-inline',
            turnNo: 2,
            toolCallId: 'sh_tc_2',
            commandText: 'echo inline',
            standalone: false,
        ));

        $this->assertCount(2, $this->appendedEvents, 'Non-standalone shell must produce only tool_exec events.');
        $this->assertSame(RunEventTypeEnum::ToolExecutionStart->value, $this->appendedEvents[0]->type);
        $this->assertSame(RunEventTypeEnum::ToolExecutionEnd->value, $this->appendedEvents[1]->type);

        // No AgentEnd.
        foreach ($this->appendedEvents as $event) {
            $this->assertNotSame(
                RunEventTypeEnum::AgentEnd->value,
                $event->type,
                'Non-standalone shell must not emit AgentEnd.',
            );
        }
    }

    /**
     * Creates an in-memory EventStore that collects appended events for assertion.
     */
    private function createEventStore(): EventStoreInterface
    {
        return new class($this->appendedEvents) implements EventStoreInterface {
            /** @var list<RunEvent> */
            private array $collector;

            /** @param list<RunEvent> &$collector reference to the test-local collection */
            public function __construct(array &$collector)
            {
                $this->collector = &$collector;
            }

            public function append(RunEvent $event): RunEvent
            {
                $seq = \count(array_filter($this->collector, static fn (RunEvent $e): bool => $e->runId === $event->runId)) + 1;
                $persisted = new RunEvent($event->runId, $seq, $event->turnNo, $event->type, $event->payload, $event->createdAt);
                $this->collector[] = $persisted;

                return $persisted;
            }

            public function appendMany(array $events): array
            {
                $out = [];
                foreach ($events as $event) {
                    $out[] = $this->append($event);
                }

                return $out;
            }

            /**
             * @return list<RunEvent>
             */
            public function allFor(string $runId): array
            {
                return array_values(
                    array_filter(
                        $this->collector,
                        static fn (RunEvent $e): bool => $e->runId === $runId,
                    ),
                );
            }
        };
    }

    /**
     * Creates a stubbed ToolExecutor that returns a fixed result text.
     */
    private function createToolExecutor(string $resultText): ToolExecutorInterface
    {
        return new class($resultText) implements ToolExecutorInterface {
            public function __construct(private readonly string $resultText)
            {
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => $this->resultText]],
                    isError: false,
                );
            }
        };
    }
}
