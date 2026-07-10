<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Messenger;

use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayFailureReason;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Last-resort safety net for async Messenger worker failures.
 *
 * When a message on the run_control transport permanently fails (all
 * Messenger retries exhausted), this subscriber writes a failed RunState
 * and an agent_end event to the EventStore so the controller's event
 * drain picks it up and the TUI shows a visible error instead of hanging.
 *
 * Without this subscriber, a failed StartRun/AdvanceRun/ApplyCommand
 * eventually exhausts Messenger retries and is discarded with no
 * trace written to the EventStore — the TUI sees run.started (emitted
 * synchronously by StartRunHandler) and then nothing, hanging forever.
 *
 * This subscriber only acts when willRetry() returns false (final
 * rejection), preventing partial/intermediate retries from writing
 * spurious terminal states.
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

            $replayCorruption = $this->findDuplicateSequenceReplayFailure($exception);
            if (null !== $replayCorruption) {
                $errorMessage = \sprintf(
                    'Permanent worker failure: %s: %s',
                    $replayCorruption::class,
                    $replayCorruption->getMessage(),
                );
            } else {
                $errorMessage = \sprintf(
                    'Permanent worker failure: %s: %s',
                    $exception::class,
                    $exception->getMessage(),
                );
            }

            if (!$this->eventStore instanceof SequencedEventStoreInterface) {
                $this->logger->error('agent_loop.worker_failed_missing_sequenced_store', [
                    'run_id' => $runId,
                    'component' => 'messenger.worker',
                    'event_type' => 'worker_failed.missing_sequenced_store',
                ]);

                return;
            }

            $agentEndEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $current->turnNo,
                type: 'agent_end',
                payload: [
                    'reason' => 'failed',
                    'error' => $exception->getMessage(),
                    'message_type' => $message::class,
                ],
            );

            $persisted = $this->eventStore->appendWithNextSeq($agentEndEvent);

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
                $this->logger->warning('agent_loop.worker_failed_cas_conflict', [
                    'run_id' => $runId,
                    'expected_version' => $current->version,
                    'persisted_seq' => $persisted->seq,
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

    private function findDuplicateSequenceReplayFailure(\Throwable $exception): ?RunStateReplayException
    {
        while (true) {
            if ($exception instanceof RunStateReplayException
                && RunStateReplayFailureReason::DuplicateSequences === $exception->reason()) {
                return $exception;
            }

            if ($exception instanceof RunStateReplayException) {
                return null;
            }

            $previous = $exception->getPrevious();
            if (null === $previous) {
                return null;
            }

            $exception = $previous;
        }
    }
}
