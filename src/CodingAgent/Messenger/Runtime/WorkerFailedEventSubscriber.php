<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Messenger\Runtime;

use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Last-resort safety net for async Messenger worker failures on run_control.
 *
 * Normal run mutations (StartRun, ApplyCommand, LlmStepResult, ToolCallResult,
 * CompactionStepResult) are serialized through RunMessageProcessor and
 * RunCommit in the single run_control consumer process. This subscriber is an
 * intentional exception to that path: it runs only after the processor/handler
 * for a run_control message has permanently failed (willRetry() is false) and
 * must append a sequenced agent_end then CAS a terminal Failed RunState so the
 * controller/TUI does not hang with no durable terminal event.
 *
 * Receiver filtering (HANDLED_RECEIVERS = run_control) keeps the write inside
 * the same authorized run_control consumer process; execution-bus failures on
 * llm/tool/agent are out of scope here because workers enqueue results back to
 * run_control instead of mutating canonical state directly.
 *
 * Limitation: this bypass does not invoke RunCommit post-commit hooks (for
 * example tool-batch snapshot cleanup). With sequenced append-before-CAS, a CAS
 * conflict after agent_end is persisted can leave state.json stale while the
 * event log contains the terminal agent_end; we never overwrite an already
 * terminal RunState when CAS fails.
 *
 * This subscriber only acts when willRetry() returns false (final rejection),
 * preventing partial/intermediate retries from writing spurious terminal states.
 */
final readonly class WorkerFailedEventSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const array HANDLED_RECEIVERS = ['run_control'];

    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // Only act after ALL retries are exhausted — the message will not
        // be re-queued. Writing a terminal state mid-retry would race with
        // the next retry attempt.
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();

        // Only handle messages that belong to a run.
        if (!$message instanceof AbstractAgentBusMessage) {
            return;
        }

        // Only handle run_control transport failures. Execution bus failures
        // (LLM/tool workers) are handled by their result handlers.
        if (!\in_array($event->getReceiverName(), self::HANDLED_RECEIVERS, true)) {
            return;
        }

        $runId = $message->runId();
        $exception = $event->getThrowable();

        $this->logger->warning('agent_loop.worker_failed_permanent', [
            'run_id' => $runId,
            'message_type' => $message::class,
            'exception' => $exception,
        ]);

        try {
            // Read current state so we can CAS with the correct expected version.
            $current = $this->runStore->get($runId);

            if (null === $current) {
                // No state was ever committed — create a minimal queued
                // placeholder so we have a base version for the CAS.
                $current = RunState::queued($runId);
            }

            // If the run is already in a terminal state, don't overwrite it.
            if (RunStatus::Failed === $current->status
                || RunStatus::Completed === $current->status
                || RunStatus::Cancelled === $current->status
            ) {
                $this->logger->info('agent_loop.worker_failed_skipped_terminal', [
                    'run_id' => $runId,
                    'current_status' => $current->status->value,
                    'component' => 'messenger.worker',
                    'event_type' => 'worker_failed.skipped_terminal',
                ]);

                return;
            }

            if ($exception instanceof RunStateDuplicateSequenceReplayException || ($exception instanceof RunStateReplayException && $exception->isDuplicateSequences())) {
                $this->logger->warning('agent_loop.worker_failed_skipped_replay_corruption', [
                    'run_id' => $runId,
                    'component' => 'messenger.worker',
                    'event_type' => 'worker_failed.skipped_replay_corruption',
                ]);

                return;
            }

            $errorMessage = \sprintf(
                'Permanent worker failure: %s: %s',
                $exception::class,
                $exception->getMessage(),
            );
            $agentEndEvent = RunEvent::forAppend(
                runId: $runId,
                turnNo: $current->turnNo,
                type: 'agent_end',
                payload: [
                    'reason' => 'failed',
                    'error' => $exception->getMessage(),
                    'message_type' => $message::class,
                ],
            );

            $persisted = $this->eventStore->append($agentEndEvent);

            $failedState = new RunState(
                runId: $runId,
                status: RunStatus::Failed,
                version: $current->version + 1,
                turnNo: $current->turnNo,
                lastSeq: $persisted->seq,
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: $errorMessage,
                messages: $current->messages,
                activeStepId: $current->activeStepId,
                retryableFailure: false,
            );

            $committed = $this->runStore->compareAndSwap($failedState, $current->version);

            if (!$committed) {
                $after = $this->runStore->get($runId);
                $this->logger->warning('agent_loop.worker_failed_cas_conflict', [
                    'run_id' => $runId,
                    'session_id' => $runId,
                    'component' => 'messenger.worker',
                    'event_type' => 'worker_failed.cas_conflict',
                    'expected_version' => $current->version,
                    'persisted_seq' => $persisted->seq,
                    'store_status_after' => $after?->status->value,
                ]);

                return;
            }

            $this->logger->info('agent_loop.worker_failed_written', [
                'run_id' => $runId,
                'message_type' => $message::class,
                'seq' => $persisted->seq,
            ]);
        } catch (\Throwable $e) {
            // Never let this subscriber throw — we're inside Messenger's
            // failure-handling middleware, and throwing would interfere
            // with the retry/failure transport logic.
            $this->logger->error('agent_loop.worker_failed_subscriber_error', [
                'run_id' => $runId,
                'message_type' => $message::class,
                'exception' => $e,
            ]);
        }
    }
}
