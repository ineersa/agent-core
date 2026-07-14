<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gap/restart recovery for deferred single-child lifecycle: tails durable child events.jsonl once per wakeup.
 */
final readonly class DeferredSingleSubagentRecoveryService
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private AgentChildRunEventStoreFactory $childEventStoreFactory,
        private DeferredChildRunEventProjector $projector,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function recover(string $lifecycleId): void
    {
        $row = $this->launchRepository->findEntityByLifecycleId($lifecycleId);
        if (null === $row) {
            return;
        }

        if (null !== $row->terminalCompletionEnqueuedAt) {
            return;
        }

        if (null !== $row->interruptionKind) {
            $this->dispatchInterrupt($lifecycleId, $row->interruptionKind);

            return;
        }

        $cursor = $row->childEventCursor;
        $childStore = $this->childEventStoreFactory->create($row->parentRunId, $row->childRunId, $row->artifactId);
        $tailEvents = $childStore->readAfterSeq($cursor);

        if ([] !== $tailEvents) {
            $summaries = array_map(
                static fn (RunEvent $event): AfterTurnCommitEventSummary => new AfterTurnCommitEventSummary(
                    seq: $event->seq,
                    type: $event->type,
                    payload: $event->payload,
                ),
                $tailEvents,
            );

            $rawProjection = $row->childLifecycleProjection;
            $current = \is_array($rawProjection) && [] !== $rawProjection
                ? DeferredChildRunLifecycleProjectionDTO::fromArray($rawProjection)
                : new DeferredChildRunLifecycleProjectionDTO(
                    childStatus: RunStatus::Running,
                    childTurnNo: 0,
                    lastCommittedSeq: $cursor,
                );

            $maxTurnNo = $current->childTurnNo;
            foreach ($tailEvents as $event) {
                $maxTurnNo = max($maxTurnNo, $event->turnNo);
            }

            $updated = $this->projector->apply(
                current: $current,
                summaries: $summaries,
                definitionModel: $row->definitionModel,
                committedStatus: null,
                committedTurnNo: $maxTurnNo,
            );

            try {
                $this->launchRepository->applyChildLifecycleProjection(
                    lifecycleId: $lifecycleId,
                    projection: $updated,
                    childEventCursor: $updated->lastCommittedSeq,
                    expectedProjectionVersion: $row->projectionVersion,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_single_subagent.recovery_projection_version_conflict', [
                    'lifecycle_id' => $lifecycleId,
                    'child_run_id' => $row->childRunId,
                    'cursor' => $cursor,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_single_subagent.recovery_projection_version_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }
        }

        $this->dispatchDeliver($lifecycleId);
    }

    private function dispatchDeliver(string $lifecycleId): void
    {
        try {
            $this->commandBus->dispatch(new DeliverDeferredSingleSubagentLifecycleMessage($lifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred single subagent lifecycle delivery after recovery.', previous: $exception);
        }
    }

    private function dispatchInterrupt(string $lifecycleId, DeferredSingleSubagentInterruptionKindEnum $kind): void
    {
        try {
            $this->commandBus->dispatch(new InterruptDeferredSingleSubagentMessage($lifecycleId, $kind));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred single subagent interruption after recovery.', previous: $exception);
        }
    }
}
