<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;

/** Applies captured batch, mailbox, and lifecycle projection changes after verified append. */
final readonly class LocalMetadataCoordinator
{
    public function __construct(
        private ApplicationDbTransactionInterface $transactions,
        private ToolBatchStoreInterface $batches,
        private CommandStoreInterface $commands,
        private DeferredToolCompletionRepositoryInterface $deferredCompletions,
    ) {
    }

    public function apply(TransitionPlan $plan): void
    {
        $transition = $plan->verified ?? throw new \RuntimeException('Local metadata requires verified transition evidence.');
        $batchActions = [];
        $lifecycleActions = [];
        foreach ($plan->localActions as $action) {
            if ($action instanceof FinalizeToolBatchDTO || $action instanceof RegisterToolBatchDTO) {
                $batchActions[] = $action;
            } elseif ($action instanceof RecordToolInvocationLifecycleDTO) {
                $lifecycleActions[] = $action;
            } elseif (!$action instanceof EnqueueCommandDTO && !$action instanceof MarkCommandAppliedDTO && !$action instanceof RejectCommandDTO) {
                throw new \RuntimeException('Unsupported local metadata action '.$action::class.'.');
            }
        }
        // Reads and payload serialization finish before the short DB transaction.
        $applyBatches = $this->batches->prepareChanges($batchActions, $transition);
        $enqueues = [];
        foreach ($plan->localActions as $index => $action) {
            if ($action instanceof EnqueueCommandDTO) {
                $enqueues[$index] = $this->commands->prepareEnqueue($action->command);
            }
        }
        $this->transactions->transactional(function () use ($plan, $applyBatches, $enqueues, $lifecycleActions): void {
            $applyBatches();
            foreach ($plan->localActions as $index => $action) {
                if ($action instanceof EnqueueCommandDTO) {
                    $enqueues[$index]();
                } elseif ($action instanceof MarkCommandAppliedDTO) {
                    $this->commands->markApplied($action->runId, $action->idempotencyKey);
                } elseif ($action instanceof RejectCommandDTO) {
                    $this->commands->markRejected($action->runId, $action->idempotencyKey, $action->reason);
                }
            }
            foreach ($lifecycleActions as $lifecycleAction) {
                $this->deferredCompletions->applyLifecycleProjection($lifecycleAction);
            }
        });
    }
}
