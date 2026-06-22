<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
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

        // Compute next sequence number from existing events.
        $existingEvents = $this->eventStore->allFor($runId);
        $nextSeq = [] !== $existingEvents
            ? max(array_map(static fn (RunEvent $e): int => $e->seq, $existingEvents)) + 1
            : 1;

        // Emit tool_execution_start event.
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $nextSeq,
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
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $nextSeq + 1,
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
    }
}
