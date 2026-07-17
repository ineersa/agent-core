<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsEventListener(event: DeferredToolCompletionRegisteredEvent::class)]
final readonly class DeferredToolCompletionRegisteredBatchListener
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function __invoke(DeferredToolCompletionRegisteredEvent $event): void
    {
        $correlation = $event->correlation;
        $batch = $this->batchRepository->findByParentRunAndToolCall($correlation->runId, $correlation->toolCallId);
        if (null === $batch) {
            return;
        }

        if ($batch->lifecycleId !== $correlation->deferredId) {
            return;
        }

        // Launch failed before any child started (e.g. fork compaction hard failure):
        // natural Deliver path has no terminal child projection — complete the deferred tool now.
        if (DeferredSubagentBatchLaunchStatusEnum::Failed === $batch->launchStatus
            && null === $batch->terminalCompletionEnqueuedAt
            && null === $batch->interruptionKind) {
            $presentation = 'Deferred child launch failed before runtime start.';
            $this->completionDispatcher->dispatchCompletion(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                expectedProjectionVersion: $batch->projectionVersion,
                presentation: $presentation,
                isError: true,
                errorEnvelope: [
                    'error' => [
                        'type' => ToolCallException::class,
                        'message' => $presentation,
                        'retryable' => false,
                        'hint' => null,
                    ],
                    'details' => [
                        'error_type' => ToolCallException::class,
                        'retryable' => false,
                        'hint' => null,
                    ],
                ],
            );

            return;
        }

        try {
            $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred subagent batch lifecycle delivery.', previous: $exception);
        }

        // Re-dispatch interruption intent if already persisted
        if (null !== $batch->interruptionKind) {
            try {
                $this->commandBus->dispatch(new InterruptDeferredSubagentBatchMessage(
                    $batch->lifecycleId,
                    $batch->interruptionKind,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred subagent batch interruption after registration.', previous: $exception);
            }

            return;
        }

        // Schedule timeout interruption
        if (null === $batch->deadlineAt) {
            return;
        }

        $delayMs = max(0, ($batch->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

        try {
            $this->commandBus->dispatch(
                new InterruptDeferredSubagentBatchMessage(
                    $batch->lifecycleId,
                    DeferredSubagentInterruptionKindEnum::Timeout,
                ),
                $stamps,
            );
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to schedule deferred subagent batch timeout interruption.', previous: $exception);
        }
    }
}
