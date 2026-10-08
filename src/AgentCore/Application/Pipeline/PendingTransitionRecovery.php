<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\RunControlTransitionMessageInterface;

/** Owner-only reconciliation. Unsupported execution stays recovery-required. */
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
        foreach ($effects as $effect) {
            $this->requireGated($effect);
        }
        foreach ([...$actions, ...$afterTurnActions] as $action) {
            $this->actionValidator->validate($action);
        }
        $executionDisposition = $work['execution_disposition'] ?? null;
        if (null !== $executionDisposition && !$executionDisposition instanceof ExecutionResultDispositionDTO) {
            throw new \RuntimeException('Invalid pending execution disposition.');
        }

        $finalized = false;
        try {
            $this->finalizer->complete($runId, $pending, $effects, $actions, $afterTurnActions, $executionDisposition);
            $finalized = true;
        } finally {
            // Cold replay must include the newly published suffix. A warm owner must
            // not continue using its predecessor after recovered physical append,
            // including when gated delivery throws after journal finalization.
            if ($finalized || null === $this->store->verifiedPendingTransition($runId)) {
                $this->registry->release($runId);
            }
        }
    }

    private function requireGated(object $effect): void
    {
        if (!$effect instanceof ExecuteToolCall && !$effect instanceof RunControlTransitionMessageInterface && !ExecutionOperationMapper::supports($effect)) {
            throw new \RuntimeException('Owner transition requires coordination recovery for ungated execution.');
        }
    }
}
