<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Projection;

use Ineersa\AgentCore\Contract\RunOperationalProjectionWriterInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInput;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Ineersa\CodingAgent\Entity\RunOperationalToolCall;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;

/** Maps active full state to the bounded, payload-free operational entity graph. */
final readonly class RunOperationalProjectionWriter implements RunOperationalProjectionWriterInterface
{
    public function __construct(private RunOperationalProjectionRepository $repository)
    {
    }

    public function replace(string $ownerSessionId, RunState $state): void
    {
        $projection = new RunOperationalState(
            $state->runId,
            $ownerSessionId,
            $state->status,
            $state->turnNo,
            $state->activeStepId,
            $state->currentOperation,
            $state->lastAppliedAdvanceKey,
            $state->lastAppliedCompactionKey,
            $state->retryableFailure,
            $state->retryAttempts,
            $state->lastSeq,
            $state->version,
        );

        foreach ($state->currentToolCalls as $toolCall) {
            $projection->addToolCall(new RunOperationalToolCall(
                $projection,
                $toolCall->batchId,
                $toolCall->toolCallId,
                $toolCall->orderIndex,
                $toolCall->status,
                $toolCall->attempt,
            ));
        }

        foreach ($state->pendingHumanInputRequests as $index => $request) {
            $projection->addHumanInput(new RunOperationalHumanInput(
                $projection,
                $request->questionId,
                $index,
                $request->continuationKind,
                $request->toolCallId(),
            ));
        }

        $this->repository->replace($projection);
    }
}
