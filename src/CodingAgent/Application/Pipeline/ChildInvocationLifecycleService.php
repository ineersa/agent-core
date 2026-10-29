<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

final class ChildInvocationLifecycleService
{
    private ?BashRegistrationClient $owner = null;

    public function __construct(private readonly DeferredSubagentBatchRepository $batches)
    {
    }

    public function useOwner(BashRegistrationClient $owner): void
    {
        $this->owner = $owner;
    }

    public function reserveBatch(
        string $lifecycleId,
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        \DateTimeImmutable $deadlineAt,
        DeferredSubagentBatchChildIntentDTO $childIntent,
        ?string $parentModel = null,
    ): DeferredSubagentBatchProjectionDTO {
        if (null !== $this->owner) {
            return $this->owner->reserveChild($lifecycleId, $parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $deadlineAt, $childIntent, $parentModel);
        }

        return $this->batches->reserveBatch($lifecycleId, $parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $deadlineAt, $childIntent, $parentModel);
    }

    public function applyLaunchFailurePreparation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        if (null !== $this->owner) {
            $this->owner->recordChildLaunchFailure($parentRunId, $parentToolCallId, $lifecycleId);

            return;
        }
        $this->requireInvocation($parentRunId, $parentToolCallId, $lifecycleId);
        $this->batches->applyLaunchFailurePreparation($parentRunId, $parentToolCallId, $lifecycleId);
    }

    public function applyLaunchSuccessState(string $parentRunId, string $parentToolCallId, string $lifecycleId, \DateTimeImmutable $startedAt): void
    {
        if (null !== $this->owner) {
            $this->owner->recordChildLaunchSuccess($parentRunId, $parentToolCallId, $lifecycleId, $startedAt);

            return;
        }
        $this->requireInvocation($parentRunId, $parentToolCallId, $lifecycleId);
        $this->batches->applyLaunchSuccessState($parentRunId, $parentToolCallId, $lifecycleId, $startedAt);
    }

    private function requireInvocation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $batch = $this->batches->findByLifecycleId($lifecycleId);
        if (null === $batch || $batch->parentRunId !== $parentRunId || $batch->parentToolCallId !== $parentToolCallId) {
            throw new ToolCallException('Child launch metadata does not match its reserved invocation.', retryable: false);
        }
    }
}
