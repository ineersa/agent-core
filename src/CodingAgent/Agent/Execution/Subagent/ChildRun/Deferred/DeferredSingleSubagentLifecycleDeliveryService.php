<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;

/**
 * Durable deferred single-child delivery orchestration: progress emission and terminal completion handoff.
 */
final readonly class DeferredSingleSubagentLifecycleDeliveryService
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private DeferredSingleSubagentTerminalCompletionService $terminalCompletionService,
    ) {
    }

    public function deliver(string $lifecycleId): void
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

        $childProjection = $projection->childLifecycleProjection;
        $expectedVersion = $row->projectionVersion;

        if (null !== $projection->interruptionKind) {
            if ($projection->childEventCursor > $projection->parentProgressCursor) {
                $childForProgress = $childProjection ?? new DeferredSingleSubagentChildLifecycleProjectionDTO(
                    childStatus: \Ineersa\AgentCore\Domain\Run\RunStatus::Running,
                    childTurnNo: 0,
                    lastCommittedSeq: $projection->childEventCursor,
                );
                $this->terminalCompletionService->deliverProgressIfNeeded(
                    $projection,
                    $childForProgress,
                    $expectedVersion,
                    $projection->interruptionKind,
                );
                $row = $this->launchRepository->findEntityByLifecycleId($lifecycleId);
                if (null === $row || null !== $row->terminalCompletionEnqueuedAt) {
                    return;
                }
                $expectedVersion = $row->projectionVersion;
            }

            $this->terminalCompletionService->completeFromInterruption($projection, $expectedVersion);

            return;
        }

        if (null === $childProjection) {
            return;
        }

        if ($projection->childEventCursor > $projection->parentProgressCursor) {
            $this->terminalCompletionService->deliverProgressIfNeeded(
                $projection,
                $childProjection,
                $expectedVersion,
            );
            $row = $this->launchRepository->findEntityByLifecycleId($lifecycleId);
            if (null === $row || null !== $row->terminalCompletionEnqueuedAt) {
                return;
            }
            $expectedVersion = $row->projectionVersion;
            $projection = $this->launchRepository->findByLifecycleId($lifecycleId);
            if (null === $projection) {
                return;
            }
            $childProjection = $projection->childLifecycleProjection;
            if (null === $childProjection) {
                return;
            }
        }

        if (!$childProjection->childStatus->isTerminal()) {
            return;
        }

        $this->terminalCompletionService->completeFromChildProjection($projection, $childProjection, $expectedVersion);
    }
}
