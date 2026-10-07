<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/** Dispatch-only unit fixtures. Execution and durability proofs require the configured store. */
final class TestExecutionOperationStore implements ExecutionOperationStoreInterface
{
    public function unknownExecutionsForRepair(string $runId): array
    {
        return [];
    }

    public function assertUnknownRepairable(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): void
    {
        throw new \LogicException('Dispatch-only fixture cannot validate unknown repair.');
    }

    public function matchesCurrentAuthorizedToolInvocation(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice, \Ineersa\AgentCore\Domain\Message\ExecuteToolCall $authorizedCall): bool
    {
        return false;
    }

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, VerifiedTransitionDTO $transition): void
    {
        throw new \LogicException('Dispatch-only fixture cannot retire unknown execution.');
    }

    public function repairDelivery(string $runId, \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO $operation, string $requestType): ?Envelope
    {
        return null;
    }

    public function assertRequestCapacity(AbstractAgentBusMessage $request): void
    {
        $this->encode($request);
    }

    public function prepare(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
    {
        return $this->arm($request, $transition);
    }

    public function arm(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
    {
        return new ExecutionAuthorizationStamp(hash('sha256', $transition->identity.'|'.$request->idempotencyKey()), hash('sha256', $this->encode($request)));
    }

    public function requestReference(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization): ExecutionRequest
    {
        return new ExecutionRequest($request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey(), $authorization->effectId, $request::class, $authorization->requestHash, \strlen($this->encode($request)));
    }

    public function claim(ExecutionRequest $request, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null
    {
        throw new \LogicException('Dispatch-only fixture cannot prove execution claims.');
    }

    public function resolveRequest(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): AbstractAgentBusMessage
    {
        throw new \LogicException('Dispatch-only fixture cannot resolve execution inputs.');
    }

    public function peekRequest(ExecutionRequest $reference): AbstractAgentBusMessage
    {
        throw new \LogicException('Dispatch-only fixture cannot peek execution inputs.');
    }

    public function resultForClaim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult
    {
        throw new \LogicException('Dispatch-only fixture cannot acknowledge execution.');
    }

    public function saveResult(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, AbstractAgentBusMessage $result): DurableExecutionResult
    {
        throw new \LogicException('Dispatch-only fixture cannot persist execution results.');
    }

    public function transferToDeferred(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim, string $deferredId): void
    {
        throw new \LogicException('Dispatch-only fixture cannot transfer deferred ownership.');
    }

    public function saveDeferredResult(string $deferredId, AbstractAgentBusMessage $result): DurableExecutionResult
    {
        throw new \LogicException('Dispatch-only fixture cannot persist deferred results.');
    }

    public function resolveResult(DurableExecutionResult $reference): AbstractAgentBusMessage
    {
        throw new \LogicException('Dispatch-only fixture cannot resolve execution results.');
    }

    public function isDisposed(DurableExecutionResult $reference): bool
    {
        throw new \LogicException('Dispatch-only fixture cannot prove result dispositions.');
    }

    public function validateDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void
    {
        throw new \LogicException('Dispatch-only fixture cannot validate result dispositions.');
    }

    public function applyDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void
    {
        throw new \LogicException('Dispatch-only fixture cannot apply result dispositions.');
    }

    public function reclaimDisposedPayloads(string $ownerSessionId, string $afterEffectId): string
    {
        return '';
    }

    public function retireUnstartedPermissions(string $runId, int $turnNo, string $stepId, VerifiedTransitionDTO $transition): void
    {
        // Dispatch-only fixture has no durable Prepared/Armed permissions.
    }

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): bool
    {
        throw new \LogicException('Dispatch-only fixture has no unknown execution receipts.');
    }

    public function consumeUnknownNotice(\Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO $action, VerifiedTransitionDTO $transition): void
    {
        throw new \LogicException('Dispatch-only fixture cannot consume unknown notices.');
    }

    public function assertNoUnknownExecution(string $runId): void
    {
        // This fixture arms only; it has no Running or OutcomeUnknown records.
    }

    private function encode(AbstractAgentBusMessage $request): string
    {
        return (new PhpSerializer())->encode(new Envelope($request))['body'];
    }
}
