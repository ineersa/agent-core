<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Runtime-owned origin identity for one retained tool_completion message. */
final readonly class RetainedToolCompletionOrigin
{
    public function __construct(
        public string $controllerRunId,
        public string $sourceRunId,
        public int $originTurnNo,
        public string $originStepId,
        public string $toolCallId,
        public string $invocationId,
        public string $executionOwnerKind,
        public ?RetainedToolCompletionSourceEventReference $acceptedSourceEventReference = null,
    ) {
        foreach ([
            'controllerRunId' => $this->controllerRunId,
            'sourceRunId' => $this->sourceRunId,
            'originStepId' => $this->originStepId,
            'toolCallId' => $this->toolCallId,
            'invocationId' => $this->invocationId,
            'executionOwnerKind' => $this->executionOwnerKind,
        ] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Retained origin %s must be nonblank.', $field));
            }
        }
        if ($this->originTurnNo < 0) {
            throw new \InvalidArgumentException('Retained origin turn must be non-negative.');
        }
    }

    /**
     * @return array{
     *     controller_run_id: string,
     *     source_run_id: string,
     *     origin_turn_no: int,
     *     origin_step_id: string,
     *     tool_call_id: string,
     *     invocation_id: string,
     *     execution_owner_kind: string,
     *     accepted_source_event_reference?: array{run_id: string, seq: int, turn_no: int, type: string}
     * }
     */
    public function toArray(): array
    {
        $payload = [
            'controller_run_id' => $this->controllerRunId,
            'source_run_id' => $this->sourceRunId,
            'origin_turn_no' => $this->originTurnNo,
            'origin_step_id' => $this->originStepId,
            'tool_call_id' => $this->toolCallId,
            'invocation_id' => $this->invocationId,
            'execution_owner_kind' => $this->executionOwnerKind,
        ];
        if (null !== $this->acceptedSourceEventReference) {
            $payload['accepted_source_event_reference'] = $this->acceptedSourceEventReference->toArray();
        }

        return $payload;
    }

    public function withAcceptedSourceEventReference(RetainedToolCompletionSourceEventReference $reference): self
    {
        return new self(
            controllerRunId: $this->controllerRunId,
            sourceRunId: $this->sourceRunId,
            originTurnNo: $this->originTurnNo,
            originStepId: $this->originStepId,
            toolCallId: $this->toolCallId,
            invocationId: $this->invocationId,
            executionOwnerKind: $this->executionOwnerKind,
            acceptedSourceEventReference: $reference,
        );
    }
}
