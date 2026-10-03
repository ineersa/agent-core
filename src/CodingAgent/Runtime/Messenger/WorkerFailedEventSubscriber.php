<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Last-resort safety net for async Messenger worker failures on run_control.
 *
 * Normal run mutations (StartRun, ApplyCommand, LlmStepResult, ToolCallResult,
 * CompactionStepResult) are serialized through RunMessageProcessor and
 * RunCommit in the single run_control consumer process. This subscriber's
 * terminal failure path uses the same owner lock and RunCommit after the
 * processor/handler permanently fails (willRetry() is false). This publishes
 * the terminal event/state and invokes normal collector release and hooks.
 *
 * Receiver filtering (HANDLED_RECEIVERS = run_control) keeps the write inside
 * the same authorized run_control consumer process; execution-bus failures on
 * llm/tool/agent are out of scope here because workers enqueue results back to
 * run_control instead of mutating canonical state directly.
 *
 * This subscriber only acts when willRetry() returns false (final rejection),
 * preventing partial/intermediate retries from writing spurious terminal states.
 */
final readonly class WorkerFailedEventSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const array HANDLED_RECEIVERS = ['run_control'];

    public function __construct(
        private ActiveRunContextInterface $activeRunContext,
        private RunCommit $runCommit,
        private RunLockManager $runLockManager,
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
            if ($exception instanceof RunStateDuplicateSequenceReplayException) {
                $this->logger->warning('agent_loop.worker_failed_skipped_replay_corruption', [
                    'run_id' => $runId,
                    'component' => 'messenger.worker',
                    'event_type' => 'worker_failed.skipped_replay_corruption',
                ]);

                return;
            }

            $this->runLockManager->synchronized($runId, function () use ($runId, $exception, $message): void {
                $current = $this->activeRunContext->requireLoaded($runId);

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

                $failedState = $current->with([
                    'status' => RunStatus::Failed,
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'pendingToolCalls' => [],
                    'errorMessage' => $errorMessage,
                ]);

                $this->runCommit->commit($current, $failedState, [$agentEndEvent]);

                $this->logger->info('agent_loop.worker_failed_written', [
                    'run_id' => $runId,
                    'message_type' => $message::class,
                ]);
            });
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
