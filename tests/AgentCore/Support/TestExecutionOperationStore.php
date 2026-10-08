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
    /** @var array<string, array{0: DurableExecutionResult, 1: AbstractAgentBusMessage}> */
    private array $deferredResults = [];

    /** @var array<string, array{0: AbstractAgentBusMessage, 1: ExecutionAuthorizationStamp}> */
    private array $armed = [];

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
        $stamp = new ExecutionAuthorizationStamp(hash('sha256', $transition->identity.'|'.$request->idempotencyKey()), hash('sha256', $this->encode($request)));
        $this->armed[$stamp->effectId] = [$request, $stamp];

        return $stamp;
    }

    public function arm(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
    {
        $stamp = $this->prepare($request, $transition);

        return $stamp;
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
        if (!$result instanceof \Ineersa\AgentCore\Domain\Message\ToolCallResult) {
            throw new \RuntimeException('Deferred completion requires a tool-call result envelope.');
        }
        $existing = $this->deferredResults[$deferredId] ?? null;
        if (null !== $existing) {
            if (serialize($existing[1]) !== serialize($result)) {
                throw new \RuntimeException('Conflicting durable execution result.');
            }

            return $existing[0];
        }

        $reference = new DurableExecutionResult(
            $result->runId(),
            $result->turnNo(),
            $result->stepId(),
            $result->attempt(),
            $result->idempotencyKey(),
            hash('sha256', $deferredId.'|'.$result->idempotencyKey()),
            'claim-'.$deferredId,
            hash('sha256', serialize($result)),
            \strlen(serialize($result)),
            $result::class,
        );
        $this->deferredResults[$deferredId] = [$reference, $result];

        return $reference;
    }

    public function resolveResult(DurableExecutionResult $reference): AbstractAgentBusMessage
    {
        foreach ($this->deferredResults as [$stored, $result]) {
            if ($stored->effectId === $reference->effectId
                && $stored->sha256 === $reference->sha256
                && $stored->bytes === $reference->bytes) {
                return $result;
            }
        }

        throw new \LogicException('Dispatch-only fixture cannot resolve unknown deferred results.');
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

    public function pendingDeliveriesForRun(string $runId): array
    {
        $pending = [];
        foreach ($this->armed as $effectId => [$request, $stamp]) {
            if ($request->runId() !== $runId) {
                continue;
            }
            $pending[$effectId] = new Envelope($this->requestReference($request, $stamp), [$stamp]);
        }

        return $pending;
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
