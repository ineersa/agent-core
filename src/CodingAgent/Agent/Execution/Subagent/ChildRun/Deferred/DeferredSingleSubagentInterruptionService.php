<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Applies durable timeout/parent-cancel interruptions without polling parent or child RunStore.
 */
final readonly class DeferredSingleSubagentInterruptionService
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private DeferredSingleSubagentLifecycleDeliveryService $deliveryService,
        private AgentRunnerInterface $agentRunner,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private SubagentChildRunBatchLifecyclePolicyFactory $lifecyclePolicyFactory,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function interrupt(string $lifecycleId, DeferredSubagentInterruptionKindEnum $kind): void
    {
        $row = $this->launchRepository->findEntityByLifecycleId($lifecycleId);
        if (null === $row) {
            return;
        }

        if (null !== $row->terminalCompletionEnqueuedAt) {
            return;
        }

        $projection = $this->launchRepository->findByLifecycleId($lifecycleId);
        if (null === $projection) {
            return;
        }

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind && null !== $projection->deadlineAt) {
            $delayMs = ($projection->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000;
            if ($delayMs > 0) {
                try {
                    $this->commandBus->dispatch(
                        new InterruptDeferredSingleSubagentMessage($lifecycleId, $kind),
                        [new DelayStamp($delayMs)],
                    );
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to reschedule deferred single subagent timeout interruption.', previous: $exception);
                }

                return;
            }
        }

        $childProjection = $projection->childLifecycleProjection;
        if (null !== $childProjection
            && $childProjection->childStatus->isTerminal()
            && null === $projection->interruptionKind) {
            $this->deliveryService->deliver($lifecycleId);

            return;
        }

        $effectiveKind = $projection->interruptionKind ?? $kind;
        $expectedVersion = $row->projectionVersion;
        if (null === $projection->interruptionKind) {
            try {
                $this->launchRepository->persistInterruptionIntent(
                    lifecycleId: $lifecycleId,
                    kind: $kind,
                    requestedAt: $this->clock->now(),
                    expectedProjectionVersion: $expectedVersion,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_single_subagent.interruption_intent_conflict', [
                    'lifecycle_id' => $lifecycleId,
                    'kind' => $kind->value,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_single_subagent.interruption_intent_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }

            $projection = $this->launchRepository->findByLifecycleId($lifecycleId);
            if (null === $projection) {
                return;
            }
            $effectiveKind = $projection->interruptionKind ?? $kind;
        }

        $deferredStatus = $this->deferredToolCompletionRepository->status($lifecycleId);
        if (null === $deferredStatus) {
            $this->logger->info('deferred_single_subagent.interruption_waiting_for_registration', [
                'lifecycle_id' => $lifecycleId,
                'kind' => $effectiveKind->value,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.interruption_waiting_for_registration',
            ]);

            return;
        }

        if ('completed' === $deferredStatus) {
            $this->deliveryService->deliver($lifecycleId);

            return;
        }

        $policy = $this->lifecyclePolicyFactory->create();
        $cancelReason = DeferredSubagentInterruptionKindEnum::Timeout === $effectiveKind
            ? $policy->singleTimeoutCancelReason
            : $policy->parentCancelSingleReason;

        $this->agentRunner->cancel($projection->childRunId, $cancelReason);
        $this->deliveryService->deliver($lifecycleId);
    }
}
