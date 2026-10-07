<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;

/**
 * Applies captured local mailbox/scheduling/source/disposition/outbox metadata in one
 * short application-DB transaction after verified canonical append.
 *
 * Immutable request sealing happens before this transaction. Transport delivery
 * happens after outermost owner-lock release. Only owner-admitted tool calls are armed.
 */
final readonly class LocalMetadataCoordinator
{
    public function __construct(
        private ApplicationDbTransactionInterface $transactions,
        private ToolBatchStoreInterface $batches,
        private CommandStoreInterface $commands,
        private ExecutionOperationStoreInterface $executionOperations,
        private SourceAcceptance $sourceAcceptance,
        private DurablePendingPublication $publication,
    ) {
    }

    public function apply(TransitionPlan $plan): void
    {
        $transition = $plan->verified ?? throw new \RuntimeException('Local metadata requires verified transition evidence.');
        $actions = $plan->localActions;
        $effects = $plan->gatedEffects;
        $executionDisposition = $plan->executionDisposition;

        foreach ($this->preparationCandidates($actions, $effects) as $effect) {
            $this->executionOperations->prepare($effect, $transition);
        }
        if (null !== $executionDisposition) {
            // Sealed-body verification stays outside the short metadata transaction.
            $this->executionOperations->validateDisposition($executionDisposition, $transition);
        }

        $this->transactions->transactional(function () use ($plan, $transition, $actions, $effects, $executionDisposition): void {
            foreach ($actions as $action) {
                if ($action instanceof FinalizeToolBatchDTO) {
                    $this->batches->applyPrepared($action, $transition);
                    if ($action->finalized) {
                        $this->executionOperations->retireUnstartedPermissions($action->runId, $action->turnNo, $action->stepId, $transition);
                    }
                    continue;
                }
                if ($action instanceof RegisterToolBatchDTO) {
                    $this->batches->registerPrepared($action, $transition);
                    continue;
                }
                if ($action instanceof EnqueueCommandDTO) {
                    $this->commands->enqueue($action->command);
                    continue;
                }
                if ($action instanceof MarkCommandAppliedDTO) {
                    $this->commands->markApplied($action->runId, $action->idempotencyKey);
                    continue;
                }
                if ($action instanceof RejectCommandDTO) {
                    $this->commands->markRejected($action->runId, $action->idempotencyKey, $action->reason);
                    continue;
                }
                if ($action instanceof RetireUnknownExecutionDTO) {
                    $this->executionOperations->retireUnknownExecution($action, $transition);
                    continue;
                }
                if ($action instanceof ConsumeExecutionUnknownDTO) {
                    $this->executionOperations->consumeUnknownNotice($action, $transition);
                    continue;
                }
                throw new \RuntimeException('Unsupported local metadata action '.$action::class.'.');
            }

            foreach ($this->admittedEffects($actions, $effects) as $effect) {
                if (!ExecutionOperationMapper::supports($effect)) {
                    throw new \RuntimeException('Local metadata arming requires a gated execution effect.');
                }
                $this->executionOperations->arm($effect, $transition);
            }

            if (null !== $executionDisposition) {
                $this->executionOperations->applyDisposition($executionDisposition, $transition);
            }
            $this->sourceAcceptance->publish($transition);
            $this->publication->persistControlObligations($plan->runId, $plan->controlActions);
        });
    }

    /**
     * @param list<object>                  $actions
     * @param list<AbstractAgentBusMessage> $effects
     *
     * @return list<AbstractAgentBusMessage>
     */
    private function preparationCandidates(array $actions, array $effects): array
    {
        $candidates = [];
        foreach ($effects as $effect) {
            if (ExecutionOperationMapper::supports($effect)) {
                $candidates[] = $effect;
            }
        }
        foreach ($actions as $action) {
            if (!$action instanceof RegisterToolBatchDTO) {
                continue;
            }
            foreach ($action->effects as $effect) {
                if ($effect instanceof ExecuteToolCall) {
                    $candidates[] = $effect;
                }
            }
        }
        foreach ($actions as $action) {
            if (!$action instanceof FinalizeToolBatchDTO || null === $action->revisedCallId || null === $action->answer) {
                continue;
            }
            $existing = $this->batches->load($action->runId, $action->turnNo, $action->stepId)?->calls[$action->revisedCallId] ?? null;
            if (!$existing instanceof ExecuteToolCall) {
                throw new \RuntimeException('Prepared human-answer revision has no stored invocation.');
            }
            $candidates[] = $existing->withAuthorizedHumanAnswer($action->answer);
        }

        return $candidates;
    }

    /**
     * @param list<object>                  $actions
     * @param list<AbstractAgentBusMessage> $effects
     *
     * @return list<AbstractAgentBusMessage>
     */
    private function admittedEffects(array $actions, array $effects): array
    {
        $admitted = [];
        foreach ($effects as $effect) {
            if ($effect instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($effect) && !$effect instanceof ExecuteToolCall) {
                $admitted[] = $effect;
            }
        }

        $batchKeys = [];
        foreach ($actions as $action) {
            if ($action instanceof RegisterToolBatchDTO || $action instanceof FinalizeToolBatchDTO) {
                $batchKeys[$action->runId.'|'.$action->turnNo.'|'.$action->stepId] = [$action->runId, $action->turnNo, $action->stepId];
            }
        }
        foreach ($effects as $effect) {
            if ($effect instanceof ExecuteToolCall) {
                $batchKeys[$effect->runId().'|'.$effect->turnNo().'|'.$effect->stepId()] = [$effect->runId(), $effect->turnNo(), $effect->stepId()];
            }
        }

        $admittedToolKeys = [];
        foreach ($batchKeys as [$runId, $turnNo, $stepId]) {
            foreach ($this->batches->admittedCalls($runId, $turnNo, $stepId) as $call) {
                $key = $call->idempotencyKey();
                if (isset($admittedToolKeys[$key])) {
                    continue;
                }
                $admittedToolKeys[$key] = true;
                $admitted[] = $call;
            }
        }

        $unique = [];
        $seen = [];
        foreach ($admitted as $effect) {
            $key = $effect->idempotencyKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $effect;
        }

        return $unique;
    }
}
