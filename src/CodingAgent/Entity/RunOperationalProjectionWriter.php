<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Ineersa\AgentCore\Contract\RunOperationalProjectionWriterInterface;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;

/** Maps active full state to the bounded, payload-free operational projection. */
final readonly class RunOperationalProjectionWriter implements RunOperationalProjectionWriterInterface
{
    public function __construct(private RunOperationalProjectionRepository $repository)
    {
    }

    public function replace(string $ownerSessionId, RunState $state): void
    {
        $this->repository->replaceStateAndHumanInputs(
            new RunOperationalProjectionDTO(
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
            ),
            $this->humanInputs($state->pendingHumanInputRequests),
        );
    }

    /**
     * @param list<PendingHumanInputRequestDTO> $requests
     *
     * @return list<RunOperationalHumanInputDTO>
     */
    private function humanInputs(array $requests): array
    {
        $rows = [];
        foreach ($requests as $index => $request) {
            $toolCallId = null;
            if (HumanInputContinuationKindEnum::ToolCall === $request->continuationKind
                && isset($request->continuationRef['tool_call_id'])
                && \is_string($request->continuationRef['tool_call_id'])
                && '' !== trim($request->continuationRef['tool_call_id'])) {
                $toolCallId = $request->continuationRef['tool_call_id'];
            }
            $rows[] = new RunOperationalHumanInputDTO(
                $request->questionId,
                $index,
                $request->continuationKind->value,
                $toolCallId,
                'waiting',
            );
        }

        return $rows;
    }
}
