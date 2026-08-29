<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
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
     * The execution worker only writes the in-flight start event. Completion
     * is a durable ToolCallResult routed to run_control, the sole writer of
     * completion and standalone terminal events.
     */
    public function testStandaloneDispatchesResultToRunControl(): void
    {
        $eventStore = $this->createEventStore();
        $toolExecutor = $this->createToolExecutor('hello');
        $commandBus = new TestMessageBus();
        $worker = new ExecuteShellToolCallWorker($toolExecutor, $eventStore, $commandBus);
        $worker(new ExecuteShellToolCall(
            runId: 'run-standalone',
            turnNo: 2,
            stepId: 'shell-step',
            attempt: 2,
            toolCallId: 'sh_tc_1',
            commandText: 'echo hello',
            standalone: true,
        ));

        $this->assertCount(1, $this->appendedEvents, 'Worker must only append the in-flight start event.');

        // Seq 1: tool_execution_start
        $this->assertSame(1, $this->appendedEvents[0]->seq);
        $this->assertSame(RunEventTypeEnum::ToolExecutionStart->value, $this->appendedEvents[0]->type);
        $this->assertSame(2, $this->appendedEvents[0]->turnNo);
        $this->assertSame('sh_tc_1', $this->appendedEvents[0]->payload['tool_call_id'] ?? null);
        $this->assertSame('bash', $this->appendedEvents[0]->payload['tool_name'] ?? null);
        // Direct shell must carry the canonical flat bash provider arguments so TUI can
        // render the bash card and the native resolver accepts the call.
        $this->assertSame(['command' => 'echo hello'], $this->appendedEvents[0]->payload['arguments'] ?? null);
        $this->assertArrayNotHasKey('timeout', $this->appendedEvents[0]->payload['arguments'] ?? []);
        $this->assertArrayNotHasKey('timeout', $this->appendedEvents[0]->payload);

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);
        $result = $commandBus->messages[0];
        $this->assertSame('shell-step', $result->stepId());
        $this->assertSame(2, $result->attempt());
        $this->assertSame(hash('sha256', 'run-standalone|sh_tc_1'), $result->idempotencyKey());
        $this->assertSame('sh_tc_1', $result->toolCallId);
        $this->assertSame(['command' => 'echo hello'], $result->result['arguments'] ?? null);
        $this->assertTrue($result->result['standalone'] ?? false);
        $this->assertFalse($result->isError);
    }

    /**
     * Thesis: Non-standalone shell commands (subsequent !cmd during an
     * agent run) must NOT write AgentEnd.  The run is terminated by a
     * separate complete_run command or by the LLM turn's own RunCompleted.
     * Writing AgentEnd here would prematurely terminate the agent run.
     * They also must not dispatch AdvanceRun.
     */
    public function testNonStandaloneDoesNotWriteAgentEnd(): void
    {
        $eventStore = $this->createEventStore();
        $toolExecutor = $this->createToolExecutor('result', isError: true);
        $commandBus = new TestMessageBus();
        $worker = new ExecuteShellToolCallWorker($toolExecutor, $eventStore, $commandBus);
        $worker(new ExecuteShellToolCall(
            runId: 'run-inline',
            turnNo: 2,
            stepId: 'inline-step',
            attempt: 1,
            toolCallId: 'sh_tc_2',
            commandText: 'echo inline',
            standalone: false,
        ));

        $this->assertCount(1, $this->appendedEvents);
        $this->assertSame(RunEventTypeEnum::ToolExecutionStart->value, $this->appendedEvents[0]->type);
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);
        $result = $commandBus->messages[0];
        $this->assertSame('run-inline', $result->runId());
        $this->assertSame('sh_tc_2', $result->toolCallId);
        $this->assertSame([['type' => 'text', 'text' => 'result']], $result->result['content'] ?? null);
        $this->assertFalse($result->result['standalone'] ?? true);
        $this->assertTrue($result->isError);
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
            public function latestSequenceFor(string $runId): ?int
            {
                $events = $this->allFor($runId);

                return [] === $events ? null : $events[array_key_last($events)]->seq;
            }

            public function firstFor(string $runId): ?RunEvent
            {
                $events = $this->allFor($runId);

                return $events[0] ?? null;
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                foreach ($this->collector as $event) {
                    if ($event->runId === $runId && $event->seq >= $startSeq && $event->seq <= $endSeq) {
                        yield $event;
                    }
                }
            }

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

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
    private function createToolExecutor(string $resultText, bool $isError = false): ToolExecutorInterface
    {
        return new class($resultText, $isError) implements ToolExecutorInterface {
            public function __construct(
                private readonly string $resultText,
                private readonly bool $isError,
            ) {
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => $this->resultText]],
                    isError: $this->isError,
                );
            }
        };
    }
}
