<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Applies durable timeout/parent-cancel interruptions for deferred batches without polling parent or child RunStore.
 */
final readonly class DeferredSubagentBatchInterruptionService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchLifecycleDeliveryService $deliveryService,
        private AgentRunnerInterface $agentRunner,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private SubagentChildRunBatchLifecyclePolicyFactory $lifecyclePolicyFactory,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function interrupt(string $batchLifecycleId, DeferredSubagentInterruptionKindEnum $kind): void
    {
        $row = $this->batchRepository->findEntityByLifecycleId($batchLifecycleId);
        if (null === $row) {
            return;
        }

        if (null !== $row->terminalCompletionEnqueuedAt) {
            return;
        }

        $projection = $this->batchRepository->findByLifecycleId($batchLifecycleId);
        if (null === $projection) {
            return;
        }

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind && null !== $projection->deadlineAt) {
            $delayMs = ($projection->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000;
            if ($delayMs > 0) {
                try {
                    $this->commandBus->dispatch(
                        new InterruptDeferredSubagentBatchMessage($batchLifecycleId, $kind),
                        [new DelayStamp($delayMs)],
                    );
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to reschedule deferred subagent batch timeout interruption.', previous: $exception);
                }

                return;
            }
        }

        // Check if all children are naturally terminal before first intent — deliver normally.
        if (null === $projection->interruptionKind) {
            $allTerminal = true;
            foreach ($projection->children as $child) {
                $cp = $child->childLifecycleProjection;
                if (null === $cp || !$cp->childStatus->isTerminal()) {
                    $allTerminal = false;
                    break;
                }
            }
            if ($allTerminal) {
                $this->deliveryService->deliver($batchLifecycleId);

                return;
            }
        }

        $effectiveKind = $projection->interruptionKind ?? $kind;
        $expectedVersion = $row->projectionVersion;

        // First-wins interruption intent persistence
        if (null === $projection->interruptionKind) {
            try {
                $this->batchRepository->persistInterruptionIntent(
                    batchLifecycleId: $batchLifecycleId,
                    kind: $kind,
                    requestedAt: $this->clock->now(),
                    expectedProjectionVersion: $expectedVersion,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_subagent_batch.interruption_intent_conflict', [
                    'batch_lifecycle_id' => $batchLifecycleId,
                    'kind' => $kind->value,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.interruption_intent_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }

            $projection = $this->batchRepository->findByLifecycleId($batchLifecycleId);
            if (null === $projection) {
                return;
            }
            $effectiveKind = $projection->interruptionKind ?? $kind;
        }

        // Wait for generic deferred registration before cancelling children
        $deferredStatus = $this->deferredToolCompletionRepository->status($batchLifecycleId);
        if (null === $deferredStatus) {
            $this->logger->info('deferred_subagent_batch.interruption_waiting_for_registration', [
                'batch_lifecycle_id' => $batchLifecycleId,
                'kind' => $effectiveKind->value,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.interruption_waiting_for_registration',
            ]);

            return;
        }

        if ('completed' === $deferredStatus) {
            $this->deliveryService->deliver($batchLifecycleId);

            return;
        }

        $policy = $this->lifecyclePolicyFactory->create();
        $isSingle = \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum::Single === $projection->executionMode;
        $cancelReason = match ($effectiveKind) {
            DeferredSubagentInterruptionKindEnum::Timeout => $isSingle
                ? $policy->singleTimeoutCancelReason
                : $policy->parallelTimeoutCancelReason,
            DeferredSubagentInterruptionKindEnum::ParentCancelled => $isSingle
                ? $policy->parentCancelSingleReason
                : $policy->parentCancelParallelReason,
        };

        // Cancel each potentially-started non-terminal child once
        foreach ($projection->children as $child) {
            if (DeferredSubagentChildLaunchStatusEnum::Failed === $child->launchStatus) {
                continue;
            }
            if (DeferredSubagentChildLaunchStatusEnum::Reserved !== $child->launchStatus
                && DeferredSubagentChildLaunchStatusEnum::Launched !== $child->launchStatus) {
                continue;
            }
            $cp = $child->childLifecycleProjection;
            if (null !== $cp && $cp->childStatus->isTerminal()) {
                continue;
            }

            $this->agentRunner->cancel($child->childRunId, $cancelReason);
        }

        $this->deliveryService->deliver($batchLifecycleId);
    }
}
