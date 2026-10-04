<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;

/** Narrow authority for execution effects not owned by the tool batch. */
interface ExecutionOperationStoreInterface
{
    public function arm(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp;

    public function claim(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null;

    public function saveResult(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, AbstractAgentBusMessage $result): DurableExecutionResult;

    public function resolveResult(DurableExecutionResult $reference): AbstractAgentBusMessage;

    public function isDisposed(DurableExecutionResult $reference): bool;

    public function validateDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;

    public function applyDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;
}
