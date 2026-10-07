<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;

/** Narrow authority for execution effects not owned by the tool batch. */
interface ExecutionOperationStoreInterface
{
    /** @return list<\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown> */
    public function unknownExecutionsForRepair(string $runId): array;

    public function assertUnknownRepairable(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): void;

    /**
     * True when the notice names the currently authorized ordinary-tool invocation
     * for its logical tool-call id. Uses scheduling evidence and ledger metadata.
     */
    public function matchesCurrentAuthorizedToolInvocation(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice, \Ineersa\AgentCore\Domain\Message\ExecuteToolCall $authorizedCall): bool;

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, VerifiedTransitionDTO $transition): void;

    public function repairDelivery(string $runId, \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO $operation, string $requestType): ?\Symfony\Component\Messenger\Envelope;

    /**
     * Reject oversized immutable requests before canonical append or authorization.
     * Uses the same encoding and storage bound as arming; leaves archive and rows unchanged.
     */
    public function assertRequestCapacity(AbstractAgentBusMessage $request): void;

    /**
     * Seal the immutable request and retain a Prepared ledger row without making it
     * claimable or publishable. Used for queued batch membership before admission.
     */
    public function prepare(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp;

    public function arm(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp;

    public function requestReference(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization): ExecutionRequest;

    public function claim(ExecutionRequest $request, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null;

    public function resolveRequest(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): AbstractAgentBusMessage;

    /** Read a sealed invocation without claiming it. Used for outbound routing only. */
    public function peekRequest(ExecutionRequest $reference): AbstractAgentBusMessage;

    public function resultForClaim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult;

    public function saveResult(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, AbstractAgentBusMessage $result): DurableExecutionResult;

    /** Transfer a running ordinary-tool claim to deferred ownership without filling its result. */
    public function transferToDeferred(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim, string $deferredId): void;

    /** Attach a deferred completion to the exact deferred invocation and return its durable reference. */
    public function saveDeferredResult(string $deferredId, AbstractAgentBusMessage $result): DurableExecutionResult;

    public function resolveResult(DurableExecutionResult $reference): AbstractAgentBusMessage;

    public function isDisposed(DurableExecutionResult $reference): bool;

    public function validateDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;

    public function applyDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;

    /**
     * Removes immutable request/result files for disposed operations after
     * coordination finalization. Keeps scalar authorization rows. One bounded page.
     *
     * @return string next effect-id cursor, or empty when the page is exhausted
     */
    public function reclaimDisposedPayloads(string $ownerSessionId, string $afterEffectId): string;

    /**
     * Pending broker deliveries for one run from existing ledger rows.
     *
     * @return array<string, \Symfony\Component\Messenger\Envelope|null>
     */
    public function pendingDeliveriesForRun(string $runId): array;

    /**
     * Retire Prepared/Armed permissions for a cancelled batch without touching
     * Running/Deferred/ResultReady evidence.
     */
    public function retireUnstartedPermissions(string $runId, int $turnNo, string $stepId, VerifiedTransitionDTO $transition): void;

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): bool;

    public function consumeUnknownNotice(\Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO $action, VerifiedTransitionDTO $transition): void;

    public function assertNoUnknownExecution(string $runId): void;
}
