<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\RunControlTransitionMessageInterface;

/** Owner-only reconciliation. Unsupported execution stays recovery-required. */
final readonly class PendingTransitionRecovery
{
    public function __construct(private PreparedTransitionEventStoreInterface $store, private ToolExecutionAuthorizationInterface $authorization, private StepDispatcher $dispatcher, private ActiveRunContextInterface $registry, private ExecutionOperationStoreInterface $executionOperations)
    {
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
        // Validate the entire recovery plan before applying any coordination.
        foreach ($effects as $effect) {
            $this->requireGated($effect);
        }
        foreach ($actions as $action) {
            if ($action instanceof DispatchCoordinationMessageDTO) {
                $this->requireGated($action->message);
                if (ExecutionOperationMapper::supports($action->message)) {
                    throw new \RuntimeException('Execution authorization requires a direct pending effect.');
                }
            } elseif ($action instanceof RegisterToolBatchDTO) {
                foreach ($action->effects as $effect) {
                    $this->requireGated($effect);
                    if (ExecutionOperationMapper::supports($effect)) {
                        throw new \RuntimeException('Execution authorization requires a direct pending effect.');
                    }
                }
            } elseif (!$action instanceof MarkCommandAppliedDTO && !$action instanceof \Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO) {
                throw new \RuntimeException('Owner transition requires coordination recovery for unsupported action.');
            }
        }
        $disposition = $work['result_disposition'] ?? null;
        if (null !== $disposition && !$disposition instanceof ToolResultDispositionDTO) {
            throw new \RuntimeException('Invalid pending result disposition.');
        }
        if (null !== $disposition) {
            $this->authorization->validateDisposition($disposition, $pending);
        }
        $executionDisposition = $work['execution_disposition'] ?? null;
        if (null !== $executionDisposition && !$executionDisposition instanceof ExecutionResultDispositionDTO) {
            throw new \RuntimeException('Invalid pending execution disposition.');
        }
        if (null !== $executionDisposition) {
            $this->executionOperations->validateDisposition($executionDisposition, $pending);
        }
        $stamps = [];
        foreach ($effects as $index => $effect) {
            if ($effect instanceof ExecuteToolCall) {
                $this->authorization->arm($effect);
            } elseif ($effect instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($effect)) {
                $authorization = $this->executionOperations->arm($effect, $pending);
                $reference = $this->executionOperations->requestReference($effect, $authorization);
                $effects[$index] = $reference;
                $stamps[spl_object_id($reference)] = $authorization;
            }
        }
        $this->dispatcher->dispatchEffects($effects, $stamps);
        $this->dispatcher->dispatchCoordinationActions($actions);
        if (null !== $disposition) {
            $this->authorization->applyDisposition($disposition, $pending);
        }
        if (null !== $executionDisposition) {
            $this->executionOperations->applyDisposition($executionDisposition, $pending);
        }
        $this->store->finalizeVerifiedTransition($runId, $pending->identity);
        // Cold replay must include the newly published suffix. A warm owner must
        // not continue using its predecessor after recovered physical append.
        $this->registry->release($runId);
    }

    private function requireGated(object $effect): void
    {
        if (!$effect instanceof ExecuteToolCall && !$effect instanceof RunControlTransitionMessageInterface && !ExecutionOperationMapper::supports($effect)) {
            throw new \RuntimeException('Owner transition requires coordination recovery for ungated execution.');
        }
    }
}
