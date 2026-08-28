<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles ExecuteShellToolCall messages on the agent.execution.bus.
 *
 * Executes bash tool calls in the tool consumer process (separate from the
 * controller) so the controller remains free to project runtime events and
 * accept human answers while shell execution is in flight.
 *
 * Writes canonical tool_execution_start / tool_execution_end events to the
 * EventStore so the TUI poller surfaces shell output in the transcript.
 *
 * For standalone shell commands, also writes a terminal AgentEnd event after
 * tool_exec events, ensuring the EventStore ordering guarantee
 * (tool_exec_start → tool_exec_end → agent_end) is maintained by a single
 * writer — no cross-process race with the controller.
 *
 * After that AgentEnd, dispatches AdvanceRun on agent.command.bus so a
 * follow_up (or other mailbox command) queued while the shell still looked
 * Running is drained. Without this wake, ApplyCommandHandler can queue the
 * follow_up under Running without scheduling AdvanceRun, and AgentEnd alone
 * does not re-enter the command pipeline (issue #183 race window).
 */
#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteShellToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private EventStoreInterface $eventStore,
        private MessageBusInterface $commandBus,
        private ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ExecuteShellToolCall $message): void
    {
        $runId = $message->runId();

        RunLogContext::enter([
            'run_id' => $runId,
            'session_id' => $runId,
            'component' => 'tool',
            'queue' => 'agent.execution.bus',
            'worker' => 'shell_tool',
            'tool_name' => 'bash',
        ]);

        try {
            $this->execute($message);
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteShellToolCall $message): void
    {
        $runId = $message->runId();
        $toolCallId = $message->toolCallId;
        $commandText = $message->commandText;
        $turnNo = $message->turnNo();

        if ('' === $commandText) {
            return;
        }

        // Include arguments so transcript projection can build a ToolCall block
        // for direct !shell executions that never stream tool_call.* events.
        // The canonical {command: ...} shape matches LLM-streamed calls.
        $arguments = ['command' => $commandText];
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: $turnNo,
            type: RunEventTypeEnum::ToolExecutionStart->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
                'attempt' => $message->attempt(),
                'arguments' => $arguments,
            ],
        ));

        $this->logger?->info('shell.tool_execution_started', [
            'run_id' => $runId,
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_execution_started',
            'tool_call_id' => $toolCallId,
            'command' => $commandText,
        ]);

        // Execute bash through the shared tool executor.
        // SafeGuard / extension hooks run in this tool consumer process,
        // so any blocking approval poll does not freeze the controller.
        $result = $this->toolExecutor->execute(new ToolCall(
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: $arguments,
            orderIndex: 0,
            runId: $runId,
        ));

        // Persist the direct executor's raw content and details exactly once in
        // the typed canonical payload; direct shell remains non-model-visible.
        $toolCallResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $message->stepId(),
            attempt: $message->attempt(),
            idempotencyKey: $message->idempotencyKey(),
            toolCallId: $toolCallId,
            orderIndex: 0,
            result: [
                'tool_name' => $result->toolName,
                'content' => $result->content,
                'details' => $result->details,
                'arguments' => $arguments,
            ],
            isError: $result->isError,
        );

        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: $turnNo,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: $this->toolExecutionEndPayloadCodec->toEventPayload($toolCallResult),
        ));

        $this->logger?->info('shell.tool_execution_completed', [
            'run_id' => $runId,
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_execution_completed',
            'tool_call_id' => $toolCallId,
            'is_error' => $result->isError,
        ]);

        // This worker is a canonical side-event writer, not an operational
        // projection writer. Invalidate the run_control cache after durable
        // completion so its next transition replays this event stream.
        try {
            $this->commandBus->dispatch(new InvalidateRunContext($runId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch run-context invalidation after shell completion.', previous: $exception);
        }

        // Standalone shell commands need a terminal
        // AgentEnd event so the TUI poller transitions from Running to
        // Completed and clears the working indicator.  Writing it here,
        // in the same process as tool_exec events, guarantees the
        // EventStore ordering: tool_exec_start → tool_exec_end →
        // agent_end.  This avoids the ordering race that occurs when the
        // controller writes AgentEnd synchronously before the async
        // worker has written tool_exec events (issue #183).
        if ($message->standalone) {
            $this->eventStore->append(new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: 0,
                type: RunEventTypeEnum::AgentEnd->value,
                payload: ['reason' => 'completed'],
            ));

            $this->logger?->info('shell.run_completed', [
                'run_id' => $runId,
                'component' => 'tool.shell',
                'event_type' => 'shell.run_completed',
                'tool_call_id' => $toolCallId,
            ]);

            // Wake run_control after AgentEnd only. Safe/idempotent when the
            // mailbox is empty; required when follow_up was queued while the
            // shell still held Running (issue #183 tool-end→AgentEnd window).
            $stepId = \sprintf('shell-standalone-advance-%s', $toolCallId);
            $idempotencyKey = hash('sha256', \sprintf('%s|%s', $runId, $stepId));

            try {
                $this->commandBus->dispatch(new AdvanceRun(
                    runId: $runId,
                    turnNo: $message->turnNo(),
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: $idempotencyKey,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch AdvanceRun after standalone shell AgentEnd.', previous: $exception);
            }

            $this->logger?->info('shell.advance_dispatched', [
                'run_id' => $runId,
                'component' => 'tool.shell',
                'event_type' => 'shell.advance_dispatched',
                'tool_call_id' => $toolCallId,
                'step_id' => $stepId,
            ]);
        }
    }
}
