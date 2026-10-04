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

    public function resultForClaim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult
    {
        throw new \LogicException('Dispatch-only fixture cannot acknowledge execution.');
    }

    public function saveResult(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, AbstractAgentBusMessage $result): DurableExecutionResult
    {
        throw new \LogicException('Dispatch-only fixture cannot persist execution results.');
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

    private function encode(AbstractAgentBusMessage $request): string
    {
        return (new PhpSerializer())->encode(new Envelope($request))['body'];
    }
}
