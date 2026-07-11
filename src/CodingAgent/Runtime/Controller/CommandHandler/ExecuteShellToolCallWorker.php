<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles ExecuteShellToolCall messages on the agent.execution.bus.
 *
 * Executes bash tool calls in the tool consumer process (separate from the
 * controller), avoiding the controller event-loop freeze that occurs when
 * SafeGuard extension hooks require approval and enter a blocking poll
 * in the controller process (issue #183).
 *
 * Writes canonical tool_execution_start / tool_execution_end events to the
 * EventStore so the TUI poller surfaces shell output in the transcript.
 *
 * For standalone shell commands (first-input !cmd), also writes a terminal
 * AgentEnd event after tool_exec events, ensuring the EventStore ordering
 * guarantee (tool_exec_start → tool_exec_end → agent_end) is maintained by
 * a single writer — no cross-process race with the controller.
 */
#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteShellToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private EventStoreInterface $eventStore,
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

        if ('' === $commandText) {
            return;
        }

        if (!$this->eventStore instanceof SequencedEventStoreInterface) {
            throw new \LogicException('ExecuteShellToolCallWorker requires SequencedEventStoreInterface.');
        }

        $startPersisted = $this->eventStore->appendWithNextSeq(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::ToolExecutionStart->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
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
            arguments: ['command' => $commandText],
            orderIndex: 0,
            runId: $runId,
        ));

        // Extract the result text from the ToolResult's content blocks.
        $resultText = '';
        foreach ($result->content as $contentBlock) {
            if (\is_array($contentBlock) && 'text' === ($contentBlock['type'] ?? '')) {
                $resultText .= (string) ($contentBlock['text'] ?? '');
            } elseif (\is_string($contentBlock)) {
                $resultText .= $contentBlock;
            }
        }

        // Emit tool_execution_end event with result text.
        $this->eventStore->appendWithNextSeq(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'is_error' => $result->isError,
                'result' => $resultText,
            ],
        ));

        $this->logger?->info('shell.tool_execution_completed', [
            'run_id' => $runId,
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_execution_completed',
            'tool_call_id' => $toolCallId,
            'is_error' => $result->isError,
        ]);

        // Standalone shell commands (first-input !cmd) need a terminal
        // AgentEnd event so the TUI poller transitions from Running to
        // Completed and clears the working indicator.  Writing it here,
        // in the same process as tool_exec events, guarantees the
        // EventStore ordering: tool_exec_start → tool_exec_end →
        // agent_end.  This avoids the ordering race that occurs when the
        // controller calls completeRun() synchronously before the async
        // worker has written tool_exec events (issue #183).
        if ($message->standalone) {
            $this->eventStore->appendWithNextSeq(new RunEvent(
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
        }
    }
}
