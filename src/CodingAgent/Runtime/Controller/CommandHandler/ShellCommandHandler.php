<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles shell_command commands via Symfony EventDispatcher.
 *
 * Receives shell_command RuntimeCommands from the TUI (via JSONL) and
 * dispatches them as ExecuteShellToolCall messages on the agent.execution.bus
 * so that bash execution happens in a tool consumer process (issue #183).
 *
 * This avoids the controller event-loop freeze that occurred when
 * InProcessAgentSessionClient::executeShellCommand() called toolExecutor->execute()
 * synchronously and SafeGuard hooks entered a blocking approval poll.
 *
 * The worker is the sole ordering authority for shell-command lifecycle
 * events: it writes tool_execution_start, tool_execution_end, and (when
 * standalone) AgentEnd in a single process, guaranteeing tool_exec →
 * agent_end ordering (LifecycleOrderValidator-conformant).
 *
 * complete_run commands are NOT handled here — the controller must never
 * synchronously write AgentEnd for work dispatched to an async consumer
 * because that produces [AgentEnd, tool_exec_start, tool_exec_end] ordering
 * (issue #183).  Shell output appears in the transcript via the controller's
 * periodic EventStore drain — no LLM turn is triggered.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ShellCommandHandler
{
    public function __construct(
        private readonly MessageBusInterface $executionBus,
        private readonly EventStoreInterface $eventStore,
        private readonly RunStoreInterface $runStore,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        $runId = $command->runId ?? '';

        if ('shell_command' !== $command->type) {
            return;
        }

        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'shell_command requires runId'],
            ));

            return;
        }

        $commandText = (string) ($command->payload['text'] ?? '');
        $standalone = (bool) ($command->payload['standalone'] ?? false);
        $originalText = (string) ($command->payload['original_text'] ?? '');
        if ('' === $originalText && '' !== $commandText) {
            // Process clients that only send the bare command still need a
            // bang-prefixed display line for transcript/history projection.
            $originalText = '!'.$commandText;
        }

        // Shell-only runs do not emit RunStarted (they bypass start()),
        // so the RuntimeEventEmitter drain loop never registers a cursor
        // for this run and tool_exec events sit in the EventStore forever
        // without being forwarded to the TUI.
        //
        // Emit a synthetic RunStarted event with seq 0 (transient) so the
        // emitter registers a runEventCursors entry.  The drain loop will
        // then pick up the canonical tool_exec events (seq > 0) on the
        // next cycle and forward them to the TUI.  Seq 0 is skipped by
        // the drain loop's cursor tracking so it does not interfere.
        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runId,
            seq: 0,
            payload: ['kind' => 'shell'],
        ));

        // Branch ownership for direct shell: stamp events with the current
        // RunState leaf turn (0 when no conversational turn exists yet).
        // Correlated tool_exec events reuse the same turn + tool_call_id so
        // TurnTreeReplayFilter can drop abandoned bang interactions without
        // filtering model-generated bash or legitimate run-level events.
        $runState = $this->runStore->get($runId);
        $turnNo = null !== $runState ? $runState->turnNo : 0;
        $toolCallId = uniqid('sh_', true);

        if ('' !== $commandText || '' !== $originalText) {
            $this->eventStore->append(new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $turnNo,
                type: RunEventTypeEnum::AgentCommandApplied->value,
                payload: [
                    'kind' => 'shell_command',
                    'text' => $originalText,
                    'command' => $commandText,
                    'tool_call_id' => $toolCallId,
                    'standalone' => $standalone,
                    'idempotency_key' => hash('sha256', $runId.'|'.$toolCallId.'|shell_command'),
                ],
            ));
        }

        // Dispatch bash execution to the tool consumer via the async
        // Messenger tool bus (issue #183).  The ExecuteShellToolCallWorker
        // in the tool consumer executes bash through the shared tool
        // executor and persists tool_execution_start / tool_execution_end
        // events to the canonical event store.  SafeGuard approval polls
        // run in the consumer, not the controller, so the event loop
        // stays alive.
        //
        // The standalone flag is passed through so the worker owns the terminal
        // AgentEnd write for first-input shell commands.  By keeping tool_exec and AgentEnd writes
        // within a single process (the worker), the EventStore ordering is
        // guaranteed — AgentEnd always follows tool_exec events, satisfying
        // the LifecycleOrderValidator constraint that agent_end must be the
        // final lifecycle event.  Synchronously calling completeRun() from
        // the controller would race with the async worker and produce
        // [AgentEnd, tool_exec_start, tool_exec_end] ordering (issue #183).
        try {
            $this->executionBus->dispatch(new ExecuteShellToolCall(
                runId: $runId,
                toolCallId: $toolCallId,
                commandText: $commandText,
                standalone: $standalone,
                turnNo: $turnNo,
            ));
        } catch (ExceptionInterface $exception) {
            // Messenger transport unavailable — emit a diagnostic error
            // so the user sees a clear message instead of a silent hang.
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: [
                    'error' => 'Failed to dispatch shell command: '.$exception->getMessage(),
                ],
            ));

            return;
        }
    }
}
