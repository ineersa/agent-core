<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeferredSubagentBatchLifecycleDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
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
        private ChildRunBatchLifecyclePolicyDTO $lifecyclePolicy,
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

        if (null !== $projection->completionSource) {
            $this->deliveryService->deliver($batchLifecycleId);

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

        // Deliver a naturally terminal child before recording interruption intent.
        if (null === $projection->interruptionKind
            && true === $projection->child?->childLifecycleProjection?->childStatus->isTerminal()) {
            $this->deliveryService->deliver($batchLifecycleId);

            return;
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

        // Cancel a reserved or launched child immediately; completion still waits
        // for generic deferred registration so the parent tool can finish.
        $deferredStatus = $this->deferredToolCompletionRepository->status($batchLifecycleId);
        if (null === $deferredStatus) {
            // The child may already be launched before parent tool registration.
            // Cancel them immediately on parent-cancel/timeout intent; completion
            // still waits for deferred registration so the parent tool can finish.
            $this->cancelStartedChild($projection, $effectiveKind);
            $this->logger->info('deferred_subagent_batch.interruption_waiting_for_registration', [
                'batch_lifecycle_id' => $batchLifecycleId,
                'kind' => $effectiveKind->value,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.interruption_waiting_for_registration',
            ]);

            return;
        }

        if (DeferredToolCompletionStatusEnum::Completed === $deferredStatus && null === $projection->backgroundedAt) {
            $this->deliveryService->deliver($batchLifecycleId);

            return;
        }

        $this->cancelStartedChild($projection, $effectiveKind);

        $this->deliveryService->deliver($batchLifecycleId);
    }

    private function cancelStartedChild(
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentInterruptionKindEnum $effectiveKind,
    ): void {
        $policy = $this->lifecyclePolicy;
        $cancelReason = match ($effectiveKind) {
            DeferredSubagentInterruptionKindEnum::Timeout => $policy->singleTimeoutCancelReason,
            DeferredSubagentInterruptionKindEnum::ParentCancelled => $policy->parentCancelSingleReason,
        };

        $child = $projection->child;
        if (null === $child || !\in_array($child->launchStatus, [DeferredSubagentChildLaunchStatusEnum::Reserved, DeferredSubagentChildLaunchStatusEnum::Launched], true)
            || true === $child->childLifecycleProjection?->childStatus->isTerminal()) {
            return;
        }

        $this->agentRunner->cancel($child->childRunId, $cancelReason);
    }
}
