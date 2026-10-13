<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;

/** Owner-only reconciliation. Captured canonical coordination completes before ordinary message dispatch. */
final readonly class PendingTransitionRecovery
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private ActiveRunContextInterface $registry,
        private TransitionFinalizer $finalizer,
        private CoordinationActionValidator $actionValidator = new CoordinationActionValidator(),
    ) {
    }

    public function recover(string $runId): void
    {
        $pending = $this->store->verifiedPendingTransition($runId);
        if (null === $pending) {
            return;
        }
        $work = $pending->work;
        if (($work['run_id'] ?? null) !== $runId) {
            throw new \RuntimeException('Pending transition run identity mismatch.');
        }
        $effects = [...($work['effects'] ?? []), ...($work['post_commit_effects'] ?? [])];
        $actions = $work['actions'] ?? [];
        $afterTurnActions = $work['after_turn_actions'] ?? [];
        // Validate the entire recovery plan before applying any coordination.
        foreach ([...$actions, ...$afterTurnActions] as $action) {
            $this->actionValidator->validate($action);
        }
        $finalized = false;
        try {
            $this->finalizer->complete($runId, $pending, $effects, $actions, $afterTurnActions);
            $finalized = true;
        } finally {
            // Cold replay must include the newly published suffix. A warm owner must
            // not continue using its predecessor after recovered physical append,
            // including when finalization completes before ordinary delivery.
            if ($finalized || null === $this->store->verifiedPendingTransition($runId)) {
                $this->registry->release($runId);
            }
        }
    }
}
